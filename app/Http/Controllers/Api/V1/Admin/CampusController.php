<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCampusRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCampusRequest;
use App\Http\Resources\Api\V1\Admin\CampusResource;
use App\Models\Campus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\CampusAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CampusController extends Controller
{
    public function index(Request $request, CampusAccess $campusAccess): AnonymousResourceCollection
    {
        return CampusResource::collection(
            $campusAccess->queryFor($this->actor($request))
                ->withCount('rooms')
                ->orderBy('name')
                ->paginate(50),
        );
    }

    public function store(
        StoreCampusRequest $request,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $attributes = $request->safe()->only(['name', 'address', 'phone', 'email']);

        $campus = DB::transaction(function () use ($actor, $attributes, $auditLogger, $request): Campus {
            $campus = Campus::query()->create($attributes);

            if (! $actor->isBootstrapAdministrator()) {
                $hasPrimaryCampus = $actor->campuses()->wherePivot('is_primary', true)->exists();
                $campus->users()->attach($actor, ['is_primary' => ! $hasPrimaryCampus]);
            }

            $auditLogger->record(
                $actor,
                'Campuses',
                'created',
                'campus',
                $campus->id,
                afterValues: $this->snapshot($campus),
                request: $request,
            );

            return $campus;
        });

        return (new CampusResource($campus->loadCount('rooms')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Campus $campus, CampusAccess $campusAccess): CampusResource
    {
        $campus = $campusAccess->findFor($this->actor($request), $campus->id);

        return new CampusResource($campus->loadCount('rooms'));
    }

    public function update(
        UpdateCampusRequest $request,
        Campus $campus,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): CampusResource {
        $actor = $this->actor($request);
        $campus = $campusAccess->findFor($actor, $campus->id);

        DB::transaction(function () use ($actor, $campus, $auditLogger, $request): void {
            $beforeValues = $this->snapshot($campus);
            $campus->update($request->safe()->only(['name', 'address', 'phone', 'email', 'is_active']));

            $auditLogger->record(
                $actor,
                'Campuses',
                'updated',
                'campus',
                $campus->id,
                beforeValues: $beforeValues,
                afterValues: $this->snapshot($campus->refresh()),
                request: $request,
            );
        });

        return new CampusResource($campus->loadCount('rooms'));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /**
     * @return array{name: string, address: string, phone: ?string, email: ?string, is_active: bool}
     */
    private function snapshot(Campus $campus): array
    {
        return [
            'name' => $campus->name,
            'address' => $campus->address,
            'phone' => $campus->phone,
            'email' => $campus->email,
            'is_active' => $campus->is_active,
        ];
    }
}
