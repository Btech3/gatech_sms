<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('academy_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('singleton_id')->default(1)->unique();
            $table->string('name', 180);
            $table->string('logo_path')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('timezone', 64)->default('UTC');
            $table->string('date_format', 32)->default('Y-m-d');
            $table->longText('letterhead')->nullable();
            $table->timestamps();
        });

        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->text('address');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('capacity');
            $table->string('type', 20);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['campus_id', 'name']);
            $table->index(['campus_id', 'status']);
        });

        Schema::create('scheduled_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('title', 180);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();
            $table->index(['room_id', 'starts_at', 'status']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->json('prerequisites')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });

        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->dateTime('applications_open_at');
            $table->dateTime('applications_close_at');
            $table->unsignedInteger('capacity');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['academic_session_id', 'campus_id', 'name']);
            $table->index(['is_active', 'applications_open_at', 'applications_close_at']);
        });

        Schema::create('academic_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->date('holiday_date');
            $table->timestamps();
            $table->index(['campus_id', 'holiday_date']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 80);
            $table->string('key', 120)->unique();
            $table->json('value');
            $table->timestamps();
            $table->index('group');
        });

        DB::table('system_settings')->insert([
            'group' => 'General',
            'key' => 'audit.retention_years',
            'value' => json_encode(7, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('academic_holidays');
        Schema::dropIfExists('intakes');
        Schema::dropIfExists('academic_sessions');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('scheduled_sessions');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('campuses');
        Schema::dropIfExists('academy_profiles');
    }
};
