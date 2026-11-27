<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DecideApplicationRequest;
use App\Http\Requests\Api\V1\Admin\ReviewApplicationRequest;
use App\Http\Requests\Api\V1\Admin\StoreApplicationRequest;
use App\Http\Requests\Api\V1\Admin\WithdrawApplicationRequest;
use App\Http\Resources\Api\V1\Admin\ApplicationResource;
use App\Models\Applicant;
use App\Models\Application;
use App\Models\Intake;
use App\Models\User;
use App\Services\Admissions\ApplicationService;
use App\Services\Authorization\CampusAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    public function index(Request $request, CampusAccess $campusAccess): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $query = Application::query()
            ->with('applicant:id,first_name,last_name,email,phone')
            ->orderByRaw("CASE status WHEN 'submitted' THEN 1 WHEN 'under_review' THEN 2 ELSE 3 END")
            ->latest('submitted_at');

        if (! $actor->isBootstrapAdministrator()) {
            $campusIds = $campusAccess->queryFor($actor)->select('campuses.id');
            $query->whereIn('campus_id', $campusIds);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('campus_id')) {
            $query->where('campus_id', $request->integer('campus_id'));
        }
        if ($request->filled('intake_id')) {
            $query->where('intake_id', $request->integer('intake_id'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($query) use ($search): void {
                $query->where('application_number', 'like', "%{$search}%")
                    ->orWhereHas('applicant', fn ($applicant) => $applicant
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return ApplicationResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(
        StoreApplicationRequest $request,
        ApplicationService $applications,
        CampusAccess $campusAccess,
    ): JsonResponse {
        $actor = $this->actor($request);
        $attributes = $request->validated();
        $intake = Intake::query()->lockForUpdate()->findOrFail($attributes['intake_id']);

        if ($intake->campus_id !== null) {
            $campusAccess->findFor($actor, $intake->campus_id);
        }

        $duplicate = Applicant::query()
            ->where('email', $attributes['email'])
            ->whereHas('applications')
            ->exists();
        $application = $applications->create($actor, $intake, $attributes, $request);

        return (new ApplicationResource($application->load('applicant')))
            ->additional(['duplicate_applicant_warning' => $duplicate])
            ->response()
            ->setStatusCode(201);
    }

    public function publicStore(
        StoreApplicationRequest $request,
        ApplicationService $applications,
    ): JsonResponse {
        abort_unless((bool) config('app.admissions_public_enabled', false), 404);
        $intake = Intake::query()->lockForUpdate()->findOrFail($request->integer('intake_id'));
        $actor = $this->publicActor();
        $attributes = $request->validated();
        $duplicate = Applicant::query()
            ->where('email', $attributes['email'])
            ->whereHas('applications')
            ->exists();
        $application = $applications->create($actor, $intake, $attributes, $request);

        return (new ApplicationResource($application->load('applicant')))
            ->additional(['duplicate_applicant_warning' => $duplicate])
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Application $application,
        CampusAccess $campusAccess,
    ): ApplicationResource {
        $actor = $this->actor($request);
        $this->ensureApplicationAccess($actor, $application, $campusAccess);

        return new ApplicationResource($application->load(['applicant', 'statusHistory']));
    }

    public function review(
        ReviewApplicationRequest $request,
        Application $application,
        ApplicationService $applications,
        CampusAccess $campusAccess,
    ): ApplicationResource {
        $actor = $this->actor($request);
        $this->ensureApplicationAccess($actor, $application, $campusAccess);
        $attributes = $request->validated();
        $toStatus = ($attributes['request_information'] ?? false)
            ? Application::STATUS_INFO_REQUESTED
            : Application::STATUS_UNDER_REVIEW;
        $application = $applications->changeStatus(
            $application,
            $toStatus,
            $actor,
            $attributes['notes'] ?? null,
            $request,
        );

        return new ApplicationResource($application->load(['applicant', 'statusHistory']));
    }

    public function decide(
        DecideApplicationRequest $request,
        Application $application,
        ApplicationService $applications,
        CampusAccess $campusAccess,
    ): ApplicationResource {
        $actor = $this->actor($request);
        $this->ensureApplicationAccess($actor, $application, $campusAccess);
        $decision = $request->validated('decision');

        if ($decision === 'accept') {
            $occupied = Application::query()
                ->where('intake_id', $application->intake_id)
                ->whereIn('status', [Application::STATUS_ACCEPTED, Application::STATUS_ADMITTED])
                ->lockForUpdate()
                ->count();
            if ($occupied >= (int) $application->intake->capacity) {
                throw ValidationException::withMessages([
                    'decision' => ['The intake is full. Accept would exceed its configured capacity.'],
                ]);
            }
            $toStatus = Application::STATUS_ACCEPTED;
        } elseif ($decision === 'reject') {
            $toStatus = Application::STATUS_REJECTED;
        } else {
            $position = (int) Application::query()
                ->where('intake_id', $application->intake_id)
                ->where('status', Application::STATUS_WAITLISTED)
                ->count() + 1;
            $application->forceFill([
                'waitlist_position' => $position,
                'waitlist_expires_at' => $request->validated('waitlist_expires_at'),
            ])->save();
            $toStatus = Application::STATUS_WAITLISTED;
        }

        $application = $applications->changeStatus(
            $application,
            $toStatus,
            $actor,
            $request->validated('reason'),
            $request,
        );

        if ($decision === 'reject') {
            $application->forceFill([
                'decision_reason' => $request->validated('reason'),
            ])->save();
        }

        return new ApplicationResource($application->load('applicant'));
    }

    public function admit(
        Request $request,
        Application $application,
        ApplicationService $applications,
        CampusAccess $campusAccess,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->ensureApplicationAccess($actor, $application, $campusAccess);
        [$student, $letter] = $applications->admit($application, $actor, $request);

        return response()->json([
            'data' => [
                'application_id' => $application->id,
                'student_id' => $student->id,
                'student_number' => $student->student_number,
                'letter_number' => $letter->letter_number,
                'status' => Application::STATUS_ADMITTED,
            ],
            'message' => 'The accepted application was admitted and an admission letter was issued.',
        ]);
    }

    public function withdraw(
        WithdrawApplicationRequest $request,
        Application $application,
        ApplicationService $applications,
        CampusAccess $campusAccess,
    ): ApplicationResource {
        $actor = $this->actor($request);
        $this->ensureApplicationAccess($actor, $application, $campusAccess);
        $application = $applications->changeStatus(
            $application,
            Application::STATUS_WITHDRAWN,
            $actor,
            $request->validated('reason'),
            $request,
        );
        $application->forceFill([
            'withdrawn_at' => now(),
            'decision_reason' => $request->validated('reason'),
        ])->save();

        return new ApplicationResource($application->load('applicant'));
    }

    public function waitlistPromote(
        Request $request,
        Application $application,
        ApplicationService $applications,
        CampusAccess $campusAccess,
    ): ApplicationResource {
        $actor = $this->actor($request);
        $this->ensureApplicationAccess($actor, $application, $campusAccess);

        $oldest = Application::query()
            ->where('intake_id', $application->intake_id)
            ->where('status', Application::STATUS_WAITLISTED)
            ->orderBy('waitlist_position')
            ->lockForUpdate()
            ->first();
        abort_if($oldest === null || $oldest->id !== $application->id, 409, 'Only the first waitlisted application can be promoted.');

        $occupied = Application::query()
            ->where('intake_id', $application->intake_id)
            ->whereIn('status', [Application::STATUS_ACCEPTED, Application::STATUS_ADMITTED])
            ->count();
        abort_if($occupied >= (int) $application->intake->capacity, 409, 'The intake is still full.');

        $application = $applications->changeStatus(
            $application,
            Application::STATUS_ACCEPTED,
            $actor,
            'Waitlist promoted',
            $request,
        );
        $application->forceFill([
            'waitlist_position' => null,
            'waitlist_expires_at' => null,
        ])->save();

        return new ApplicationResource($application);
    }

    private function ensureApplicationAccess(
        User $actor,
        Application $application,
        CampusAccess $campusAccess,
    ): void {
        if ($actor->isBootstrapAdministrator()) {
            return;
        }

        if ($application->campus_id !== null) {
            $campusAccess->findFor($actor, $application->campus_id);
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function publicActor(): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Public admissions',
            'email' => 'public-admissions@localhost',
        ]);

        return $user;
    }
}
