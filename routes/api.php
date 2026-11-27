<?php

use App\Http\Controllers\Api\V1\Admin\AcademicHolidayController;
use App\Http\Controllers\Api\V1\Admin\AcademicSessionController;
use App\Http\Controllers\Api\V1\Admin\AcademyProfileController;
use App\Http\Controllers\Api\V1\Admin\ApplicationController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CampusController;
use App\Http\Controllers\Api\V1\Admin\DepartmentController;
use App\Http\Controllers\Api\V1\Admin\IntakeController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\RoomController;
use App\Http\Controllers\Api\V1\Admin\SystemSettingController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\EmailChangeVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordRecoveryController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Middleware\EnforceIdleSession;
use App\Http\Middleware\EnsureAdmissionsPublicEnabled;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Middleware\RequireHttps;
use Illuminate\Support\Facades\Route;

Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware([RequireHttps::class, 'signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::get('/v1/auth/email/change/verify/{id}/{hash}', EmailChangeVerificationController::class)
    ->middleware([RequireHttps::class, 'signed', 'throttle:6,1'])
    ->name('api.v1.auth.email-change.verify');

Route::prefix('v1/auth')
    ->middleware(RequireHttps::class)
    ->name('api.v1.auth.')
    ->group(function () {
        Route::post('/admissions/applications', [ApplicationController::class, 'publicStore'])
            ->middleware(['throttle:10,1', EnsureAdmissionsPublicEnabled::class])
            ->name('admissions.public.store');

        Route::get('/invitations/{user}/{token}', [InvitationController::class, 'show'])
            ->whereNumber('user')
            ->middleware('throttle:10,1')
            ->name('invitation.show');
        Route::post('/invitations/accept', [InvitationController::class, 'accept'])
            ->middleware('throttle:6,1')
            ->name('invitation.accept');

        // Registration grants no role; authorization remains deferred to approved RBAC.
        Route::post('/register', [RegistrationController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('register');

        Route::post('/email/verification-notification', [ResendVerificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::post('/password/forgot', [PasswordRecoveryController::class, 'requestLink'])
            ->middleware('throttle:6,1')
            ->name('password.forgot');

        Route::post('/password/reset', [PasswordRecoveryController::class, 'reset'])
            ->middleware('throttle:6,1')
            ->name('password.reset');

        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login');

        Route::post('/mfa/enrollment/start', [MfaController::class, 'startEnrollment'])
            ->middleware('throttle:10,1')
            ->name('mfa.enrollment.start');
        Route::post('/mfa/enrollment/confirm', [MfaController::class, 'confirmEnrollment'])
            ->middleware('throttle:10,1')
            ->name('mfa.enrollment.confirm');
        Route::post('/mfa/verify', [MfaController::class, 'verify'])
            ->middleware('throttle:10,1')
            ->name('mfa.verify');
        Route::post('/mfa/recovery', [MfaController::class, 'verifyRecoveryCode'])
            ->middleware('throttle:10,1')
            ->name('mfa.recovery');

        Route::middleware(['auth:sanctum', EnforceIdleSession::class, EnsureMfaVerified::class])->group(function () {
            Route::get('/user', AuthenticatedUserController::class)->name('user');
            Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::get('/profile/photo', [ProfileController::class, 'showPhoto'])->name('profile.photo');
            Route::put('/password', [ProfileController::class, 'changePassword'])->name('password.change');
        });
    });

Route::prefix('v1/admin')
    ->middleware([RequireHttps::class, 'auth:sanctum', EnforceIdleSession::class, EnsureMfaVerified::class])
    ->name('api.v1.admin.')
    ->group(function () {
        Route::get('/applications', [ApplicationController::class, 'index'])
            ->middleware('permission:Admissions.View')
            ->name('applications.index');
        Route::post('/applications', [ApplicationController::class, 'store'])
            ->middleware('permission:Admissions.Create')
            ->name('applications.store');
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])
            ->whereNumber('application')
            ->middleware('permission:Admissions.View')
            ->name('applications.show');
        Route::post('/applications/{application}/review', [ApplicationController::class, 'review'])
            ->whereNumber('application')
            ->middleware('permission:Admissions.Review')
            ->name('applications.review');
        Route::post('/applications/{application}/decision', [ApplicationController::class, 'decide'])
            ->whereNumber('application')
            ->middleware('permission:Admissions.Decide')
            ->name('applications.decide');
        Route::post('/applications/{application}/admit', [ApplicationController::class, 'admit'])
            ->whereNumber('application')
            ->middleware('permission:Admissions.Admit')
            ->name('applications.admit');
        Route::post('/applications/{application}/withdraw', [ApplicationController::class, 'withdraw'])
            ->whereNumber('application')
            ->middleware('permission:Admissions.Manage')
            ->name('applications.withdraw');
        Route::post('/applications/{application}/waitlist/promote', [ApplicationController::class, 'waitlistPromote'])
            ->whereNumber('application')
            ->middleware('permission:Admissions.Decide')
            ->name('applications.waitlist.promote');

        Route::get('/users', [UserController::class, 'index'])
            ->middleware('permission:Users.Manage')
            ->name('users.index');
        Route::post('/users', [UserController::class, 'store'])
            ->middleware('permission:Users.Manage')
            ->name('users.store');
        Route::post('/users/{user}/invitation', [UserController::class, 'resendInvitation'])
            ->whereNumber('user')
            ->middleware('permission:Users.Manage')
            ->name('users.invitation.resend');
        Route::post('/users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])
            ->whereNumber('user')
            ->middleware('permission:Users.Manage')
            ->name('users.password-reset');
        Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])
            ->whereNumber('user')
            ->middleware('permission:Users.Manage')
            ->name('users.deactivate');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])
            ->whereNumber('user')
            ->middleware('permission:Users.Manage')
            ->name('users.destroy');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:Audit.View')
            ->name('audit-logs.index');
        Route::get('/audit-logs/export', [AuditLogController::class, 'export'])
            ->middleware('permission:Audit.Export')
            ->name('audit-logs.export');
        Route::put('/audit-logs/{auditLog}', [AuditLogController::class, 'denyMutation'])
            ->whereNumber('auditLog')
            ->middleware('permission:Audit.View')
            ->name('audit-logs.update');
        Route::patch('/audit-logs/{auditLog}', [AuditLogController::class, 'denyMutation'])
            ->whereNumber('auditLog')
            ->middleware('permission:Audit.View')
            ->name('audit-logs.patch');
        Route::delete('/audit-logs/{auditLog}', [AuditLogController::class, 'denyMutation'])
            ->whereNumber('auditLog')
            ->middleware('permission:Audit.View')
            ->name('audit-logs.destroy');

        Route::get('/settings', [SystemSettingController::class, 'index'])
            ->middleware('permission:Settings.Manage')
            ->name('settings.index');
        Route::get('/settings/{key}', [SystemSettingController::class, 'show'])
            ->middleware('permission:Settings.Manage')
            ->name('settings.show');
        Route::put('/settings/{key}', [SystemSettingController::class, 'update'])
            ->middleware('permission:Settings.Manage')
            ->name('settings.update');

        Route::get('/academic-holidays', [AcademicHolidayController::class, 'index'])
            ->middleware('permission:Settings.Manage')
            ->name('academic-holidays.index');
        Route::post('/academic-holidays', [AcademicHolidayController::class, 'store'])
            ->middleware('permission:Settings.Manage')
            ->name('academic-holidays.store');

        Route::get('/academic-sessions', [AcademicSessionController::class, 'index'])
            ->middleware('permission:Settings.Manage')
            ->name('academic-sessions.index');
        Route::post('/academic-sessions', [AcademicSessionController::class, 'store'])
            ->middleware('permission:Settings.Manage')
            ->name('academic-sessions.store');
        Route::patch('/academic-sessions/{academic_session}', [AcademicSessionController::class, 'update'])
            ->whereNumber('academic_session')
            ->middleware('permission:Settings.Manage')
            ->name('academic-sessions.update');

        Route::get('/intakes', [IntakeController::class, 'index'])
            ->middleware('permission:Settings.Manage')
            ->name('intakes.index');
        Route::post('/intakes', [IntakeController::class, 'store'])
            ->middleware('permission:Settings.Manage')
            ->name('intakes.store');
        Route::patch('/intakes/{intake}', [IntakeController::class, 'update'])
            ->whereNumber('intake')
            ->middleware('permission:Settings.Manage')
            ->name('intakes.update');

        Route::get('/departments', [DepartmentController::class, 'index'])
            ->middleware('permission:Settings.Manage')
            ->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])
            ->middleware('permission:Settings.Manage')
            ->name('departments.store');
        Route::patch('/departments/{department}', [DepartmentController::class, 'update'])
            ->whereNumber('department')
            ->middleware('permission:Settings.Manage')
            ->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])
            ->whereNumber('department')
            ->middleware('permission:Settings.Manage')
            ->name('departments.destroy');

        Route::get('/academy-profile', [AcademyProfileController::class, 'show'])
            ->middleware('permission:Settings.Manage')
            ->name('academy-profile.show');
        Route::put('/academy-profile', [AcademyProfileController::class, 'update'])
            ->middleware('permission:Settings.Manage')
            ->name('academy-profile.update');

        Route::get('/roles', [RoleController::class, 'index'])
            ->middleware('permission:Roles.Manage')
            ->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])
            ->middleware('permission:Roles.Manage')
            ->name('roles.store');
        Route::get('/roles/{role}', [RoleController::class, 'show'])
            ->whereNumber('role')
            ->middleware('permission:Roles.Manage')
            ->name('roles.show');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])
            ->whereNumber('role')
            ->middleware('permission:Roles.Manage')
            ->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
            ->whereNumber('role')
            ->middleware('permission:Roles.Manage')
            ->name('roles.destroy');
        Route::post('/roles/{role}/clone', [RoleController::class, 'cloneRole'])
            ->whereNumber('role')
            ->middleware('permission:Roles.Manage')
            ->name('roles.clone');

        Route::get('/permissions', [PermissionController::class, 'index'])
            ->middleware('permission:Permissions.Manage')
            ->name('permissions.index');
        Route::get('/roles/{role}/permissions', [PermissionController::class, 'showMatrix'])
            ->whereNumber('role')
            ->middleware('permission:Permissions.Manage')
            ->name('roles.permissions.show');
        Route::put('/roles/{role}/permissions', [PermissionController::class, 'updateMatrix'])
            ->whereNumber('role')
            ->middleware('permission:Permissions.Manage')
            ->name('roles.permissions.update');

        Route::get('/campuses', [CampusController::class, 'index'])
            ->middleware('permission:Settings.Manage')
            ->name('campuses.index');
        Route::post('/campuses', [CampusController::class, 'store'])
            ->middleware('permission:Settings.Manage')
            ->name('campuses.store');
        Route::get('/campuses/{campus}', [CampusController::class, 'show'])
            ->whereNumber('campus')
            ->middleware('permission:Settings.Manage')
            ->name('campuses.show');
        Route::patch('/campuses/{campus}', [CampusController::class, 'update'])
            ->whereNumber('campus')
            ->middleware('permission:Settings.Manage')
            ->name('campuses.update');

        Route::get('/campuses/{campus}/rooms', [RoomController::class, 'index'])
            ->whereNumber('campus')
            ->scopeBindings()
            ->middleware('permission:Settings.Manage')
            ->name('campuses.rooms.index');
        Route::post('/campuses/{campus}/rooms', [RoomController::class, 'store'])
            ->whereNumber('campus')
            ->scopeBindings()
            ->middleware('permission:Settings.Manage')
            ->name('campuses.rooms.store');
        Route::patch('/campuses/{campus}/rooms/{room}', [RoomController::class, 'update'])
            ->whereNumber('campus')
            ->whereNumber('room')
            ->scopeBindings()
            ->middleware('permission:Settings.Manage')
            ->name('campuses.rooms.update');
    });
