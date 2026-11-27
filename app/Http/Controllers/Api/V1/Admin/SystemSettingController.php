<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSystemSettingRequest;
use App\Http\Resources\Api\V1\Admin\SystemSettingResource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SystemSettingsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SystemSettingController extends Controller
{
    public function index(SystemSettingsCatalog $catalog): AnonymousResourceCollection
    {
        return SystemSettingResource::collection(
            SystemSetting::query()
                ->whereIn('key', array_keys($catalog->definitions()))
                ->orderBy('group')
                ->orderBy('key')
                ->paginate(50),
        );
    }

    public function update(
        UpdateSystemSettingRequest $request,
        string $key,
        SystemSettingsCatalog $catalog,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $definition = $catalog->definition($key);
        $value = $request->validated('value');
        $setting = DB::transaction(function () use ($key, $definition, $value, $actor, $auditLogger, $request): SystemSetting {
            $setting = SystemSetting::query()->lockForUpdate()->firstOrNew(['key' => $key]);
            $beforeValues = $setting->exists ? ['value' => $setting->value] : null;
            $setting->fill([
                'group' => $definition['group'],
                'value' => $value,
            ])->save();

            $auditLogger->record(
                $actor,
                'Settings',
                $beforeValues === null ? 'created' : 'updated',
                'system_setting',
                $setting->id,
                beforeValues: $beforeValues,
                afterValues: ['key' => $key, 'group' => $setting->group, 'value' => $setting->value],
                request: $request,
            );

            return $setting;
        });

        return (new SystemSettingResource($setting))
            ->response()
            ->setStatusCode(200);
    }

    public function show(string $key, SystemSettingsCatalog $catalog): SystemSettingResource
    {
        $catalog->definition($key);

        return new SystemSettingResource(SystemSetting::query()->where('key', $key)->firstOrFail());
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
