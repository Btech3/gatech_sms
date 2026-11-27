<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CloneRoleRequest;
use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRoleRequest;
use App\Http\Resources\Api\V1\Admin\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\RoleManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->paginate(50);

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request, RoleManager $roleManager): JsonResponse
    {
        $role = $roleManager->create(
            $request->safe()->only(['name', 'description']),
            $this->actor($request),
            $request,
        );

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function update(
        UpdateRoleRequest $request,
        Role $role,
        RoleManager $roleManager,
    ): RoleResource {
        return new RoleResource($roleManager->update(
            $role,
            $request->safe()->only(['name', 'description']),
            $this->actor($request),
            $request,
        ));
    }

    public function destroy(Request $request, Role $role, RoleManager $roleManager): Response
    {
        $roleManager->delete($role, $this->actor($request), $request);

        return response()->noContent();
    }

    public function cloneRole(
        CloneRoleRequest $request,
        Role $role,
        RoleManager $roleManager,
    ): JsonResponse {
        $clonedRole = $roleManager->cloneRole(
            $role,
            $request->safe()->only(['name', 'description']),
            $this->actor($request),
            $request,
        );

        return (new RoleResource($clonedRole))->response()->setStatusCode(201);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
