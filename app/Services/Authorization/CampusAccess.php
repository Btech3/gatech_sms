<?php

namespace App\Services\Authorization;

use App\Models\Campus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CampusAccess
{
    /**
     * @return Builder<Campus>
     */
    public function queryFor(User $user): Builder
    {
        $query = Campus::query();

        if (! $user->isBootstrapAdministrator()) {
            $query->whereIn('campuses.id', $user->campuses()->select('campuses.id'));
        }

        return $query;
    }

    public function findFor(User $user, int $campusId): Campus
    {
        return $this->queryFor($user)->whereKey($campusId)->firstOrFail();
    }
}
