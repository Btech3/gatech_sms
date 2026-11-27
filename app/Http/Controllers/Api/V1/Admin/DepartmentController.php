<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveDepartmentRequest;
use App\Http\Resources\Api\V1\Admin\DepartmentResource;
use App\Models\Department;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DepartmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DepartmentResource::collection(
            Department::query()->withCount(['courses', 'users'])->orderBy('name')->paginate(50),
        );
    }

    public function store(SaveDepartmentRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $department = DB::transaction(function () use ($request, $auditLogger): Department {
            $department = Department::query()->create($request->validated());
            $auditLogger->record(
                $this->actor($request),
                'Settings',
                'created',
                'department',
                $department->id,
                afterValues: $this->snapshot($department),
                request: $request,
            );

            return $department;
        });

        return (new DepartmentResource($department))->response()->setStatusCode(201);
    }

    public function update(
        SaveDepartmentRequest $request,
        Department $department,
        AuditLogger $auditLogger,
    ): DepartmentResource {
        DB::transaction(function () use ($request, $department, $auditLogger): void {
            $beforeValues = $this->snapshot($department);
            $department->update($request->validated());
            $auditLogger->record(
                $this->actor($request),
                'Settings',
                'updated',
                'department',
                $department->id,
                beforeValues: $beforeValues,
                afterValues: $this->snapshot($department->refresh()),
                request: $request,
            );
        });

        return new DepartmentResource($department->loadCount(['courses', 'users']));
    }

    public function destroy(
        Request $request,
        Department $department,
        AuditLogger $auditLogger,
    ): Response {
        return DB::transaction(function () use ($request, $department, $auditLogger): Response {
            $department = Department::query()
                ->whereKey($department->id)
                ->lockForUpdate()
                ->firstOrFail();
            $counts = [
                'courses' => $department->courses()->count(),
                'users' => $department->users()->count(),
            ];

            if (array_sum($counts) > 0) {
                return response()->json([
                    'message' => 'A department assigned to courses or users cannot be deleted.',
                    'usage' => $counts,
                ], 409);
            }

            $beforeValues = $this->snapshot($department);
            $departmentId = $department->id;
            $department->delete();
            $auditLogger->record(
                $this->actor($request),
                'Settings',
                'deleted',
                'department',
                $departmentId,
                beforeValues: $beforeValues,
                request: $request,
            );

            return response()->noContent();
        });
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
    private function snapshot(Department $department): array
    {
        return [
            'name' => $department->name,
            'description' => $department->description,
            'is_active' => $department->is_active,
        ];
    }
}
