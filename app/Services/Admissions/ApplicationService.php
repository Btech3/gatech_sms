<?php

namespace App\Services\Admissions;

use App\Models\AdmissionLetter;
use App\Models\Applicant;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Enrollment;
use App\Models\Intake;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function create(
        User $actor,
        Intake $intake,
        array $attributes,
        Request $request,
    ): Application {
        $courseId = $attributes['course_id'] ?? $intake->course_id;
        if ($courseId === null) {
            throw ValidationException::withMessages([
                'course_id' => ['A course is required for admission applications.'],
            ]);
        }

        if (! $intake->is_active
            || now()->lt($intake->applications_open_at)
            || now()->gt($intake->applications_close_at)) {
            throw ValidationException::withMessages([
                'intake_id' => ['The selected intake is not accepting applications at this time.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $intake, $attributes, $request): Application {
            $existing = Applicant::query()
                ->where('email', $attributes['email'])
                ->withCount('applications')
                ->first();

            if ($existing !== null && $existing->applications_count > 0) {
                // Duplicate application warnings are returned by the API, not silently merged.
                $this->auditLogger->record(
                    $actor,
                    'Admissions',
                    'duplicate_application_warning',
                    'applicant',
                    $existing->id,
                    afterValues: ['email' => $existing->email, 'intake_id' => $intake->id],
                    request: $request,
                );
            }

            $applicant = Applicant::query()->firstOrCreate(
                ['email' => $attributes['email']],
                [
                    'first_name' => $attributes['first_name'],
                    'last_name' => $attributes['last_name'],
                    'phone' => $attributes['phone'] ?? null,
                    'date_of_birth' => $attributes['date_of_birth'] ?? null,
                    'guardian_name' => $attributes['guardian_name'] ?? null,
                    'guardian_phone' => $attributes['guardian_phone'] ?? null,
                    'campus_id' => $intake->campus_id,
                ],
            );

            $application = Application::query()->create([
                'application_number' => $this->nextApplicationNumber(),
                'applicant_id' => $applicant->id,
                'intake_id' => $intake->id,
                'campus_id' => $intake->campus_id,
                'course_id' => $attributes['course_id'] ?? $intake->course_id,
                'status' => ($attributes['submit'] ?? false)
                    ? Application::STATUS_SUBMITTED
                    : Application::STATUS_DRAFT,
                'submitted_at' => ($attributes['submit'] ?? false) ? now() : null,
            ]);

            $this->recordStatus(
                $application,
                null,
                $application->status,
                $actor,
                'Application created',
                $request,
            );

            $this->auditLogger->record(
                $actor,
                'Admissions',
                'application_created',
                'application',
                $application->id,
                afterValues: [
                    'application_number' => $application->application_number,
                    'status' => $application->status,
                    'intake_id' => $intake->id,
                ],
                request: $request,
            );

            return $application;
        });
    }

    public function changeStatus(
        Application $application,
        string $toStatus,
        User $actor,
        ?string $notes,
        Request $request,
    ): Application {
        return DB::transaction(function () use ($application, $toStatus, $actor, $notes, $request): Application {
            $application = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $application->status;
            $allowed = [
                Application::STATUS_DRAFT => [Application::STATUS_SUBMITTED, Application::STATUS_WITHDRAWN],
                Application::STATUS_SUBMITTED => [
                    Application::STATUS_UNDER_REVIEW,
                    Application::STATUS_WITHDRAWN,
                    Application::STATUS_REJECTED,
                ],
                Application::STATUS_UNDER_REVIEW => [
                    Application::STATUS_INFO_REQUESTED,
                    Application::STATUS_ACCEPTED,
                    Application::STATUS_REJECTED,
                    Application::STATUS_WAITLISTED,
                    Application::STATUS_WITHDRAWN,
                ],
                Application::STATUS_INFO_REQUESTED => [
                    Application::STATUS_UNDER_REVIEW,
                    Application::STATUS_WITHDRAWN,
                    Application::STATUS_REJECTED,
                ],
                Application::STATUS_WAITLISTED => [
                    Application::STATUS_ACCEPTED,
                    Application::STATUS_REJECTED,
                    Application::STATUS_WITHDRAWN,
                ],
                Application::STATUS_ACCEPTED => [Application::STATUS_ADMITTED, Application::STATUS_WITHDRAWN],
                Application::STATUS_REJECTED => [],
                Application::STATUS_WITHDRAWN => [],
                Application::STATUS_ADMITTED => [],
            ];

            if (! in_array($toStatus, $allowed[$fromStatus] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["A {$fromStatus} application cannot transition to {$toStatus}."],
                ]);
            }

            $application->forceFill([
                'status' => $toStatus,
                'reviewer_id' => $actor->id,
                'review_started_at' => $application->review_started_at ?? now(),
                'decision_at' => in_array($toStatus, [
                    Application::STATUS_ACCEPTED,
                    Application::STATUS_REJECTED,
                    Application::STATUS_WAITLISTED,
                ], true) ? now() : $application->decision_at,
            ])->save();

            $this->recordStatus($application, $fromStatus, $toStatus, $actor, $notes, $request);
            $this->auditLogger->record(
                $actor,
                'Admissions',
                'application_status_changed',
                'application',
                $application->id,
                beforeValues: ['status' => $fromStatus],
                afterValues: ['status' => $toStatus, 'reason' => $notes],
                request: $request,
            );

            return $application;
        });
    }

    public function admit(Application $application, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($application, $actor, $request): array {
            $application = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($application->status !== Application::STATUS_ACCEPTED) {
                throw ValidationException::withMessages([
                    'status' => ['Only accepted applications can be admitted.'],
                ]);
            }

            $student = Student::query()->firstOrCreate(
                ['applicant_id' => $application->applicant_id],
                [
                    'student_number' => $this->nextStudentNumber(),
                    'campus_id' => $application->campus_id,
                    'status' => 'active',
                ],
            );

            Enrollment::query()->firstOrCreate([
                'student_id' => $student->id,
                'course_id' => $application->course_id,
                'intake_id' => $application->intake_id,
            ], [
                'status' => 'active',
            ]);

            $letter = AdmissionLetter::query()->firstOrCreate(
                ['application_id' => $application->id],
                [
                    'student_id' => $student->id,
                    'letter_number' => $this->nextLetterNumber(),
                    'issued_at' => now(),
                ],
            );

            $this->changeStatus($application, Application::STATUS_ADMITTED, $actor, 'Admitted to student record', $request);
            $this->auditLogger->record(
                $actor,
                'Admissions',
                'admitted',
                'application',
                $application->id,
                afterValues: [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'letter_number' => $letter->letter_number,
                ],
                request: $request,
            );

            return [$student, $letter];
        });
    }

    private function recordStatus(
        Application $application,
        ?string $fromStatus,
        string $toStatus,
        User $actor,
        ?string $notes,
        Request $request,
    ): void {
        ApplicationStatusHistory::query()->create([
            'application_id' => $application->id,
            'changed_by' => $actor->exists ? $actor->id : null,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
        ]);
    }

    private function nextApplicationNumber(): string
    {
        $year = now()->format('Y');
        $sequence = (int) Application::query()->whereYear('created_at', $year)->count() + 1;

        return 'APP-'.$year.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function nextStudentNumber(): string
    {
        $year = now()->format('Y');
        $sequence = (int) Student::query()->whereYear('created_at', $year)->count() + 1;

        return 'STU-'.$year.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function nextLetterNumber(): string
    {
        $year = now()->format('Y');
        $sequence = (int) AdmissionLetter::query()->whereYear('created_at', $year)->count() + 1;

        return 'AL-'.$year.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
