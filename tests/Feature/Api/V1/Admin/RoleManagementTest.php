<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Http\Middleware\CheckPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_role_endpoints_return_401_without_a_session(): void
    {
        $this->getJson('/api/v1/admin/roles')->assertUnauthorized();
    }

    #[Group('TC-ADN-002-P')]
    public function test_system_administrator_can_clone_a_role_with_its_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $registrar = Role::query()->where('name', 'Registrar / Admissions Officer')->firstOrFail();
        $expectedPermissions = $registrar->permissions()->orderBy('name')->pluck('name')->all();

        $response = $this->actingAs($administrator, 'web')
            ->postJson("/api/v1/admin/roles/{$registrar->id}/clone", [
                'name' => 'Senior Registrar',
                'description' => 'Experienced registrar access.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Senior Registrar')
            ->assertJsonPath('data.is_system', false);

        $clonedRole = Role::query()->where('name', 'Senior Registrar')->firstOrFail();
        $this->assertSame(
            $expectedPermissions,
            $clonedRole->permissions()->orderBy('name')->pluck('name')->all(),
        );

        $this->actingAs($administrator, 'web')
            ->patchJson("/api/v1/admin/roles/{$clonedRole->id}", [
                'description' => 'Updated senior registrar access.',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Updated senior registrar access.');

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Roles',
            'action' => 'cloned',
            'entity_id' => $clonedRole->id,
            'user_id' => $administrator->id,
        ]);
    }

    #[Group('TC-ADN-002-N')]
    public function test_delete_returns_409_when_a_role_is_assigned_to_users(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $registrar = Role::query()->where('name', 'Registrar / Admissions Officer')->firstOrFail();
        $this->userWithRole($registrar->name);

        $this->actingAs($administrator, 'web')
            ->deleteJson("/api/v1/admin/roles/{$registrar->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'This role is assigned to 1 user(s) and cannot be deleted.');

        $this->assertModelExists($registrar);
    }

    public function test_new_custom_roles_have_no_permissions_and_reject_privileged_fields(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/roles', [
                'name' => '  Custom   Reviewer ',
                'description' => 'A role created for a focused workflow.',
                'is_system' => true,
                'is_bootstrap' => true,
                'permissions' => ['Permissions.Manage'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_system', 'is_bootstrap', 'permissions']);

        $response = $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/roles', [
                'name' => '  Custom   Reviewer ',
                'description' => 'A role created for a focused workflow.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Custom Reviewer')
            ->assertJsonPath('data.permissions', []);

        $this->assertDatabaseHas('roles', [
            'name' => 'Custom Reviewer',
            'name_key' => 'custom reviewer',
            'is_system' => false,
            'is_bootstrap' => false,
        ]);
    }

    public function test_role_names_are_unique_without_case_or_whitespace_variants(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/roles', ['name' => 'Custom Reviewer'])
            ->assertCreated();

        $this->actingAs($administrator, 'web')
            ->postJson('/api/v1/admin/roles', ['name' => '  CUSTOM   REVIEWER  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(7, Role::query()->count());
    }

    #[Group('TC-ADN-003-P')]
    public function test_removing_payments_record_updates_the_matrix_and_denies_the_finance_api(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $financeOfficer = $this->userWithRole('Finance Officer');
        $financeRole = Role::query()->where('name', 'Finance Officer')->firstOrFail();
        $paymentsRecord = Permission::query()->where('name', 'Payments.Record')->firstOrFail();
        $catalog = $this->actingAs($administrator, 'web')
            ->getJson('/api/v1/admin/permissions')
            ->assertOk()
            ->json('data.modules');
        $paymentModule = collect($catalog)->firstWhere('name', 'Payments');
        $this->assertSame(
            ['Adjust', 'Approve', 'Create', 'Delete', 'Edit', 'Export', 'ManagePlans', 'Reconcile', 'Record', 'View'],
            collect($paymentModule['actions'])->pluck('name')->all(),
        );

        Route::get('/_test/finance/payments', fn () => response()->json(['data' => []]))
            ->middleware(['auth:sanctum', CheckPermission::class.':Payments.Record']);
        $testRoute = Route::getRoutes()->match(Request::create('/_test/finance/payments'));

        $remainingPermissions = [
            'Dashboard.View',
            'Payments.View',
            'Invoices.Create',
            'Reports.View',
            'Reports.Finance',
        ];

        $this->actingAs($administrator, 'web')
            ->putJson("/api/v1/admin/roles/{$financeRole->id}/permissions", [
                'permissions' => $remainingPermissions,
            ])
            ->assertOk()
            ->assertJsonPath('data.role.name', 'Finance Officer');

        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $financeRole->id,
            'permission_id' => $paymentsRecord->id,
        ]);
        $this->assertFalse($financeOfficer->fresh()->hasPermission('Payments.Record'));
        $this->assertContains(
            CheckPermission::class.':Payments.Record',
            $testRoute->gatherMiddleware(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Permissions',
            'action' => 'role_permissions_updated',
            'entity_id' => $financeRole->id,
            'user_id' => $administrator->id,
        ]);

        $module = collect($this->actingAs($administrator, 'web')
            ->getJson("/api/v1/admin/roles/{$financeRole->id}/permissions")
            ->assertOk()
            ->json('data.modules'))
            ->firstWhere('name', 'Payments');
        $recordAction = collect($module['actions'])->firstWhere('permission', 'Payments.Record');
        $this->assertFalse($recordAction['granted']);

        Auth::forgetGuards();
        $this->actingAs($financeOfficer, 'web')
            ->getJson('/_test/finance/payments')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Authorization',
            'action' => 'permission_denied',
            'user_id' => $financeOfficer->id,
        ]);
    }

    #[Group('TC-ADN-003-N')]
    public function test_registrar_cannot_edit_the_permission_matrix_and_the_attempt_is_audited(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $registrar = $this->userWithRole('Registrar / Admissions Officer');
        $financeRole = Role::query()->where('name', 'Finance Officer')->firstOrFail();
        $permissionsBefore = $financeRole->permissions()->orderBy('name')->pluck('name')->all();

        $this->actingAs($registrar, 'web')
            ->putJson("/api/v1/admin/roles/{$financeRole->id}/permissions", [
                'permissions' => [],
            ])
            ->assertForbidden();

        $this->assertSame(
            $permissionsBefore,
            $financeRole->fresh()->permissions()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Authorization',
            'action' => 'permission_denied',
            'user_id' => $registrar->id,
        ]);
    }

    public function test_role_manager_cannot_grant_a_permission_they_do_not_have(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $managerRole = Role::query()->create([
            'name' => 'Limited Permission Manager',
            'name_key' => 'limited permission manager',
            'description' => null,
        ]);
        $managerRole->permissions()->attach(
            Permission::query()->where('name', 'Permissions.Manage')->value('id'),
        );
        $manager = $this->userWithRole($managerRole->name);
        $targetRole = Role::query()->where('name', 'Instructor')->firstOrFail();

        $this->actingAs($manager, 'web')
            ->putJson("/api/v1/admin/roles/{$targetRole->id}/permissions", [
                'permissions' => ['Payments.Record'],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Authorization',
            'action' => 'permission_grant_denied',
            'user_id' => $manager->id,
            'entity_id' => $targetRole->id,
        ]);
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $targetRole->id,
            'permission_id' => Permission::query()->where('name', 'Payments.Record')->value('id'),
        ]);
    }

    public function test_bootstrap_command_assigns_the_reserved_role_once_to_an_active_verified_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $nextUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->artisan('app:bootstrap-system-administrator', ['email' => $administrator->email])
            ->assertSuccessful();

        $this->assertTrue($administrator->fresh()->hasPermission('Permissions.Manage'));
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Authorization',
            'action' => 'system_administrator_bootstrapped',
            'entity_id' => $administrator->id,
        ]);

        $this->artisan('app:bootstrap-system-administrator', ['email' => $nextUser->email])
            ->assertFailed();
        $this->assertFalse($nextUser->fresh()->isBootstrapAdministrator());
    }

    public function test_bootstrap_command_rejects_an_inactive_or_unverified_account(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->unverified()->create(['is_active' => false]);

        $this->artisan('app:bootstrap-system-administrator', ['email' => $user->email])
            ->assertFailed();

        $this->assertFalse($user->fresh()->isBootstrapAdministrator());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'system_administrator_bootstrapped',
            'entity_id' => $user->id,
        ]);
    }

    public function test_bootstrap_administrator_cannot_remove_permissions_from_its_protected_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');
        $bootstrapRole = Role::query()->where('is_bootstrap', true)->firstOrFail();

        $this->actingAs($administrator, 'web')
            ->putJson("/api/v1/admin/roles/{$bootstrapRole->id}/permissions", [
                'permissions' => [],
            ])
            ->assertConflict();

        $this->assertTrue($administrator->fresh()->hasPermission('Permissions.Manage'));
    }

    public function test_bootstrap_administrator_has_configuration_access_but_no_finance_approval_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $this->assertTrue($administrator->hasPermission('Settings.Manage'));
        $this->assertTrue($administrator->hasPermission('Roles.Manage'));
        $this->assertFalse($administrator->hasPermission('Payments.Approve'));
        $this->assertFalse($administrator->hasPermission('Invoices.Approve'));
    }

    public function test_bootstrap_administrator_is_not_granted_finance_module_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $bootstrapRole = Role::query()->where('is_bootstrap', true)->firstOrFail();
        $paymentsRecord = Permission::query()->where('name', 'Payments.Record')->firstOrFail();
        $bootstrapRole->permissions()->attach($paymentsRecord);
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $bootstrapRole->id,
            'permission_id' => $paymentsRecord->id,
        ]);

        $this->seed(RolePermissionSeeder::class);

        $this->assertFalse($bootstrapRole->permissions()
            ->whereIn('module', ['Payments', 'Invoices', 'Finance', 'Refunds', 'Discounts'])
            ->exists());
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
