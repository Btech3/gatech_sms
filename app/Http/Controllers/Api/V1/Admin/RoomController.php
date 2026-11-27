<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRoomRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRoomRequest;
use App\Http\Resources\Api\V1\Admin\RoomResource;
use App\Models\Campus;
use App\Models\Room;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\CampusAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function index(
        Request $request,
        Campus $campus,
        CampusAccess $campusAccess,
    ): AnonymousResourceCollection {
        $campusAccess->findFor($this->actor($request), $campus->id);

        return RoomResource::collection(
            $campus->rooms()->orderBy('name')->paginate(50),
        );
    }

    public function store(
        StoreRoomRequest $request,
        Campus $campus,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $campus = $campusAccess->findFor($actor, $campus->id);
        abort_if(! $campus->is_active, 409, 'Rooms cannot be added to an inactive campus.');

        $room = DB::transaction(function () use ($actor, $campus, $auditLogger, $request): Room {
            $room = $campus->rooms()->create(
                $request->safe()->only(['name', 'capacity', 'type', 'status']),
            );

            $auditLogger->record(
                $actor,
                'Rooms',
                'created',
                'room',
                $room->id,
                afterValues: $this->snapshot($room),
                request: $request,
            );

            return $room;
        });

        return (new RoomResource($room))->response()->setStatusCode(201);
    }

    public function update(
        UpdateRoomRequest $request,
        Campus $campus,
        Room $room,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): RoomResource|JsonResponse {
        $actor = $this->actor($request);
        $campus = $campusAccess->findFor($actor, $campus->id);
        $room = $campus->rooms()->whereKey($room->getKey())->firstOrFail();
        $attributes = $request->safe()->only(['name', 'capacity', 'type', 'status']);

        if (($attributes['status'] ?? null) === Room::STATUS_INACTIVE && $room->status !== Room::STATUS_INACTIVE) {
            $conflictingSessions = $room->scheduledSessions()
                ->where('status', 'scheduled')
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')
                ->get(['id', 'title', 'starts_at']);

            if ($conflictingSessions->isNotEmpty()) {
                return response()->json([
                    'message' => 'The room cannot be deactivated while future sessions are scheduled.',
                    'conflicting_sessions' => $conflictingSessions,
                ], 409);
            }
        }

        DB::transaction(function () use ($actor, $attributes, $auditLogger, $request, $room): void {
            $beforeValues = $this->snapshot($room);
            $room->update($attributes);

            $auditLogger->record(
                $actor,
                'Rooms',
                'updated',
                'room',
                $room->id,
                beforeValues: $beforeValues,
                afterValues: $this->snapshot($room->refresh()),
                request: $request,
            );
        });

        return new RoomResource($room);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /**
     * @return array{name: string, capacity: int, type: string, status: string}
     */
    private function snapshot(Room $room): array
    {
        return [
            'name' => $room->name,
            'capacity' => $room->capacity,
            'type' => $room->type,
            'status' => $room->status,
        ];
    }
}
