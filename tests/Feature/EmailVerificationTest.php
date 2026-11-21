<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_is_redirected_to_verification_notice_from_dashboard(): void
    {
        $user = User::query()->create([
            'name' => 'Unverified User',
            'email' => 'unverified@example.test',
            'password' => 'StrongPassword123',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_signed_verification_link_marks_the_user_email_as_verified(): void
    {
        $user = User::query()->create([
            'name' => 'Unverified User',
            'email' => 'unverified@example.test',
            'password' => 'StrongPassword123',
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->actingAs($user)
            ->get($url)
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_user_can_request_another_verification_email(): void
    {
        Notification::fake();
        $user = User::query()->create([
            'name' => 'Unverified User',
            'email' => 'unverified@example.test',
            'password' => 'StrongPassword123',
        ]);

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertRedirect();

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
