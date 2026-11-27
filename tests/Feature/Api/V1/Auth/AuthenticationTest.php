<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\Auth\LoginOutcome;
use App\Models\User;
use App\Services\Auth\AuthenticateUser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthenticationTest extends TestCase
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

    public function test_login_authenticates_the_user_and_returns_only_public_identity_fields(): void
    {
        $user = User::factory()->create([
            'password' => 'CorrectPassword123',
        ]);

        $response = $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ]);

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'first_name' => null,
                    'last_name' => null,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => null,
                    'profile_photo_url' => null,
                    'email_change_pending' => false,
                ],
            ]);

        $this->assertAuthenticatedAs($user, 'web');

        $userResponse = $this->withSpaOrigin()
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'first_name' => null,
                    'last_name' => null,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => null,
                    'profile_photo_url' => null,
                    'email_change_pending' => false,
                ],
            ]);

        $this->assertSame('no-store, private', $userResponse->headers->get('Cache-Control'));
    }

    public function test_login_returns_the_generic_error_for_an_existing_email_with_the_wrong_password(): void
    {
        $user = User::factory()->create();

        $response = $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'WrongPassword123',
        ]);

        $this->assertGenericLoginFailure($response);
    }

    public function test_login_returns_the_same_generic_error_for_an_unknown_email(): void
    {
        $response = $this->spaPostLogin([
            'email' => 'unknown@example.test',
            'password' => 'WrongPassword123',
        ]);

        $this->assertGenericLoginFailure($response);
    }

    public function test_fifth_failed_login_locks_the_account_for_fifteen_minutes(): void
    {
        $this->travelTo('2025-01-01 12:00:00');
        $user = User::factory()->create([
            'password' => 'CorrectPassword123',
        ]);

        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->spaPostLogin([
                'email' => $user->email,
                'password' => 'WrongPassword123',
            ])->assertUnprocessable();
        }

        $fifthAttempt = $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'WrongPassword123',
        ]);

        $fifthAttempt->assertTooManyRequests()
            ->assertHeader('Retry-After', '900');

        $this->assertSame(5, $user->fresh()->failed_login_attempts);
        $this->assertSame(
            '2025-01-01 12:15:00',
            $user->fresh()->locked_until->format('Y-m-d H:i:s'),
        );

        $this->travel(1)->minutes();

        $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertTooManyRequests()
            ->assertHeader('Retry-After', '840');

        $this->travel(14)->minutes();

        $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertNull($user->fresh()->locked_until);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
        ]);

        $response = $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGenericLoginFailure($response);
        $this->assertGuest('web');
    }

    public function test_unverified_inactive_user_can_log_in_only_through_the_local_testing_bypass(): void
    {
        $user = User::factory()->unverified()->create([
            'is_active' => false,
            'password' => 'CorrectPassword123',
        ]);

        config()->set('auth.allow_unverified_login', true);

        $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertOk();

        config()->set('auth.allow_unverified_login', false);

        $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are invalid.');
    }

    public function test_unverified_login_bypass_is_rejected_in_production_even_if_configured(): void
    {
        $user = User::factory()->unverified()->create([
            'is_active' => false,
            'password' => 'CorrectPassword123',
        ]);

        config()->set('auth.allow_unverified_login', true);
        $this->app->detectEnvironment(fn () => 'production');

        $result = app(AuthenticateUser::class)->attempt($user->email, 'CorrectPassword123');

        $this->assertSame(LoginOutcome::InvalidCredentials, $result->outcome);
    }

    public function test_authentication_state_cannot_be_changed_through_mass_assignment(): void
    {
        $user = User::factory()->create();

        $user->fill([
            'is_active' => false,
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ])->save();

        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertNull($user->fresh()->locked_until);
    }

    public function test_current_user_endpoint_returns_401_without_authentication(): void
    {
        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/user')
            ->assertUnauthorized();
    }

    public function test_current_user_endpoint_returns_json_401_without_an_accept_header(): void
    {
        $this->get('/api/v1/auth/user')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create([
            'password' => 'CorrectPassword123',
        ]);

        $this->spaPostLogin([
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertOk();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertGuest('web');

        // The in-process test client reuses guards between simulated HTTP requests.
        Auth::forgetGuards();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/user')
            ->assertUnauthorized();
    }

    public function test_csrf_cookie_endpoint_is_available_under_the_versioned_auth_prefix(): void
    {
        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_authentication_endpoints_require_https_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'WrongPassword123',
        ])->assertStatus(400)
            ->assertExactJson([
                'message' => 'Secure transport is required.',
            ]);
    }

    /**
     * Send API requests as a first-party Vue SPA so Sanctum applies session middleware.
     */
    private function withSpaOrigin(): static
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:5173',
        ]);
    }

    /**
     * Attempt a login using the first-party SPA request headers.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    private function spaPostLogin(array $credentials): TestResponse
    {
        return $this->withSpaOrigin()
            ->postJson('/api/v1/auth/login', $credentials);
    }

    private function assertGenericLoginFailure(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are invalid.')
            ->assertJsonPath('status', false);
    }
}
