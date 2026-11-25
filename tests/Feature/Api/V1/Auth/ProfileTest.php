<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Notifications\Auth\EmailChangeVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
        config()->set('sanctum.stateful', [
            'localhost',
            'localhost:5173',
            '127.0.0.1',
            '127.0.0.1:8000',
            '127.0.0.1:5173',
        ]);
    }

    public function test_authenticated_user_can_update_their_name_and_phone(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'name' => 'Ada Lovelace',
        ]);

        $this->spaAs($user)
            ->patchJson('/api/v1/auth/profile', [
                'first_name' => 'Grace',
                'phone' => '+1 555 0100',
            ])->assertOk()
            ->assertJsonPath('data.first_name', 'Grace')
            ->assertJsonPath('data.name', 'Grace Lovelace')
            ->assertJsonPath('data.phone', '+1 555 0100')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame('Grace', $user->fresh()->first_name);
        $this->assertSame('Grace Lovelace', $user->fresh()->name);
    }

    public function test_email_change_waits_for_a_signed_link_to_the_new_address(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'old@example.test',
            'is_active' => false,
        ]);

        $this->spaAs($user)
            ->patchJson('/api/v1/auth/profile', [
                'email' => 'new@example.test',
            ])->assertOk()
            ->assertJsonPath('data.email', 'old@example.test')
            ->assertJsonPath('data.email_change_pending', true);

        $this->assertSame('old@example.test', $user->fresh()->email);
        $this->assertSame('new@example.test', $user->fresh()->pending_email);
        $verificationUrl = null;
        Notification::assertSentOnDemand(
            EmailChangeVerification::class,
            function (
                EmailChangeVerification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ) use (&$verificationUrl): bool {
                $this->assertSame('new@example.test', $notifiable->routes['mail']);
                $this->assertContains('mail', $channels);
                $verificationUrl = $notification->toMail($notifiable)->actionUrl;

                return true;
            },
        );
        $this->assertIsString($verificationUrl);

        $this->getJson($verificationUrl)->assertOk()
            ->assertJsonPath('message', 'Your new email address has been verified.');

        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->pending_email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->is_active);

        $this->getJson($verificationUrl)->assertForbidden();
    }

    public function test_profile_cannot_change_email_to_an_existing_or_pending_address(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'used@example.test']);
        User::factory()->create()->forceFill(['pending_email' => 'reserved@example.test'])->save();

        foreach (['used@example.test', 'reserved@example.test'] as $email) {
            $this->spaAs($user)
                ->patchJson('/api/v1/auth/profile', ['email' => $email])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        }
    }

    public function test_unverified_account_must_verify_its_current_email_before_requesting_an_email_change(): void
    {
        $user = User::factory()->unverified()->create([
            'is_active' => false,
        ]);

        $this->spaAs($user)
            ->patchJson('/api/v1/auth/profile', [
                'email' => 'new@example.test',
            ])->assertForbidden();

        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_authenticated_user_can_upload_and_retrieve_a_private_profile_photo(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $validPng = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/rWQAAAAASUVORK5CYII=',
        );

        $response = $this->spaAs($user)
            ->patchJson('/api/v1/auth/profile', [
                'profile_photo' => UploadedFile::fake()->createWithContent('avatar.png', $validPng),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.profile_photo_url', route('api.v1.auth.profile.photo'));

        $path = $user->fresh()->profile_photo_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        $this->spaAs($user)
            ->get('/api/v1/auth/profile/photo')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_profile_rejects_role_and_account_state_changes(): void
    {
        $user = User::factory()->create();

        $this->spaAs($user)
            ->patchJson('/api/v1/auth/profile', [
                'role' => 'administrator',
                'role_id' => 1,
                'is_admin' => true,
                'is_active' => false,
                'pending_email' => 'attacker@example.test',
                'profile_photo_path' => 'public/attacker.png',
                'remember_token' => 'attacker-token',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'role',
                'role_id',
                'is_admin',
                'is_active',
                'pending_email',
                'profile_photo_path',
                'remember_token',
            ]);
    }

    public function test_password_change_requires_the_current_password_and_invalidates_other_sessions(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword123',
        ]);
        DB::table('sessions')->insert([
            'id' => 'other-browser',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->spaAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'WrongPassword123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->spaAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'OldPassword123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser']);
    }

    public function test_password_change_rejects_a_weak_or_unconfirmed_new_password(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword123',
        ]);

        $this->spaAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'OldPassword123',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->spaAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'OldPassword123',
                'password' => 'StrongPass123',
                'password_confirmation' => 'DifferentPass123',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }

    public function test_idle_session_expires_before_a_protected_profile_change_is_applied(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $this->spaAs($user)
            ->withSession(['auth_last_activity_at' => now()->subMinutes(31)->timestamp])
            ->patchJson('/api/v1/auth/profile', ['first_name' => 'Changed'])
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame('Ada', $user->fresh()->first_name);
    }

    public function test_profile_and_password_endpoints_require_authentication(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->patchJson('/api/v1/auth/profile', ['first_name' => 'Grace'])
            ->assertUnauthorized();

        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->putJson('/api/v1/auth/password', [])
            ->assertUnauthorized();
    }

    private function spaAs(User $user): static
    {
        return $this->actingAs($user, 'web')->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ]);
    }
}
