<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $storyPermissions = [
            'Admissions.Admit',
            'Admissions.Create',
            'Admissions.Decide',
            'Admissions.Manage',
            'Admissions.Review',
            'Admissions.View',
            'Attendance.Correct',
            'Attendance.Export',
            'Attendance.Mark',
            'Audit.View',
            'Certificates.Manage',
            'Certificates.Revoke',
            'Classes.Create',
            'Classes.Edit',
            'Classes.View',
            'Courses.Archive',
            'Courses.Create',
            'Courses.Edit',
            'Courses.Manage',
            'Courses.ManageFees',
            'Courses.View',
            'Dashboard.View',
            'Documents.Manage',
            'Enrollment.Create',
            'Enrollment.Transfer',
            'Enrollment.Withdraw',
            'Finance.View',
            'Instructors.Create',
            'Invoices.Create',
            'Payments.Adjust',
            'Payments.ManagePlans',
            'Payments.Reconcile',
            'Payments.Record',
            'Payments.View',
            'Permissions.Manage',
            'Reports.Export',
            'Reports.Finance',
            'Reports.View',
            'Roles.Manage',
            'Schedule.Cancel',
            'Schedule.Create',
            'Schedule.Edit',
            'Settings.Manage',
            'Students.ChangeStatus',
            'Students.Create',
            'Students.Edit',
            'Students.Export',
            'Students.Import',
            'Students.Manage',
            'Students.View',
            'Users.Manage',
        ];
        $matrixActions = ['View', 'Create', 'Edit', 'Delete', 'Approve', 'Export'];
        $modules = collect($storyPermissions)
            ->map(fn (string $name): string => explode('.', $name, 2)[0])
            ->unique()
            ->values();
        $permissionNames = collect($storyPermissions);

        foreach ($modules as $module) {
            foreach ($matrixActions as $action) {
                $permissionNames->push("{$module}.{$action}");
            }
        }

        DB::transaction(function () use ($permissionNames): void {
            foreach ($permissionNames->unique()->values() as $name) {
                [$module, $action] = explode('.', $name, 2);

                Permission::query()->firstOrCreate(
                    ['name' => $name],
                    ['module' => $module, 'action' => $action],
                );
            }

            $roles = [
                [
                    'name' => 'Academy Administrator',
                    'description' => 'ADN-002 Admin mapped to the Academy Administrator persona.',
                ],
                [
                    'name' => 'Registrar / Admissions Officer',
                    'description' => 'ADN-002 Registrar mapped to the Registrar / Admissions Officer persona.',
                ],
                ['name' => 'Instructor', 'description' => 'Default instructor access.'],
                ['name' => 'Finance Officer', 'description' => 'Default finance access.'],
                ['name' => 'Academy Head', 'description' => 'Default academy leadership access.'],
                [
                    'name' => 'System Administrator',
                    'description' => 'Reserved for the controlled CLI bootstrap process.',
                    'is_bootstrap' => true,
                ],
            ];

            $newlyCreatedRoleNames = [];

            foreach ($roles as $attributes) {
                $name = $attributes['name'];
                $role = Role::query()->firstOrNew(['name_key' => mb_strtolower($name)]);

                if ($role->exists && ! $role->is_system) {
                    throw new \LogicException("The reserved role name [{$name}] is already in use.");
                }

                $isNewRole = ! $role->exists;
                $role->forceFill([
                    'name' => $name,
                    'description' => $attributes['description'],
                    'is_system' => true,
                    'is_bootstrap' => $attributes['is_bootstrap'] ?? false,
                ])->save();

                if ($isNewRole) {
                    $newlyCreatedRoleNames[$name] = true;
                }
            }

            $permissionIds = Permission::query()->pluck('id', 'name');
            $rolePermissions = [
                'Academy Administrator' => [
                    'Dashboard.View',
                    'Courses.View', 'Courses.Create', 'Courses.Edit', 'Courses.Manage',
                    'Classes.View', 'Classes.Create', 'Classes.Edit',
                    'Schedule.View', 'Schedule.Create', 'Schedule.Edit', 'Schedule.Cancel',
                    'Attendance.Mark', 'Attendance.Correct', 'Attendance.Export',
                    'Instructors.Create',
                    'Reports.View', 'Reports.Export',
                ],
                'Registrar / Admissions Officer' => [
                    'Admissions.Admit', 'Admissions.Create', 'Admissions.Decide',
                    'Admissions.Manage', 'Admissions.Review', 'Admissions.View',
                    'Students.ChangeStatus', 'Students.Create', 'Students.Edit',
                    'Students.Export', 'Students.Import', 'Students.Manage', 'Students.View',
                    'Enrollment.Create', 'Enrollment.Transfer', 'Enrollment.Withdraw',
                    'Courses.View',
                ],
                'Instructor' => ['Classes.View', 'Schedule.View', 'Attendance.Mark'],
                'Finance Officer' => [
                    'Dashboard.View', 'Payments.View', 'Payments.Record',
                    'Invoices.Create', 'Reports.View', 'Reports.Finance',
                ],
                'Academy Head' => [
                    'Dashboard.View', 'Reports.View', 'Reports.Finance', 'Payments.Adjust',
                ],
            ];

            foreach ($rolePermissions as $roleName => $names) {
                if (! isset($newlyCreatedRoleNames[$roleName])) {
                    continue;
                }

                $role = Role::query()->where('name_key', mb_strtolower($roleName))->firstOrFail();
                $role->permissions()->syncWithoutDetaching(
                    collect($names)->map(fn (string $name): int => $permissionIds[$name])->all(),
                );
            }

            $systemAdministrator = Role::query()
                ->where('is_bootstrap', true)
                ->firstOrFail();
            $systemAdministratorPermissions = Permission::query()
                ->where(function ($query): void {
                    $query->whereNotIn('module', ['Payments', 'Invoices', 'Finance', 'Refunds', 'Discounts'])
                        ->orWhere('action', '!=', 'Approve');
                })
                ->pluck('id')
                ->all();
            $systemAdministrator->permissions()->sync($systemAdministratorPermissions);
        });
    }
}
