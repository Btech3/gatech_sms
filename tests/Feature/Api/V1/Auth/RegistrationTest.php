<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_registration_creates_an_inactive_unverified_account_and_queues_verification_email(): void
    {
        Notification::fake();

        $response = $this->register();
        $user = User::query()->where('email', 'ada@example.test')->firstOrFail();

        $response->assertCreated()
            ->assertJsonPath(
                'message',
                'Registration created. Verification email queued; account remains inactive until verified.',
            )
            ->assertJsonPath('data.first_name', 'Ada')
            ->assertJsonPath('data.last_name', 'Lovelace')
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.email', 'ada@example.test');

        $this->assertModelExists($user);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_registration_queues_verification_without_blocking_the_http_response(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => false,
        ]);

        $response = $this->register();
        $user = User::query()->where('email', 'ada@example.test')->firstOrFail();

        $response->assertCreated()
            ->assertJsonPath(
                'message',
                'Registration created. Verification email queued; account remains inactive until verified.',
            );

        $this->assertModelExists($user);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_registration_rolls_back_when_verification_cannot_be_queued(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => false,
            'queue.connections.database.table' => 'missing_registration_jobs',
        ]);

        $response = $this->register();

        $response->assertServerError();
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.test']);
    }

    public function test_registration_requires_every_account_field(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'email',
                'password',
                'password_confirmation',
            ]);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_an_invalid_email_address(): void
    {
        $response = $this->register(['email' => 'not-an-email']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_a_duplicate_email_without_creating_another_user(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'ada@example.test',
        ]);

        $response = $this->register();

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
        $this->assertModelExists($existingUser);
    }

    public function test_email_is_normalized_before_duplicate_account_validation(): void
    {
        User::factory()->create([
            'email' => 'ada@example.test',
        ]);

        $response = $this->register([
            'email' => 'ADA@EXAMPLE.TEST',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Aa1aaaa'],
            'missing uppercase' => ['lowercase123'],
            'missing lowercase' => ['UPPERCASE123'],
            'missing number' => ['NoNumbersHere'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_registration_rejects_passwords_that_do_not_meet_the_approved_policy(string $password): void
    {
        $response = $this->register([
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_a_password_confirmation_mismatch(): void
    {
        $response = $this->register([
            'password_confirmation' => 'DifferentPassword123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_hashes_the_password_before_persisting_it(): void
    {
        Notification::fake();

        $this->register();
        $user = User::query()->where('email', 'ada@example.test')->firstOrFail();

        $this->assertNotSame('StrongPass123', $user->password);
        $this->assertTrue(Hash::check('StrongPass123', $user->password));
    }

    public function test_registration_rejects_privileged_role_and_account_state_manipulation(): void
    {
        $response = $this->register([
            'role' => 'administrator',
            'role_id' => 1,
            'is_admin' => true,
            'permissions' => ['*'],
            'is_active' => true,
            'email_verified_at' => now()->toISOString(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'role',
                'role_id',
                'is_admin',
                'permissions',
                'is_active',
                'email_verified_at',
            ]);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_registration_submissions_never_create_multiple_accounts(): void
    {
        Notification::fake();

        $firstResponse = $this->register();
        $secondResponse = $this->register();

        $firstResponse->assertCreated();
        $secondResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_response_excludes_passwords_hashes_tokens_and_privileged_fields(): void
    {
        Notification::fake();

        $response = $this->register();
        $responseData = $response->json('data');

        $this->assertSame(
            [
                'id',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'profile_photo_url',
                'email_change_pending',
            ],
            array_keys($responseData),
        );
        $this->assertStringNotContainsString('StrongPass123', $response->getContent());
        $this->assertStringNotContainsString('$2y$', $response->getContent());
        $this->assertStringNotContainsString('remember_token', $response->getContent());
        $this->assertStringNotContainsString('administrator', $response->getContent());
    }

    /**
     * Submit a valid first-party SPA registration payload, optionally overriding fields.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function register(array $overrides = []): TestResponse
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/register', array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
        ], $overrides));
    }
}
