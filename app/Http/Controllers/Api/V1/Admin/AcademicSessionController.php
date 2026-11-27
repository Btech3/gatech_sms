<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveAcademicSessionRequest;
use App\Http\Resources\Api\V1\Admin\AcademicSessionResource;
use App\Models\AcademicSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AcademicSessionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AcademicSessionResource::collection(
            AcademicSession::query()->withCount('intakes')->orderByDesc('starts_on')->paginate(50),
        );
    }

    public function store(SaveAcademicSessionRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $session = DB::transaction(function () use ($request, $auditLogger): AcademicSession {
            AcademicSession::query()->lockForUpdate()->get();
            $attributes = $request->validated();

            if ($attributes['is_current'] ?? false) {
                AcademicSession::query()->where('is_current', true)->update(['is_current' => false]);
            }

            $session = AcademicSession::query()->create($attributes);
            $auditLogger->record(
                $this->actor($request),
                'Settings',
                'created',
                'academic_session',
                $session->id,
                afterValues: $this->snapshot($session),
                request: $request,
            );

            return $session;
        });

        return (new AcademicSessionResource($session))->response()->setStatusCode(201);
    }

    public function update(
        SaveAcademicSessionRequest $request,
        AcademicSession $academicSession,
        AuditLogger $auditLogger,
    ): AcademicSessionResource {
        $session = DB::transaction(function () use ($request, $academicSession, $auditLogger): AcademicSession {
            AcademicSession::query()->lockForUpdate()->get();
            $beforeValues = $this->snapshot($academicSession);
            $attributes = $request->validated();

            if (($attributes['is_current'] ?? false) && ! ($attributes['is_closed'] ?? $academicSession->is_closed)) {
                AcademicSession::query()
                    ->where('is_current', true)
                    ->whereKeyNot($academicSession->id)
                    ->update(['is_current' => false]);
            }

            $academicSession->update($attributes);
            $auditLogger->record(
                $this->actor($request),
                'Settings',
                'updated',
                'academic_session',
                $academicSession->id,
                beforeValues: $beforeValues,
                afterValues: $this->snapshot($academicSession->refresh()),
                request: $request,
            );

            return $academicSession;
        });

        return new AcademicSessionResource($session->loadCount('intakes'));
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
    private function snapshot(AcademicSession $session): array
    {
        return [
            'name' => $session->name,
            'starts_on' => $session->starts_on?->toDateString(),
            'ends_on' => $session->ends_on?->toDateString(),
            'is_current' => $session->is_current,
            'is_closed' => $session->is_closed,
        ];
    }
}
