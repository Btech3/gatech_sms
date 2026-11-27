<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('locked_until');
            $table->timestamp('mfa_enabled_at')->nullable()->after('mfa_secret');
            $table->text('mfa_pending_secret')->nullable()->after('mfa_enabled_at');
            $table->timestamp('mfa_pending_expires_at')->nullable()->after('mfa_pending_secret');
            $table->unsignedBigInteger('mfa_last_used_timestep')->nullable()->after('mfa_pending_expires_at');
            $table->unsignedTinyInteger('mfa_failed_attempts')->default(0)->after('mfa_last_used_timestep');
            $table->timestamp('mfa_locked_until')->nullable()->after('mfa_failed_attempts');
            $table->text('mfa_recovery_code_hashes')->nullable()->after('mfa_locked_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'mfa_secret',
                'mfa_enabled_at',
                'mfa_pending_secret',
                'mfa_pending_expires_at',
                'mfa_last_used_timestep',
                'mfa_failed_attempts',
                'mfa_locked_until',
                'mfa_recovery_code_hashes',
            ]);
        });
    }
};
