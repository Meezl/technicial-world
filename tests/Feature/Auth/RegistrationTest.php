<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '0712345678',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice', absolute: false));
    }

    /**
     * Registration must send the verification email and nothing else. The
     * welcome email used to go out here too; two stock-template emails
     * seconds apart read as one duplicate, which clients reported as the
     * verification email arriving twice. It now fires on Verified instead.
     */
    public function test_registration_sends_only_the_verification_email(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '0712345678',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'test@example.com')->firstOrFail();

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        Notification::assertNotSentTo($user, WelcomeNotification::class);
    }
}
