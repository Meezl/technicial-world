<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountCredentialsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin-created accounts get a generated password, mailed to the user.
 *
 * The point is not convenience. An admin inventing a password has to convey it
 * by some channel outside the system, which in practice means WhatsApp, and
 * that password then stays valid indefinitely. Generating it puts the delivery
 * on a recorded channel and makes it single use.
 */
class GeneratedAccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function createUserAs(User $admin, array $overrides = [])
    {
        return $this->actingAs($admin)->post(route('admin.users.store'), array_merge([
            'name' => 'Grace Wanjiru',
            'email' => 'grace@example.com',
            'role' => User::ROLE_OFFICE,
            'phone' => '254712345678',
        ], $overrides));
    }

    public function test_an_admin_creates_a_user_without_choosing_a_password(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->createUserAs($admin)->assertRedirect(route('admin.users'));

        $user = User::where('email', 'grace@example.com')->sole();
        $this->assertSame(User::ROLE_OFFICE, $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertNotEmpty($user->password);
    }

    public function test_the_generated_password_is_emailed_to_the_user_and_actually_works(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->createUserAs($admin);
        $user = User::where('email', 'grace@example.com')->sole();

        Notification::assertSentTo($user, AccountCredentialsNotification::class,
            function (AccountCredentialsNotification $notification) use ($user) {
                $mail = $notification->toMail($user);

                // The password in the mail must be the one on the account —
                // a credentials mail that does not sign you in is worse than
                // no mail at all.
                $sent = collect($mail->introLines)
                    ->first(fn ($line) => str_contains($line, 'Temporary password:'));
                $this->assertNotNull($sent);

                $password = trim(str_replace(['**Temporary password:**', '**'], '', $sent));
                $this->assertTrue(Hash::check($password, $user->password));

                // BCC'd so the office has a record an account was issued.
                $this->assertContains(config('mail.office_archive'), collect($mail->bcc)->flatten()->all());

                return true;
            });
    }

    public function test_a_password_is_never_accepted_from_the_form(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // An old client, or a crafted request, still sending a password must
        // not get to choose one.
        $this->createUserAs($admin, [
            'password' => 'chosen-by-the-admin',
            'password_confirmation' => 'chosen-by-the-admin',
        ]);

        $user = User::where('email', 'grace@example.com')->sole();
        $this->assertFalse(Hash::check('chosen-by-the-admin', $user->password));
    }

    public function test_the_new_account_is_held_on_the_set_password_screen(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_OFFICE,
            'must_change_password' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('password.change'))->assertOk();
    }

    public function test_setting_a_password_releases_the_account(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_OFFICE,
            'must_change_password' => true,
        ]);

        $this->actingAs($user)->post(route('password.change.update'), [
            'password' => 'a-password-of-my-own',
            'password_confirmation' => 'a-password-of-my-own',
        ])->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('a-password-of-my-own', $user->password));

        // /dashboard dispatches by role, so a redirect is expected here —
        // what matters is that it is no longer to the set-password screen.
        $this->assertNotSame(
            route('password.change'),
            $this->actingAs($user)->get('/dashboard')->headers->get('Location')
        );
    }

    /** The mailed password is the one thing the new one must not be. */
    public function test_the_issued_password_cannot_be_kept(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_OFFICE,
            'password' => Hash::make('the-mailed-one'),
            'must_change_password' => true,
        ]);

        $this->actingAs($user)->post(route('password.change.update'), [
            'password' => 'the-mailed-one',
            'password_confirmation' => 'the-mailed-one',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_an_existing_account_is_not_asked_to_change_anything(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OFFICE]);

        $this->assertFalse($user->must_change_password);
        $this->assertNotSame(
            route('password.change'),
            $this->actingAs($user)->get('/dashboard')->headers->get('Location')
        );
    }

    public function test_credentials_can_be_reissued_when_the_mail_never_arrives(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create([
            'role' => User::ROLE_OFFICE,
            'password' => Hash::make('the-lost-one'),
            'must_change_password' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.resend-credentials', $user))
            ->assertRedirect();

        $user->refresh();

        // A reissue invalidates the old password — otherwise two work at once.
        $this->assertFalse(Hash::check('the-lost-one', $user->password));
        $this->assertTrue($user->must_change_password);
        Notification::assertSentTo($user, AccountCredentialsNotification::class);
    }
}
