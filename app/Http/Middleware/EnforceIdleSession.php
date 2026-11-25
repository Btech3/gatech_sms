<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdleSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');

            return $response;
        }

        $session = $request->session();
        $lastActivity = $session->get('auth_last_activity_at');
        $idleTimeout = (int) config('session.idle_timeout', 30);

        // Existing sessions predate this timestamp, so use their persisted activity time once.
        if (! is_numeric($lastActivity) && config('session.driver') === 'database') {
            $lastActivity = DB::table(config('session.table', 'sessions'))
                ->where('id', $session->getId())
                ->value('last_activity');
        }

        if (is_numeric($lastActivity) && now()->timestamp - (int) $lastActivity >= $idleTimeout * 60) {
            auth()->guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return response()->json([
                'message' => 'Your session expired due to inactivity. Please sign in again.',
            ], 401, [
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
            ]);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        if (auth()->guard('web')->check()) {
            $session->put('auth_last_activity_at', now()->timestamp);
        }

        return $response;
    }
}
