<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\InviteUserRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserResource;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\Auth\UserInvitationNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\InvalidateUserSessions;
use App\Services\Authorization\CampusAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function index(Request $request, CampusAccess $campusAccess): AnonymousResourceCollection
    {
        $actor = $this->actor($request);

        return AdminUserResource::collection(
            $this->usersVisibleTo($actor, $campusAccess)
                ->with(['roles:id,name', 'campuses:id,name'])
                ->orderBy('name')
                ->paginate(50),
        );
    }

    public function store(
        InviteUserRequest $request,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $attributes = $request->validated();
        $roles = Role::query()
            ->with('permissions:id,name')
            ->whereIn('id', $attributes['role_ids'])
            ->get();

        if ($roles->count() !== count(array_unique($attributes['role_ids']))) {
            throw ValidationException::withMessages([
                'role_ids' => ['One or more selected roles are no longer available.'],
            ]);
        }

        if ($roles->contains(fn (Role $role): bool => $role->is_bootstrap)) {
            throw ValidationException::withMessages([
                'role_ids' => ['The protected System Administrator role cannot be assigned through this endpoint.'],
            ]);
        }

        foreach ($attributes['campus_ids'] ?? [] as $campusId) {
            $campusAccess->findFor($actor, $campusId);
        }

        $this->ensureCanGrantRoles($actor, $roles, $auditLogger, $request);

        $plainToken = Str::random(64);
        $user = DB::transaction(function () use ($attributes, $roles, $plainToken, $actor, $auditLogger, $request): User {
            $user = User::query()->createOrFirst(
                ['email' => $attributes['email']],
                [
                    'first_name' => $attributes['first_name'],
                    'last_name' => $attributes['last_name'],
                    'name' => trim($attributes['first_name'].' '.$attributes['last_name']),
                    'phone' => $attributes['phone'] ?? null,
                    'password' => Str::random(64),
                ],
            );

            if (! $user->wasRecentlyCreated) {
                throw ValidationException::withMessages([
                    'email' => ['The email address already has an account.'],
                ]);
            }

            $user->forceFill([
                'is_active' => false,
                'email_verified_at' => null,
            ])->save();
            $user->roles()->sync($roles->modelKeys());
            $user->campuses()->sync(collect($attributes['campus_ids'] ?? [])
                ->mapWithKeys(fn (int $campusId, int $index): array => [
                    $campusId => ['is_primary' => $index === 0],
                ])->all());

            UserInvitation::query()->create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHours(48),
            ]);

            $auditLogger->record(
                $actor,
                'Users',
                'invited',
                'user',
                $user->id,
                afterValues: [
                    'email' => $user->email,
                    'role_names' => $roles->pluck('name')->all(),
                    'campus_ids' => $attributes['campus_ids'],
                    'is_active' => false,
                ],
                request: $request,
            );

            return $user;
        });

        $user->notify(new UserInvitationNotification($this->invitationUrl($user, $plainToken)));

        return (new AdminUserResource($user->load(['roles:id,name', 'campuses:id,name'])))
            ->additional(['message' => 'The invitation notification has been queued.'])
            ->response()
            ->setStatusCode(201);
    }

    public function resendInvitation(
        Request $request,
        User $user,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->ensureUserAccessible($actor, $user, $campusAccess);
        abort_if($user->is_active || $user->email_verified_at !== null, 409, 'This user has already accepted the invitation.');

        $plainToken = Str::random(64);
        DB::transaction(function () use ($user, $plainToken, $actor, $auditLogger, $request): void {
            $invitation = $user->invitation()->lockForUpdate()->firstOrFail();
            $invitation->update([
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHours(48),
            ]);
            $auditLogger->record(
                $actor,
                'Users',
                'invitation_resent',
                'user',
                $user->id,
                afterValues: ['expires_at' => $invitation->expires_at->toIso8601String()],
                request: $request,
            );
        });

        $user->notify(new UserInvitationNotification($this->invitationUrl($user, $plainToken)));

        return response()->json(['message' => 'The invitation notification has been queued.'], 202);
    }

    public function deactivate(
        Request $request,
        User $user,
        CampusAccess $campusAccess,
        InvalidateUserSessions $sessions,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->ensureUserAccessible($actor, $user, $campusAccess);

        $user = DB::transaction(function () use ($user, $actor, $sessions, $auditLogger, $request): User {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->guardLastBootstrapAdministrator($user);
            $wasActive = $user->is_active;
            $user->forceFill([
                'is_active' => false,
                'remember_token' => Str::random(60),
            ])->save();
            $sessions->forUser($user);
            $auditLogger->record(
                $actor,
                'Users',
                'deactivated',
                'user',
                $user->id,
                beforeValues: ['is_active' => $wasActive],
                afterValues: ['is_active' => false],
                request: $request,
            );

            return $user->refresh();
        });

        return response()->json([
            'data' => new AdminUserResource($user->load(['roles:id,name', 'campuses:id,name'])),
            'message' => 'The user has been deactivated.',
        ]);
    }

    public function destroy(
        Request $request,
        User $user,
        CampusAccess $campusAccess,
        InvalidateUserSessions $sessions,
        AuditLogger $auditLogger,
    ): Response {
        $actor = $this->actor($request);
        $this->ensureUserAccessible($actor, $user, $campusAccess);

        DB::transaction(function () use ($user, $actor, $sessions, $auditLogger, $request): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->guardLastBootstrapAdministrator($user);
            $wasActive = $user->is_active;
            $user->forceFill([
                'is_active' => false,
                'remember_token' => Str::random(60),
            ])->save();
            $sessions->forUser($user);
            $user->delete();
            $auditLogger->record(
                $actor,
                'Users',
                'deleted',
                'user',
                $user->id,
                beforeValues: ['is_active' => $wasActive],
                afterValues: ['deleted' => true, 'is_active' => false],
                request: $request,
            );
        });

        return response()->noContent();
    }

    public function sendPasswordReset(
        Request $request,
        User $user,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->ensureUserAccessible($actor, $user, $campusAccess);
        abort_unless($user->is_active && $user->email_verified_at !== null, 409, 'Only active, verified users can receive a password reset link.');

        $status = Password::sendResetLink(['email' => $user->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'The password reset link could not be issued. Please retry after the reset throttle expires.',
            ], $status === Password::RESET_THROTTLED ? 429 : 503);
        }

        $auditLogger->record(
            $actor,
            'Users',
            'password_reset_requested',
            'user',
            $user->id,
            request: $request,
        );

        return response()->json(['message' => 'Password reset instructions were requested.'], 202);
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    private function ensureCanGrantRoles(
        User $actor,
        Collection $roles,
        AuditLogger $auditLogger,
        Request $request,
    ): void {
        if ($actor->isBootstrapAdministrator()) {
            return;
        }

        $actorPermissions = $actor->permissionNames()->all();
        $requestedPermissions = $roles->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique();
        $forbidden = $requestedPermissions
            ->reject(fn (string $permission): bool => in_array($permission, $actorPermissions, true))
            ->values()
            ->all();

        if ($forbidden === []) {
            return;
        }

        $auditLogger->record(
            $actor,
            'Authorization',
            'user_role_assignment_denied',
            'user',
            null,
            afterValues: ['permissions' => $forbidden],
            request: $request,
        );

        throw new AuthorizationException('You cannot assign roles with permissions you do not have.');
    }

    private function usersVisibleTo(User $actor, CampusAccess $campusAccess): Builder
    {
        $query = User::query();

        if (! $actor->isBootstrapAdministrator()) {
            $campusIds = $campusAccess->queryFor($actor)->select('campuses.id');
            $query->whereHas('campuses', fn (Builder $campusQuery) => $campusQuery->whereIn('campuses.id', $campusIds));
        }

        return $query;
    }

    private function ensureUserAccessible(User $actor, User $target, CampusAccess $campusAccess): void
    {
        if ($actor->isBootstrapAdministrator()) {
            return;
        }

        $campusIds = $campusAccess->queryFor($actor)->select('campuses.id');
        abort_unless(
            $target->campuses()->whereIn('campuses.id', $campusIds)->exists(),
            404,
        );
    }

    private function guardLastBootstrapAdministrator(User $user): void
    {
        if (! $user->isBootstrapAdministrator()) {
            return;
        }

        Role::query()->where('is_bootstrap', true)->lockForUpdate()->firstOrFail();
        $activeAdministrators = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->where('roles.is_bootstrap', true))
            ->count();

        abort_if($activeAdministrators <= 1, 409, 'The last active System Administrator cannot be deactivated or deleted.');
    }

    private function invitationUrl(User $user, string $token): string
    {
        return route('api.v1.auth.invitation.show', [
            'user' => $user->id,
            'token' => $token,
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
