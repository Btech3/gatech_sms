<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\FilterAuditLogRequest;
use App\Http\Resources\Api\V1\Admin\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(FilterAuditLogRequest $request): AnonymousResourceCollection
    {
        return AuditLogResource::collection(
            $this->query($request->validated())
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 50)),
        );
    }

    public function export(
        FilterAuditLogRequest $request,
        AuditLogger $auditLogger,
    ): StreamedResponse {
        $actor = $this->actor($request);
        $auditLogger->record(
            $actor,
            'Audit',
            'exported',
            'audit_log',
            null,
            afterValues: ['filters' => $request->validated()],
            request: $request,
        );

        return response()->streamDownload(function () use ($request): void {
            $output = fopen('php://output', 'w');
            if (! is_resource($output)) {
                throw new \RuntimeException('Unable to open the audit log export stream.');
            }

            fputcsv($output, [
                'id',
                'user_id',
                'module',
                'action',
                'entity_type',
                'entity_id',
                'before_values',
                'after_values',
                'ip_address',
                'user_agent',
                'created_at',
            ]);

            $this->query($request->validated())
                ->orderBy('id')
                ->lazyById(500)
                ->each(function (AuditLog $log) use ($output): void {
                    fputcsv($output, [
                        $log->id,
                        $log->user_id,
                        $log->module,
                        $log->action,
                        $log->entity_type,
                        $log->entity_id,
                        json_encode($log->before_values, JSON_THROW_ON_ERROR),
                        json_encode($log->after_values, JSON_THROW_ON_ERROR),
                        $log->ip_address,
                        $log->user_agent,
                        $log->created_at?->toIso8601String(),
                    ]);
                });

            fclose($output);
        }, 'audit-logs.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function denyMutation(
        Request $request,
        AuditLog $auditLog,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $auditLogger->record(
            $this->actor($request),
            'Audit',
            'mutation_denied',
            'audit_log',
            $auditLog->id,
            request: $request,
        );

        return response()->json(['message' => 'Audit log entries are immutable.'], 405);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->when(isset($filters['user_id']), fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['module']), fn (Builder $query) => $query->where('module', $filters['module']))
            ->when(isset($filters['action']), fn (Builder $query) => $query->where('action', $filters['action']))
            ->when(isset($filters['entity_type']), fn (Builder $query) => $query->where('entity_type', $filters['entity_type']))
            ->when(isset($filters['entity_id']), fn (Builder $query) => $query->where('entity_id', $filters['entity_id']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['to']));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
