<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AcademicSession;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AcademicSetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Group('TC-ADN-006-P')]
    public function test_admin_can_create_sessions_and_an_intake(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $department = Department::factory()->create();
        $course = Course::factory()->for($department)->create();
        $campus = Campus::factory()->create();

        $firstSession = $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/academic-sessions', [
                'name' => '2027/2028',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-12-31',
                'is_current' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_current', true);

        $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/academic-sessions', [
                'name' => '2028/2029',
                'starts_on' => '2028-01-01',
                'ends_on' => '2028-12-31',
                'is_current' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_current', true);

        $this->assertDatabaseHas('academic_sessions', [
            'id' => $firstSession->json('data.id'),
            'is_current' => false,
        ]);

        $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/intakes', [
                'academic_session_id' => $firstSession->json('data.id'),
                'course_id' => $course->id,
                'campus_id' => $campus->id,
                'name' => 'January 2027',
                'starts_on' => '2027-01-15',
                'applications_open_at' => '2026-10-01 00:00:00',
                'applications_close_at' => '2026-12-15 23:59:59',
                'capacity' => 80,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'January 2027')
            ->assertJsonPath('data.capacity', 80);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'intake',
            'action' => 'created',
        ]);
    }

    #[Group('TC-ADN-006-N')]
    public function test_overlapping_academic_sessions_are_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        AcademicSession::factory()->create([
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ]);

        $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/academic-sessions', [
                'name' => 'Overlapping Session',
                'starts_on' => '2027-12-01',
                'ends_on' => '2028-01-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('academic_sessions', 1);
    }

    #[Group('TC-ADN-006-P')]
    public function test_admin_can_add_an_academic_holiday(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $session = AcademicSession::factory()->create();

        $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/academic-holidays', [
                'academic_session_id' => $session->id,
                'name' => 'Founders Day',
                'holiday_date' => '2027-03-15',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Founders Day')
            ->assertJsonPath('data.holiday_date', '2027-03-15');

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'academic_holiday',
            'action' => 'created',
        ]);
    }

    #[Group('TC-ADN-007-P')]
    public function test_admin_can_create_a_department_for_courses_and_users(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $response = $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/departments', [
                'name' => 'ICT',
                'description' => 'Information and communications technology',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ICT');

        Course::factory()->create(['department_id' => $response->json('data.id')]);

        $this->actingAs($administrator, 'web')
            ->getJson('/api/v1/admin/departments')
            ->assertOk()
            ->assertJsonPath('data.0.courses_count', 1);
    }

    #[Group('TC-ADN-007-N')]
    public function test_department_deletion_is_blocked_while_a_course_uses_it(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $department = Department::factory()->create(['name' => 'ICT']);
        Course::factory()->create(['department_id' => $department->id]);

        $this->actingAs($administrator, 'web')
            ->deleteJson("/api/v1/admin/departments/{$department->id}")
            ->assertConflict()
            ->assertJsonPath('usage.courses', 1);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    #[Group('TC-ADN-007-P')]
    public function test_admin_can_delete_an_unused_department(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $department = Department::factory()->create(['name' => 'Unused']);

        $this->actingAs($administrator, 'web')
            ->deleteJson("/api/v1/admin/departments/{$department->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'department',
            'action' => 'deleted',
            'entity_id' => $department->id,
        ]);
    }

    #[Group('TC-ADN-008-P')]
    public function test_admin_can_change_the_discount_approval_threshold(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $this->actingAs($administrator, 'web')
            ->getJson('/api/v1/admin/settings/audit.retention_years')
            ->assertOk()
            ->assertJsonPath('data.value', 7);

        $this->actingAs($administrator, 'web')
            ->putJson('/api/v1/admin/settings/discount.approval_threshold_percent', [
                'value' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('data.key', 'discount.approval_threshold_percent')
            ->assertJsonPath('data.value', 15);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'system_setting',
            'action' => 'created',
        ]);
    }

    #[Group('TC-ADN-008-N')]
    public function test_invalid_attendance_threshold_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $this->actingAs($administrator, 'web')
            ->putJson('/api/v1/admin/settings/attendance.threshold_percent', [
                'value' => 150,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'audit.retention_years',
            'value' => '7',
        ]);
        $this->assertDatabaseMissing('system_settings', [
            'key' => 'attendance.threshold_percent',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
