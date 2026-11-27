<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Campus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\Auth\UserInvitationNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Group('TC-ADN-001-P')]
    public function test_administrator_can_invite_a_registrar_and_accept_the_invitation(): void
    {
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $registrarRole = Role::query()->where('name', 'Registrar / Admissions Officer')->firstOrFail();
        $campus = Campus::factory()->create();

        $response = $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/users', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.test',
                'role_ids' => [$registrarRole->id],
                'campus_ids' => [$campus->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.test')
            ->assertJsonPath('data.is_active', false);

        $user = User::withTrashed()->findOrFail($response->json('data.id'));
        $this->assertDatabaseHas('user_invitations', [
            'user_id' => $user->id,
            'accepted_at' => null,
        ]);
        Notification::assertSentTo($user, UserInvitationNotification::class);

        $invitation = $user->invitation()->firstOrFail();
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => false,
            'email_verified_at' => null,
        ]);

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/auth/invitations/accept', [
                'token' => $this->plaintextTokenFor($invitation),
                'password' => 'StrongPass123',
                'password_confirmation' => 'StrongPass123',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => true,
        ]);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[Group('TC-ADN-001-N')]
    public function test_last_bootstrap_administrator_cannot_be_deactivated_or_deleted(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $this->actingAs($administrator, 'web')
            ->patchJson("/api/v1/admin/users/{$administrator->id}/deactivate")
            ->assertConflict();

        $this->actingAs($administrator, 'web')
            ->deleteJson("/api/v1/admin/users/{$administrator->id}")
            ->assertConflict();

        $this->assertDatabaseHas('users', [
            'id' => $administrator->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_public_invitation_assignment_rejects_bootstrap_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $bootstrapRole = Role::query()->where('is_bootstrap', true)->firstOrFail();

        $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/users', [
                'first_name' => 'Invalid',
                'last_name' => 'Admin',
                'email' => 'invalid-admin@example.test',
                'role_ids' => [$bootstrapRole->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_ids']);

        $this->assertDatabaseMissing('users', ['email' => 'invalid-admin@example.test']);
    }

    private function plaintextTokenFor(UserInvitation $invitation): string
    {
        $token = str_repeat('x', 64);
        $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
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
