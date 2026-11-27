<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Group('TC-ADN-009-P')]
    public function test_admin_can_filter_audit_logs_and_sensitive_secrets_are_removed(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $logger = app(AuditLogger::class);
        $targetLog = $logger->record(
            $administrator,
            'Students',
            'updated',
            'student',
            456,
            beforeValues: ['status' => 'applicant'],
            afterValues: [
                'status' => 'active',
                'password' => 'must-not-be-saved',
                'nested' => ['access_token' => 'must-not-be-saved', 'status' => 'active'],
            ],
        );
        $logger->record($administrator, 'Finance', 'created', 'invoice', 99);

        $response = $this->actingAs($administrator, 'web')
            ->getJson('/api/v1/admin/audit-logs?entity_type=student&entity_id=456')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $administrator->id)
            ->assertJsonPath('data.0.before_values.status', 'applicant')
            ->assertJsonPath('data.0.after_values.status', 'active');

        $this->assertArrayNotHasKey('password', $response->json('data.0.after_values'));
        $this->assertArrayNotHasKey('access_token', $response->json('data.0.after_values.nested'));
        $this->assertSame($targetLog->id, $response->json('data.0.id'));
    }

    #[Group('TC-ADN-009-N')]
    public function test_audit_log_deletion_is_rejected_and_the_attempt_is_logged(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $log = app(AuditLogger::class)->record(
            $administrator,
            'Students',
            'created',
            'student',
            123,
        );

        $this->actingAs($administrator, 'web')
            ->deleteJson("/api/v1/admin/audit-logs/{$log->id}")
            ->assertStatus(405)
            ->assertJsonPath('message', 'Audit log entries are immutable.');

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Audit',
            'action' => 'mutation_denied',
            'entity_id' => $log->id,
        ]);
    }

    #[Group('TC-ADN-009-P')]
    public function test_admin_can_export_filtered_audit_logs(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        app(AuditLogger::class)->record($administrator, 'Students', 'updated', 'student', 456);

        $response = $this->actingAs($administrator, 'web')
            ->get('/api/v1/admin/audit-logs/export?entity_type=student&entity_id=456')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('student', $response->streamedContent());

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Audit',
            'action' => 'exported',
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
