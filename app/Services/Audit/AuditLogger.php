<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $beforeValues
     * @param  array<string, mixed>|null  $afterValues
     */
    public function record(
        ?User $actor,
        string $module,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $beforeValues = null,
        ?array $afterValues = null,
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $actor?->getKey(),
            'module' => $module,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_values' => $this->removeSecrets($beforeValues),
            'after_values' => $this->removeSecrets($afterValues),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function removeSecrets(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|authorization|remember.?token/i', (string) $key) === 1) {
                unset($values[$key]);

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->removeSecrets($value);
            }
        }

        return $values;
    }
}
