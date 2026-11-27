<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveIntakeRequest;
use App\Http\Resources\Api\V1\Admin\IntakeResource;
use App\Models\Intake;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\CampusAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class IntakeController extends Controller
{
    public function index(Request $request, CampusAccess $campusAccess): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $campusIds = $campusAccess->queryFor($actor)->select('campuses.id');

        return IntakeResource::collection(
            Intake::query()
                ->where(function ($query) use ($campusIds, $actor): void {
                    if ($actor->isBootstrapAdministrator()) {
                        return;
                    }

                    $query->whereNull('campus_id')->orWhereIn('campus_id', $campusIds);
                })
                ->with(['academicSession:id,name', 'campus:id,name', 'course:id,name'])
                ->orderBy('starts_on')
                ->paginate(50),
        );
    }

    public function store(
        SaveIntakeRequest $request,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $attributes = $request->validated();
        $this->ensureCampusAccess($actor, $campusAccess, $attributes['campus_id'] ?? null);

        $intake = DB::transaction(function () use ($actor, $attributes, $auditLogger, $request): Intake {
            $intake = Intake::query()->create($attributes);
            $auditLogger->record(
                $actor,
                'Settings',
                'created',
                'intake',
                $intake->id,
                afterValues: $this->snapshot($intake),
                request: $request,
            );

            return $intake;
        });

        return (new IntakeResource($intake))->response()->setStatusCode(201);
    }

    public function update(
        SaveIntakeRequest $request,
        Intake $intake,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): IntakeResource {
        $actor = $this->actor($request);
        $this->ensureCampusAccess($actor, $campusAccess, $intake->campus_id);
        $attributes = $request->validated();
        $this->ensureCampusAccess($actor, $campusAccess, $attributes['campus_id'] ?? null);

        DB::transaction(function () use ($actor, $intake, $attributes, $auditLogger, $request): void {
            $beforeValues = $this->snapshot($intake);
            $intake->update($attributes);
            $auditLogger->record(
                $actor,
                'Settings',
                'updated',
                'intake',
                $intake->id,
                beforeValues: $beforeValues,
                afterValues: $this->snapshot($intake->refresh()),
                request: $request,
            );
        });

        return new IntakeResource($intake);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function ensureCampusAccess(User $actor, CampusAccess $campusAccess, ?int $campusId): void
    {
        if ($campusId !== null) {
            $campusAccess->findFor($actor, $campusId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Intake $intake): array
    {
        return [
            'academic_session_id' => $intake->academic_session_id,
            'course_id' => $intake->course_id,
            'campus_id' => $intake->campus_id,
            'name' => $intake->name,
            'starts_on' => $intake->starts_on?->toDateString(),
            'applications_open_at' => $intake->applications_open_at?->toIso8601String(),
            'applications_close_at' => $intake->applications_close_at?->toIso8601String(),
            'capacity' => $intake->capacity,
            'is_active' => $intake->is_active,
        ];
    }
}
