<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Application;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Intake;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ApplicationManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Group('TC-ADM-001-P')]
    #[Group('TC-ADM-002-P')]
    public function test_registrar_can_create_submit_review_and_admit_an_application(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $campus = Campus::factory()->create();
        $course = Course::factory()->create();
        $intake = Intake::factory()->create([
            'campus_id' => $campus->id,
            'course_id' => $course->id,
            'capacity' => 5,
            'applications_open_at' => now()->subDay(),
            'applications_close_at' => now()->addMonth(),
        ]);

        $response = $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/applications', [
                'intake_id' => $intake->id,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada.applicant@example.test',
                'submit' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Application::STATUS_SUBMITTED)
            ->assertJsonPath('duplicate_applicant_warning', false);

        $application = Application::query()->findOrFail($response->json('data.id'));
        $this->assertStringStartsWith('APP-', $application->application_number);
        $this->assertDatabaseHas('application_status_history', [
            'application_id' => $application->id,
            'to_status' => Application::STATUS_SUBMITTED,
        ]);

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/applications/{$application->id}/review", [
                'notes' => 'Eligibility checked',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Application::STATUS_UNDER_REVIEW);

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/applications/{$application->id}/decision", [
                'decision' => 'accept',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Application::STATUS_ACCEPTED);

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/applications/{$application->id}/admit")
            ->assertOk()
            ->assertJsonPath('data.status', Application::STATUS_ADMITTED)
            ->assertJsonStructure(['data' => ['student_id', 'student_number', 'letter_number']]);

        $this->assertDatabaseHas('students', [
            'campus_id' => $campus->id,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'intake_id' => $intake->id,
            'course_id' => $course->id,
        ]);
        $this->assertDatabaseHas('admission_letters', [
            'application_id' => $application->id,
        ]);
    }

    #[Group('TC-ADM-004-N')]
    public function test_rejection_requires_a_reason_and_full_intake_cannot_be_accepted(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $intake = Intake::factory()->create([
            'capacity' => 1,
            'applications_open_at' => now()->subDay(),
            'applications_close_at' => now()->addMonth(),
        ]);
        $application = Application::factory()->create([
            'intake_id' => $intake->id,
            'campus_id' => $intake->campus_id,
            'status' => Application::STATUS_UNDER_REVIEW,
        ]);

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/applications/{$application->id}/decision", [
                'decision' => 'reject',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        Application::factory()->create([
            'intake_id' => $intake->id,
            'campus_id' => $intake->campus_id,
            'status' => Application::STATUS_ACCEPTED,
        ]);

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/applications/{$application->id}/decision", [
                'decision' => 'accept',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decision']);

        $this->assertNotSame(Application::STATUS_ACCEPTED, $application->fresh()->status);
    }

    public function test_public_admissions_is_disabled_by_default(): void
    {
        config(['app.admissions_public_enabled' => false]);
        $intake = Intake::factory()->create([
            'applications_open_at' => now()->subDay(),
            'applications_close_at' => now()->addMonth(),
        ]);

        $this->postJson('/api/v1/auth/admissions/applications', [
            'intake_id' => $intake->id,
            'first_name' => 'Public',
            'last_name' => 'Applicant',
            'email' => 'public@example.test',
        ])
            ->assertNotFound();
    }

    public function test_waitlist_and_withdrawal_work_in_controlled_state_transitions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $intake = Intake::factory()->create([
            'capacity' => 1,
            'applications_open_at' => now()->subDay(),
            'applications_close_at' => now()->addMonth(),
        ]);
        $waitlisted = Application::factory()->create([
            'intake_id' => $intake->id,
            'campus_id' => $intake->campus_id,
            'status' => Application::STATUS_UNDER_REVIEW,
        ]);

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/applications/{$waitlisted->id}/decision", [
                'decision' => 'waitlist',
                'waitlist_expires_at' => now()->addDays(14)->toDateTimeString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Application::STATUS_WAITLISTED)
            ->assertJsonPath('data.waitlist_position', 1);

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/applications/{$waitlisted->id}/withdraw", [
                'reason' => 'Applicant changed plans',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Application::STATUS_WITHDRAWN);

        $this->assertNotNull($waitlisted->fresh()->withdrawn_at);
    }

    public function test_public_admissions_can_be_enabled_for_configured_applications(): void
    {
        config(['app.admissions_public_enabled' => true]);
        $course = Course::factory()->create();
        $intake = Intake::factory()->create([
            'course_id' => $course->id,
            'applications_open_at' => now()->subDay(),
            'applications_close_at' => now()->addMonth(),
        ]);

        $this->postJson('/api/v1/auth/admissions/applications', [
            'intake_id' => $intake->id,
            'first_name' => 'Public',
            'last_name' => 'Applicant',
            'email' => 'enabled-public@example.test',
            'submit' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', Application::STATUS_SUBMITTED);

        $this->assertDatabaseHas('applications', [
            'status' => Application::STATUS_SUBMITTED,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
