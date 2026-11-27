<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateAcademyProfileRequest;
use App\Http\Resources\Api\V1\Admin\AcademyProfileResource;
use App\Models\AcademyProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AcademyProfileController extends Controller
{
    public function show(): AcademyProfileResource
    {
        return new AcademyProfileResource(AcademyProfile::query()->first());
    }

    public function update(
        UpdateAcademyProfileRequest $request,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $profile = AcademyProfile::query()->first();
        $oldLogoPath = $profile?->logo_path;
        $newLogoPath = null;
        $attributes = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            $newLogoPath = $request->file('logo')->store('academy', 'public');
            $attributes['logo_path'] = $newLogoPath;
        }

        try {
            $profile = DB::transaction(function () use ($profile, $attributes, $actor, $auditLogger, $request) {
                $beforeValues = $profile ? $this->snapshot($profile) : null;
                $profile = AcademyProfile::query()->updateOrCreate(
                    ['singleton_id' => 1],
                    $attributes,
                );

                $auditLogger->record(
                    $actor,
                    'Settings',
                    $beforeValues === null ? 'created' : 'updated',
                    'academy_profile',
                    $profile->id,
                    beforeValues: $beforeValues,
                    afterValues: $this->snapshot($profile->refresh()),
                    request: $request,
                );

                return $profile;
            });
        } catch (\Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }

        if ($newLogoPath !== null && $oldLogoPath !== null) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return (new AcademyProfileResource($profile))
            ->response()
            ->setStatusCode(200);
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
    private function snapshot(AcademyProfile $profile): array
    {
        return [
            'name' => $profile->name,
            'logo_path' => $profile->logo_path,
            'address' => $profile->address,
            'phone' => $profile->phone,
            'email' => $profile->email,
            'registration_number' => $profile->registration_number,
            'currency' => $profile->currency,
            'timezone' => $profile->timezone,
            'date_format' => $profile->date_format,
            'letterhead' => $profile->letterhead,
        ];
    }
}
