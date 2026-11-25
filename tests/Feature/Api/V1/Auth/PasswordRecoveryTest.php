<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PasswordRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    public function test_known_and_unknown_email_receive_the_same_reset_link_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $knownResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);
        $unknownResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'unknown@example.test',
        ]);

        $knownResponse->assertAccepted();
        $unknownResponse->assertAccepted();
        $this->assertSame($knownResponse->json(), $unknownResponse->json());
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame(30, config('auth.passwords.users.expire'));

        $notification = Notification::sent($user, ResetPassword::class)->first();
        $resetUrl = $notification->toMail($user)->actionUrl;
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?', $resetUrl);
        $this->assertSame($notification->token, $query['token']);
        $this->assertSame($user->email, $query['email']);
    }

    public function test_password_reset_changes_the_password_invalidates_all_sessions_and_token_is_one_time(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password' => 'OldPassword123',
            'remember_token' => 'old-remember-token',
        ]);

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertAccepted();

        $token = Notification::sent($user, ResetPassword::class)->first()->token;
        $this->createSession($user, 'browser-one');
        $this->createSession($user, 'browser-two');

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ];

        $this->postJson('/api/v1/auth/password/reset', $payload)
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Your password has been reset. Please sign in with your new password.',
            );

        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'browser-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'browser-two']);

        $this->postJson('/api/v1/auth/password/reset', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The password reset link is invalid or expired.');
    }

    public function test_password_reset_link_expires_after_thirty_minutes(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertAccepted();

        $token = Notification::sent($user, ResetPassword::class)->first()->token;
        $this->travel(31)->minutes();

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertUnprocessable();
    }

    public function test_password_reset_rejects_weak_and_mismatched_passwords(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => 'not-a-token',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => 'not-a-token',
            'password' => 'StrongPass123',
            'password_confirmation' => 'DifferentPass123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_link_request_validates_email_format(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'not-an-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    private function createSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}
