<?php

namespace Tests\Feature;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Onboarding a technician.
 *
 * The reference used to be rand(1, 999) against a unique column, so an admin
 * onboarding a technician could be told "Duplicate entry 'TECH-344'" — and
 * the retry rolled the same dice. Worse, the user account was created and
 * committed before the profile, so the failed attempt left the email address
 * taken and every retry then failed validation instead.
 */
class TechnicianOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function documents(): array
    {
        return [
            'doc_nca_license'   => UploadedFile::fake()->create('nca.pdf', 40, 'application/pdf'),
            'doc_tertiary_cert' => UploadedFile::fake()->create('cert.pdf', 40, 'application/pdf'),
            'doc_id_card'       => UploadedFile::fake()->image('id.jpg'),
            'doc_passport_photo' => UploadedFile::fake()->image('passport.jpg'),
            'doc_kra_pin'       => UploadedFile::fake()->create('kra.pdf', 40, 'application/pdf'),
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sammy Wambua',
            'email' => 'sammy' . uniqid() . '@example.com',
            'specialization' => 'Plumbing & Fitting',
            'location' => 'Nairobi',
            'availability' => 'busy',
            'skills' => ['Sanitary Fittings', 'Pump Installation'],
        ], $overrides, $this->documents());
    }

    public function test_references_are_sequential_and_never_collide(): void
    {
        Storage::fake('public');
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($admin)
                ->post(route('admin.technicians.store'), $this->payload())
                ->assertRedirect();
        }

        $references = Technician::pluck('technician_id');

        $this->assertCount(4, $references);
        $this->assertSame($references->unique()->count(), $references->count());
        $this->assertSame(
            ['TECH-001', 'TECH-002', 'TECH-003', 'TECH-004'],
            $references->sort()->values()->all()
        );
    }

    public function test_a_reference_already_in_use_is_stepped_over(): void
    {
        Storage::fake('public');
        Mail::fake();

        // A technician onboarded before the sequential scheme, sitting on a
        // high reference. The next one must not collide with it.
        Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN])->id,
            'technician_id' => 'TECH-344',
            'specialization' => 'Legacy',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('admin.technicians.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('TECH-345', Technician::where('technician_id', '!=', 'TECH-344')->value('technician_id'));
    }

    public function test_a_failed_profile_does_not_strand_the_user_account(): void
    {
        Storage::fake('public');
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $email = 'retry@example.com';

        // Force the profile insert to fail by handing it a reference that is
        // taken, the way the random scheme used to.
        Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN])->id,
            'technician_id' => 'TECH-777',
            'specialization' => 'Legacy',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        $usersBefore = User::count();

        try {
            $this->actingAs($admin)->post(
                route('admin.technicians.store'),
                $this->payload(['email' => $email, 'technician_id' => 'TECH-777'])
            );
        } catch (\Throwable $e) {
            // The insert is expected to fail; what matters is what it left.
        }

        // The account must have rolled back with the profile, so the admin
        // can simply try again with the same address.
        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseMissing('users', ['email' => $email]);

        $this->actingAs($admin)
            ->post(route('admin.technicians.store'), $this->payload(['email' => $email]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    public function test_credentials_are_still_emailed_after_the_response(): void
    {
        Storage::fake('public');
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $email = 'deferred' . uniqid() . '@example.com';

        $this->actingAs($admin)
            ->post(route('admin.technicians.store'), $this->payload(['email' => $email]))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Sending moved off the request so the admin is not left watching
        // "Saving…" through an SMTP round-trip. It must still actually go —
        // a technician with no credentials cannot sign in.
        Mail::assertSent(
            \App\Mail\TechnicianAccountCreated::class,
            fn ($mail) => $mail->hasTo($email)
        );
    }
}
