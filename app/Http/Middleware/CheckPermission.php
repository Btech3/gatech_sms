<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->hasPermission($permission)) {
            $this->auditLogger->record(
                $user,
                'Authorization',
                'permission_denied',
                'route',
                null,
                afterValues: [
                    'required_permission' => $permission,
                    'route' => $request->route()?->uri(),
                ],
                request: $request,
            );

            return response()->json([
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
