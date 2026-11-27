<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OTPHP\TOTP;
use Tests\TestCase;

class MfaAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', [
            'localhost',
            'localhost:5173',
            '127.0.0.1',
            '127.0.0.1:8000',
            '127.0.0.1:5173',
        ]);
    }

    public function test_sensitive_role_login_requires_mfa_before_authenticating_the_session(): void
    {
        $user = $this->userWithRole('Finance Officer');

        $this->spaPostLogin($user)
            ->assertStatus(202)
            ->assertJsonPath('data.enrollment_required', true)
            ->assertJsonMissingPath('data.email');

        $this->assertGuest('web');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/user')
            ->assertUnauthorized();
    }

    public function test_login_without_a_stateful_session_returns_an_explicit_unauthorized_response(): void
    {
        $user = User::factory()->create([
            'password' => 'CorrectPassword123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'A first-party session is required.');
    }

    public function test_user_can_enroll_totp_and_receives_single_use_recovery_codes(): void
    {
        $user = $this->userWithRole('Finance Officer');
        $this->spaPostLogin($user)->assertStatus(202);

        $response = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/enrollment/start')
            ->assertOk();
        $enrollment = $response->json('data');

        $this->assertNotEmpty($enrollment['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $enrollment['provisioning_uri']);

        $response = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/enrollment/confirm', [
                'code' => $this->totpCode($enrollment['secret']),
            ])
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes')
            ->assertJsonMissingPath('data.mfa_secret');
        $response->assertJsonMissingPath('data.mfa_pending_secret')
            ->assertJsonMissingPath('data.mfa_recovery_code_hashes');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue($user->fresh()->hasMfaEnabled());
        $this->assertNotSame(
            $enrollment['secret'],
            DB::table('users')->where('id', $user->id)->value('mfa_secret'),
        );
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Authentication',
            'action' => 'mfa_enabled',
            'entity_id' => $user->id,
        ]);
        $this->assertNotEmpty($response->json('recovery_codes'));

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/user')
            ->assertOk();
    }

    public function test_enrolled_sensitive_user_must_verify_a_fresh_totp_code_at_login(): void
    {
        $user = $this->userWithRole('Finance Officer');
        $this->enableMfa($user);
        $this->travel(30)->seconds();

        $this->spaPostLogin($user)
            ->assertStatus(202)
            ->assertJsonPath('data.enrollment_required', false);

        $code = $this->totpCode($user->fresh()->mfa_secret);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->assertAuthenticatedAs($user, 'web');
        $this->withSpaOrigin()->getJson('/api/v1/auth/user')->assertOk();

        $this->spaPostLogin($user)->assertStatus(202);
        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/verify', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The MFA verification code is invalid.');
    }

    public function test_fifth_invalid_mfa_code_locks_the_user_for_fifteen_minutes(): void
    {
        $this->travelTo('2025-01-01 12:00:00');
        $user = $this->userWithRole('Finance Officer');
        $this->enableMfa($user);
        $this->spaPostLogin($user)->assertStatus(202);
        $wrongCode = $this->wrongTotpCode($user->fresh()->mfa_secret);

        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->withSpaOrigin()
                ->postJson('/api/v1/auth/mfa/verify', ['code' => $wrongCode])
                ->assertUnprocessable();
        }

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/verify', ['code' => $wrongCode])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '900');

        $this->assertSame(5, $user->fresh()->mfa_failed_attempts);
        $this->assertSame(
            '2025-01-01 12:15:00',
            $user->fresh()->mfa_locked_until->format('Y-m-d H:i:s'),
        );
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Authentication',
            'action' => 'mfa_login_locked',
            'entity_id' => $user->id,
        ]);
        $this->assertGuest('web');

        $this->spaPostLogin($user)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '900');

        $this->travel(15)->minutes();

        $this->spaPostLogin($user)
            ->assertStatus(202)
            ->assertJsonPath('data.enrollment_required', false);

        $this->assertSame(0, $user->fresh()->mfa_failed_attempts);
        $this->assertNull($user->fresh()->mfa_locked_until);
    }

    public function test_recovery_code_authenticates_once_and_is_removed_after_use(): void
    {
        $user = $this->userWithRole('Finance Officer');
        $recoveryCodes = $this->enableMfa($user);
        $recoveryCode = $recoveryCodes[0];
        $this->spaPostLogin($user)->assertStatus(202);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/recovery', ['code' => $recoveryCode])
            ->assertOk();

        $this->assertCount(7, $user->fresh()->mfa_recovery_code_hashes);

        $this->spaPostLogin($user)->assertStatus(202);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/recovery', ['code' => $recoveryCode])
            ->assertUnprocessable();

        $this->assertCount(7, $user->fresh()->mfa_recovery_code_hashes);
    }

    public function test_sensitive_session_without_mfa_proof_is_logged_out(): void
    {
        $user = $this->userWithRole('System Administrator');

        $this->actingAs($user, 'web')
            ->withSession(['auth_mfa_verified_at' => 'invalid'])
            ->withSpaOrigin()
            ->getJson('/api/v1/auth/user')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Multi-factor authentication is required. Please sign in again.');

        $this->assertGuest('web');
    }

    public function test_regular_role_does_not_receive_an_mfa_challenge(): void
    {
        $user = $this->userWithRole('Instructor');

        $this->spaPostLogin($user)->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_mfa_code_must_contain_six_digits(): void
    {
        $user = $this->userWithRole('Finance Officer');
        $this->spaPostLogin($user)->assertStatus(202);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/enrollment/confirm', ['code' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_expired_mfa_challenge_cannot_start_enrollment(): void
    {
        $user = $this->userWithRole('Finance Officer');
        $this->spaPostLogin($user)->assertStatus(202);

        $this->travel(6)->minutes();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/enrollment/start')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'A valid MFA challenge is required.');
    }

    private function userWithRole(string $roleName): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create([
            'password' => 'CorrectPassword123',
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }

    /**
     * @return list<string>
     */
    private function enableMfa(User $user): array
    {
        $this->spaPostLogin($user)->assertStatus(202);
        $enrollment = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/enrollment/start')
            ->assertOk()
            ->json('data');

        return $this->withSpaOrigin()
            ->postJson('/api/v1/auth/mfa/enrollment/confirm', [
                'code' => $this->totpCode($enrollment['secret']),
            ])
            ->assertOk()
            ->json('recovery_codes');
    }

    private function totpCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->at(now()->timestamp);
    }

    private function wrongTotpCode(string $secret): string
    {
        $totp = TOTP::createFromSecret($secret);
        $currentStep = intdiv(now()->timestamp, 30);
        $validCodes = [];

        foreach ([$currentStep - 1, $currentStep, $currentStep + 1] as $step) {
            if ($step >= 0) {
                $validCodes[] = $totp->at($step * 30);
            }
        }

        for ($candidate = 0; $candidate < 1_000_000; $candidate++) {
            $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);

            if (! in_array($code, $validCodes, true)) {
                return $code;
            }
        }

        throw new \LogicException('A non-matching TOTP test code could not be generated.');
    }

    private function withSpaOrigin(): static
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:5173',
        ]);
    }

    private function spaPostLogin(User $user): TestResponse
    {
        return $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ]);
    }
}
