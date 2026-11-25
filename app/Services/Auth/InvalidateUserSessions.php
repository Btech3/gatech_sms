<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvalidateUserSessions
{
    public function forUser(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            throw new LogicException('Authentication session invalidation requires the database session driver.');
        }

        $sessions = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey());

        if ($exceptSessionId !== null) {
            $sessions->where('id', '!=', $exceptSessionId);
        }

        $sessions->delete();
    }
}
