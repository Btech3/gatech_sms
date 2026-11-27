<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AcademyProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AcademyProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Group('TC-ADN-004-P')]
    public function test_admin_can_update_academy_profile_and_logo(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $response = $this->actingAs($administrator, 'web')
            ->putJson('/api/v1/admin/academy-profile', [
                'name' => 'Gatech Academy',
                'address' => '1 School Road',
                'email' => 'office@example.test',
                'currency' => 'NGN',
                'timezone' => 'Africa/Lagos',
                'date_format' => 'd/m/Y',
                'letterhead' => 'Gatech Academy',
                'logo' => UploadedFile::fake()->createWithContent(
                    'logo.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                ),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Gatech Academy')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.timezone', 'Africa/Lagos');

        $profile = AcademyProfile::query()->firstOrFail();
        $this->assertSame(1, AcademyProfile::query()->count());
        $this->assertStringContainsString('academy/', $response->json('data.logo_url'));
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'academy_profile',
            'entity_id' => $profile->id,
            'action' => 'created',
        ]);
        Storage::disk('public')->assertExists($profile->logo_path);
    }

    #[Group('TC-ADN-004-N')]
    public function test_invalid_logo_and_currency_are_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->userWithRole('System Administrator');

        $this->actingAs($administrator, 'web')
            ->putJson('/api/v1/admin/academy-profile', [
                'name' => 'Gatech Academy',
                'currency' => 'US Dollars',
                'logo' => UploadedFile::fake()->create('logo.gif', 5120, 'image/gif'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency', 'logo']);

        $this->assertDatabaseCount('academy_profiles', 0);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
