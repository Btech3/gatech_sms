<?php

namespace App\Services\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleManager
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, description?: string|null}  $attributes
     */
    public function create(array $attributes, User $actor, Request $request): Role
    {
        return DB::transaction(function () use ($attributes, $actor, $request): Role {
            $role = Role::query()->create([
                'name' => $attributes['name'],
                'name_key' => $this->nameKey($attributes['name']),
                'description' => $attributes['description'] ?? null,
            ]);

            $this->auditLogger->record(
                $actor,
                'Roles',
                'created',
                'role',
                $role->id,
                afterValues: ['name' => $role->name, 'description' => $role->description],
                request: $request,
            );

            return $this->loadRole($role);
        });
    }

    /**
     * @param  array{name: string, description?: string|null}  $attributes
     */
    public function cloneRole(Role $source, array $attributes, User $actor, Request $request): Role
    {
        $permissions = $source->permissions()->orderBy('name')->get();
        $this->assertCanGrant($source, $permissions, $actor, $request);

        return DB::transaction(function () use ($source, $attributes, $actor, $request, $permissions): Role {
            $role = Role::query()->create([
                'name' => $attributes['name'],
                'name_key' => $this->nameKey($attributes['name']),
                'description' => $attributes['description'] ?? null,
            ]);
            $role->permissions()->sync($permissions->modelKeys());

            $this->auditLogger->record(
                $actor,
                'Roles',
                'cloned',
                'role',
                $role->id,
                afterValues: [
                    'name' => $role->name,
                    'source_role_id' => $source->id,
                    'permissions' => $permissions->pluck('name')->all(),
                ],
                request: $request,
            );

            return $this->loadRole($role);
        });
    }

    /**
     * @param  array{name?: string, description?: string|null}  $attributes
     */
    public function update(Role $role, array $attributes, User $actor, Request $request): Role
    {
        abort_if($role->is_system, 409, 'Built-in roles cannot be renamed or edited here.');

        return DB::transaction(function () use ($role, $attributes, $actor, $request): Role {
            $beforeValues = $this->snapshot($role);
            $updates = [];

            if (array_key_exists('name', $attributes)) {
                $updates['name'] = $attributes['name'];
                $updates['name_key'] = $this->nameKey($attributes['name']);
            }

            if (array_key_exists('description', $attributes)) {
                $updates['description'] = $attributes['description'];
            }

            $role->update($updates);
            $role->refresh();

            $this->auditLogger->record(
                $actor,
                'Roles',
                'updated',
                'role',
                $role->id,
                beforeValues: $beforeValues,
                afterValues: $this->snapshot($role),
                request: $request,
            );

            return $this->loadRole($role);
        });
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function replacePermissions(
        Role $role,
        array $permissionNames,
        User $actor,
        Request $request,
    ): Role {
        abort_if($role->is_bootstrap, 409, 'The bootstrap administrator role is protected.');

        $permissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->orderBy('name')
            ->get();

        if ($permissions->count() !== count(array_unique($permissionNames))) {
            abort(422, 'One or more permissions are no longer available.');
        }

        $this->assertCanGrant($role, $permissions, $actor, $request);

        return DB::transaction(function () use ($role, $actor, $request, $permissions): Role {
            $role->load('permissions');
            $beforeValues = $this->snapshot($role);
            $role->permissions()->sync($permissions->modelKeys());

            $this->auditLogger->record(
                $actor,
                'Permissions',
                'role_permissions_updated',
                'role',
                $role->id,
                beforeValues: $beforeValues,
                afterValues: $this->snapshot($role->fresh('permissions')),
                request: $request,
            );

            return $this->loadRole($role);
        });
    }

    public function delete(Role $role, User $actor, Request $request): void
    {
        DB::transaction(function () use ($role, $actor, $request): void {
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            $userCount = $lockedRole->users()->count();
            abort_if(
                $userCount > 0,
                409,
                "This role is assigned to {$userCount} user(s) and cannot be deleted.",
            );
            abort_if($lockedRole->is_system, 409, 'Built-in roles cannot be deleted.');

            $beforeValues = $this->snapshot($lockedRole);
            $roleId = $lockedRole->id;
            $lockedRole->delete();

            $this->auditLogger->record(
                $actor,
                'Roles',
                'deleted',
                'role',
                $roleId,
                beforeValues: $beforeValues,
                request: $request,
            );
        });
    }

    private function assertCanGrant(
        Role $role,
        Collection $permissions,
        User $actor,
        Request $request,
    ): void {
        if ($actor->isBootstrapAdministrator()) {
            return;
        }

        $actorPermissions = $actor->permissionNames()->all();
        $forbiddenPermissions = $permissions
            ->pluck('name')
            ->reject(fn (string $name): bool => in_array($name, $actorPermissions, true))
            ->values()
            ->all();

        if ($forbiddenPermissions === []) {
            return;
        }

        $this->auditLogger->record(
            $actor,
            'Authorization',
            'permission_grant_denied',
            'role',
            $role->id,
            afterValues: ['permissions' => $forbiddenPermissions],
            request: $request,
        );

        throw new AuthorizationException('You cannot grant permissions you do not have.');
    }

    private function nameKey(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    /**
     * @return array{name: string, description: ?string, permissions: list<string>}
     */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all(),
        ];
    }

    private function loadRole(Role $role): Role
    {
        return $role->load('permissions')->loadCount('users');
    }
}
