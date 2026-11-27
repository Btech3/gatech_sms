<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_signed_link_verifies_email_and_activates_the_account(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_active' => false,
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $response = $this->getJson($verificationUrl);

        $response->assertOk()
            ->assertJsonPath('message', 'Email verified. Your account is now active.');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_local_login_bypass_works_before_the_registration_email_is_verified(): void
    {
        Notification::fake();

        $spaHeaders = [
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ];

        $this->withHeaders($spaHeaders)
            ->postJson('/api/v1/auth/register', [
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'email' => 'grace@example.test',
                'password' => 'StrongPass123',
                'password_confirmation' => 'StrongPass123',
            ])->assertCreated();

        $user = User::query()->where('email', 'grace@example.test')->firstOrFail();
        $notification = Notification::sent($user, QueuedVerifyEmail::class)->first();
        $verificationUrl = $notification->toMail($user)->actionUrl;

        $this->withHeaders($spaHeaders)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'StrongPass123',
            ])->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->is_active);

        $this->getJson($verificationUrl)
            ->assertOk()
            ->assertJsonPath('message', 'Email verified. Your account is now active.');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue($user->fresh()->is_active);

        $this->withHeaders($spaHeaders)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'StrongPass123',
            ])->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_resend_endpoint_sends_a_new_link_to_an_unverified_account(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create([
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/email/verification-notification', [
            'email' => $user->email,
        ])->assertAccepted()
            ->assertJsonPath(
                'message',
                'If the address belongs to an unverified account, a verification link will be sent.',
            );

        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_resend_endpoint_returns_the_same_response_for_an_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/email/verification-notification', [
            'email' => 'unknown@example.test',
        ])->assertAccepted()
            ->assertJsonPath(
                'message',
                'If the address belongs to an unverified account, a verification link will be sent.',
            );

        Notification::assertNothingSent();
    }

    public function test_resend_endpoint_does_not_send_a_link_to_an_already_verified_account(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/email/verification-notification', [
            'email' => $user->email,
        ])->assertAccepted();

        Notification::assertNothingSent();
    }

    public function test_verification_rejects_a_tampered_signature_without_activating_the_account(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_active' => false,
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $tamperedUrl = $verificationUrl.'&signature=invalid';
        $this->getJson($tamperedUrl)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_verification_rejects_a_signed_link_for_a_different_email(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_active' => false,
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            [
                'id' => $user->id,
                'hash' => sha1('someone-else@example.test'),
            ],
        );

        $this->getJson($verificationUrl)
            ->assertForbidden()
            ->assertJsonPath('message', 'The email verification link is invalid.');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_verification_rejects_an_expired_link(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_active' => false,
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $this->getJson($verificationUrl)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->is_active);
    }
}
