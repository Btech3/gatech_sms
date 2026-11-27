<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:bootstrap-system-administrator {email : Email of an existing active, verified user}')]
#[Description('Grant the reserved System Administrator role to one existing verified user')]
class BootstrapSystemAdministrator extends Command
{
    public function handle(RolePermissionSeeder $seeder, AuditLogger $auditLogger): int
    {
        $existingBootstrapRole = Role::query()
            ->where('is_bootstrap', true)
            ->first();

        if ($existingBootstrapRole?->users()->exists()) {
            $this->error('A System Administrator has already been bootstrapped.');

            return self::FAILURE;
        }

        $seeder->run();
        $email = Str::lower(trim((string) $this->argument('email')));

        return DB::transaction(function () use ($email, $auditLogger): int {
            $role = Role::query()
                ->where('is_bootstrap', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($role->users()->exists()) {
                $this->error('A System Administrator has already been bootstrapped.');

                return self::FAILURE;
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                $this->error('No existing account with that email was found.');

                return self::FAILURE;
            }

            if (! $user->is_active || ! $user->hasVerifiedEmail()) {
                $this->error('The account must be active and email-verified before bootstrap.');

                return self::FAILURE;
            }

            $role->users()->attach($user);
            $auditLogger->record(
                null,
                'Authorization',
                'system_administrator_bootstrapped',
                'user',
                $user->id,
                afterValues: ['role' => $role->name],
            );

            $this->info('System Administrator access has been assigned.');

            return self::SUCCESS;
        });
    }
}
