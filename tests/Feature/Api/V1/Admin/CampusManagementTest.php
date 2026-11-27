<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduledSession;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class CampusManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Group('TC-ADN-005-P')]
    public function test_admin_can_create_a_campus_and_a_room(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $campusResponse = $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/campuses', [
                'name' => 'North Campus',
                'address' => '10 School Road',
                'phone' => '+15550102000',
                'email' => 'north@example.test',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'North Campus');

        $campusId = $campusResponse->json('data.id');

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/campuses/{$campusId}/rooms", [
                'name' => 'Lab 1',
                'capacity' => 30,
                'type' => 'lab',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Lab 1')
            ->assertJsonPath('data.capacity', 30);

        $this->assertDatabaseHas('rooms', [
            'campus_id' => $campusId,
            'name' => 'Lab 1',
            'capacity' => 30,
            'type' => 'lab',
            'status' => 'active',
        ]);
    }

    #[Group('TC-ADN-005-N')]
    public function test_room_deactivation_is_blocked_when_future_sessions_are_scheduled(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $campus = Campus::factory()->create();
        $room = $campus->rooms()->create([
            'name' => 'Lab 1',
            'capacity' => 30,
            'type' => 'lab',
            'status' => 'active',
        ]);
        ScheduledSession::factory()->for($room)->create([
            'title' => 'Chemistry practical',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        $this->actingAs($administrator, 'web')
            ->patchJson("/api/v1/admin/campuses/{$campus->id}/rooms/{$room->id}", [
                'status' => 'inactive',
            ])
            ->assertConflict()
            ->assertJsonPath('conflicting_sessions.0.title', 'Chemistry practical');

        $this->assertSame('active', $room->fresh()->status);
    }

    #[Group('TC-ADN-005-N')]
    public function test_duplicate_room_names_are_rejected_within_the_same_campus(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $campus = Campus::factory()->create();
        $campus->rooms()->create([
            'name' => 'Lab 1',
            'capacity' => 30,
            'type' => 'lab',
            'status' => 'active',
        ]);

        $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/campuses/{$campus->id}/rooms", [
                'name' => 'Lab 1',
                'capacity' => 20,
                'type' => 'classroom',
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_campus_manager_cannot_read_another_campuss_rooms(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $managerRole = Role::query()->create([
            'name' => 'Campus Manager',
            'name_key' => 'campus manager',
            'description' => null,
        ]);
        $managerRole->permissions()->attach(
            Permission::query()->where('name', 'Settings.Manage')->value('id'),
        );
        $manager = User::factory()->create(['is_active' => true]);
        $manager->roles()->attach($managerRole);
        $assignedCampus = Campus::factory()->create();
        $otherCampus = Campus::factory()->create();
        $assignedCampus->users()->attach($manager, ['is_primary' => true]);

        $this->actingAs($manager, 'web')
            ->getJson('/api/v1/admin/campuses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedCampus->id);

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/admin/campuses/{$otherCampus->id}/rooms")
            ->assertNotFound();
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
