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
            $table->string('phone', 30)->nullable()->after('last_name');
            $table->string('profile_photo_path')->nullable()->after('phone');
            $table->string('pending_email')->nullable()->after('email');
            $table->unique('pending_email', 'users_pending_email_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_pending_email_unique');
            $table->dropColumn(['phone', 'profile_photo_path', 'pending_email']);
        });
    }
};
