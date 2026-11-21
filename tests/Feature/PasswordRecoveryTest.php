<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_is_available_to_guests(): void
    {
        $this->get('/forgot-password')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_existing_account_receives_a_reset_link_without_disclosing_account_existence(): void
    {
        Notification::fake();
        $user = User::query()->create([
            'name' => 'Password User',
            'email' => 'password@example.test',
            'password' => 'OldPassword123',
        ]);

        $this->from('/forgot-password')->post('/forgot-password', [
            'email' => 'password@example.test',
        ])->assertSessionHas('status', 'If an account matches that email address, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_rejects_an_invalid_token(): void
    {
        User::query()->create([
            'name' => 'Password User',
            'email' => 'password@example.test',
            'password' => 'OldPassword123',
        ]);

        $this->from('/reset-password/invalid')->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => 'password@example.test',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertSessionHasErrors('email');

    }
}
