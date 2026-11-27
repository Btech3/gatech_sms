<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateRolePermissionsRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\RoleManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::query()
            ->orderBy('module')
            ->orderBy('action')
            ->get();

        return response()->json([
            'data' => [
                'modules' => $this->modules($permissions),
            ],
        ]);
    }

    public function showMatrix(Role $role): JsonResponse
    {
        $role->load('permissions');
        $permissions = Permission::query()
            ->orderBy('module')
            ->orderBy('action')
            ->get();

        return response()->json([
            'data' => [
                'role' => ['id' => $role->id, 'name' => $role->name],
                'modules' => $this->modules($permissions, $role),
            ],
        ]);
    }

    public function updateMatrix(
        UpdateRolePermissionsRequest $request,
        Role $role,
        RoleManager $roleManager,
    ): JsonResponse {
        $role = $roleManager->replacePermissions(
            $role,
            $request->validated('permissions'),
            $this->actor($request),
            $request,
        );

        $permissions = Permission::query()
            ->orderBy('module')
            ->orderBy('action')
            ->get();

        return response()->json([
            'data' => [
                'role' => ['id' => $role->id, 'name' => $role->name],
                'modules' => $this->modules($permissions, $role),
            ],
        ]);
    }

    /**
     * @return list<array{name: string, actions: list<array<string, mixed>>}>
     */
    private function modules(Collection $permissions, ?Role $role = null): array
    {
        $grantedPermissionIds = $role?->permissions->modelKeys() ?? [];

        return $permissions
            ->groupBy('module')
            ->map(function (Collection $modulePermissions, string $module) use ($grantedPermissionIds, $role): array {
                return [
                    'name' => $module,
                    'actions' => $modulePermissions
                        ->map(function (Permission $permission) use ($grantedPermissionIds, $role): array {
                            $action = [
                                'name' => $permission->action,
                                'permission' => $permission->name,
                            ];

                            if ($role !== null) {
                                $action['granted'] = in_array($permission->id, $grantedPermissionIds, true);
                            }

                            return $action;
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
