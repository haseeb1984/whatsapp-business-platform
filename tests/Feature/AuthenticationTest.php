<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_user_and_an_isolated_business_workspace(): void
    {
        $response = $this->post('/register', [
            'name' => 'Business Owner',
            'email' => 'owner@example.test',
            'business_name' => 'Example Studio',
            'timezone' => 'Asia/Karachi',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ]);

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $tenant = Tenant::query()->where('slug', 'example-studio')->firstOrFail();

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($tenant->users()->whereKey($user->id)->wherePivot('role', 'business_admin')->exists());
        $this->assertSame('Asia/Karachi', $tenant->timezone);
    }

    public function test_registration_rejects_weak_passwords_and_duplicate_email_addresses(): void
    {
        User::query()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => 'ExistingPassword123',
        ]);

        $this->from('/register')->post('/register', [
            'name' => 'Another User',
            'email' => 'existing@example.test',
            'business_name' => 'Another Studio',
            'timezone' => 'Asia/Karachi',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_login_regenerates_session_and_logout_invalidates_it(): void
    {
        $user = User::query()->create([
            'name' => 'Business Owner',
            'email' => 'owner@example.test',
            'password' => 'StrongPassword123',
        ]);
        $tenant = Tenant::query()->create(['name' => 'Example Studio', 'slug' => 'example-studio']);
        $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->post('/login', [
            'email' => 'owner@example.test',
            'password' => 'StrongPassword123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
