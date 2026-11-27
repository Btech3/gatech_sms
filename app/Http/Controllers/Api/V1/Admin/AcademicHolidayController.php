<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveAcademicHolidayRequest;
use App\Http\Resources\Api\V1\Admin\AcademicHolidayResource;
use App\Models\AcademicHoliday;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\CampusAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AcademicHolidayController extends Controller
{
    public function index(Request $request, CampusAccess $campusAccess): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $campusIds = $campusAccess->queryFor($actor)->select('campuses.id');

        return AcademicHolidayResource::collection(
            AcademicHoliday::query()
                ->where(function ($query) use ($campusIds, $actor): void {
                    if ($actor->isBootstrapAdministrator()) {
                        return;
                    }

                    $query->whereNull('campus_id')->orWhereIn('campus_id', $campusIds);
                })
                ->orderBy('holiday_date')
                ->paginate(50),
        );
    }

    public function store(
        SaveAcademicHolidayRequest $request,
        CampusAccess $campusAccess,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $attributes = $request->validated();
        $campusId = $attributes['campus_id'] ?? null;

        if ($campusId !== null) {
            $campusAccess->findFor($actor, $campusId);
        }

        $holiday = DB::transaction(function () use ($actor, $attributes, $auditLogger, $request): AcademicHoliday {
            $holiday = AcademicHoliday::query()->create($attributes);
            $auditLogger->record(
                $actor,
                'Settings',
                'created',
                'academic_holiday',
                $holiday->id,
                afterValues: $this->snapshot($holiday),
                request: $request,
            );

            return $holiday;
        });

        return (new AcademicHolidayResource($holiday))->response()->setStatusCode(201);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AcademicHoliday $holiday): array
    {
        return [
            'academic_session_id' => $holiday->academic_session_id,
            'campus_id' => $holiday->campus_id,
            'name' => $holiday->name,
            'holiday_date' => $holiday->holiday_date?->toDateString(),
        ];
    }
}
