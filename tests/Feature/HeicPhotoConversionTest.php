<?php

namespace Tests\Feature;

use App\Models\JobPhoto;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\StoredImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * iPhone photographs that no browser could draw.
 *
 * HEIC uploads are accepted on purpose — refusing them meant a technician on
 * site could not send a photograph at all (#27). What was missing is the other
 * half: Chrome, Firefox and Edge cannot decode HEIC, so the file stored
 * perfectly and rendered as a grey box everywhere but Safari.
 *
 * Conversion needs ImageMagick built against libheif. Where that is absent the
 * upload must still succeed and still be recorded — losing a technician's only
 * photograph of a site would be a far worse failure than one that will not
 * render — so the behaviour is asserted both ways.
 */
class HeicPhotoConversionTest extends TestCase
{
    use RefreshDatabase;

    private function job(): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);

        return ServiceRequest::create([
            'request_id' => 'REQ-HEIC-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Riser replacement',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ]);
    }

    // ==================== recognising them ====================

    public function test_an_iphone_photo_is_recognised_by_extension(): void
    {
        $this->assertTrue(StoredImage::needsConversion(
            UploadedFile::fake()->create('IMG_4021.HEIC', 200, 'image/heic')
        ));
        $this->assertTrue(StoredImage::needsConversion(
            UploadedFile::fake()->create('IMG_4021.heif', 200, 'image/heif')
        ));
    }

    public function test_a_jpeg_is_left_alone(): void
    {
        $this->assertFalse(StoredImage::needsConversion(
            UploadedFile::fake()->image('site.jpg')
        ));
        $this->assertFalse(StoredImage::needsConversion(
            UploadedFile::fake()->image('site.png')
        ));
    }

    public function test_a_jpeg_upload_keeps_its_format(): void
    {
        Storage::fake('public');

        $path = StoredImage::put(UploadedFile::fake()->image('site.jpg'), 'job-photos/1');

        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('public')->assertExists($path);
    }

    // ==================== storing them ====================

    public function test_a_heic_upload_is_never_lost_whatever_the_server_can_decode(): void
    {
        Storage::fake('public');

        $path = StoredImage::put(
            UploadedFile::fake()->create('IMG_4021.heic', 300, 'image/heic'),
            'job-photos/1'
        );

        // Converted where a decoder exists, stored as it arrived where none
        // does — but stored either way. The upload is the thing that must not
        // be lost.
        Storage::disk('public')->assertExists($path);

        if (StoredImage::canConvert()) {
            $this->assertStringEndsWith('.jpg', $path);
        } else {
            $this->assertStringEndsWith('.heic', $path);
        }
    }

    public function test_the_job_photo_endpoint_stores_an_iphone_upload(): void
    {
        Storage::fake('public');

        $job = $this->job();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('jobs.photos.store', $job), [
                'photos' => [UploadedFile::fake()->create('IMG_4021.heic', 300, 'image/heic')],
                'caption' => 'Riser before work',
            ])
            ->assertRedirect();

        $photo = JobPhoto::firstOrFail();

        Storage::disk('public')->assertExists($photo->file_path);
        $this->assertSame('Riser before work', $photo->caption);

        if (StoredImage::canConvert()) {
            $this->assertStringEndsWith('.jpg', $photo->file_path);
        }
    }

    // ==================== the ones already on disk ====================

    public function test_the_backfill_refuses_rather_than_pretending_when_it_cannot_decode(): void
    {
        if (StoredImage::canConvert()) {
            $this->markTestSkipped('This machine has a HEIC decoder.');
        }

        $this->artisan('photos:convert-heic')
            ->expectsOutputToContain('No HEIC decoder on this machine')
            ->assertExitCode(1);
    }

    public function test_the_backfill_leaves_everything_alone_on_a_dry_run(): void
    {
        if (!StoredImage::canConvert()) {
            $this->markTestSkipped('No HEIC decoder on this machine.');
        }

        Storage::fake('public');
        $job = $this->job();

        $photo = JobPhoto::create([
            'service_request_id' => $job->id,
            'photoable_type' => ServiceRequest::class,
            'photoable_id' => $job->id,
            'file_path' => 'job-photos/1/original.heic',
            'added_by' => User::factory()->create(['role' => User::ROLE_ADMIN])->id,
            'uploader_role' => User::ROLE_ADMIN,
        ]);

        $this->artisan('photos:convert-heic --dry-run')->assertExitCode(0);

        $this->assertSame('job-photos/1/original.heic', $photo->fresh()->file_path);
    }

    // ==================== not taking the box down with it ====================

    public function test_a_file_too_large_to_decode_safely_is_stored_untouched(): void
    {
        Storage::fake('public');

        // Far outside what a camera produces. Attempting it risks the process
        // for a file nobody is waiting on.
        $huge = UploadedFile::fake()->create(
            'IMG_4021.heic',
            (StoredImage::MAX_SOURCE_BYTES / 1024) + 1024,
            'image/heic'
        );

        $path = StoredImage::put($huge, 'job-photos/1');

        $this->assertStringEndsWith('.heic', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_backfill_works_in_batches_rather_than_all_at_once(): void
    {
        // Converting every photograph in one process is what took the instance
        // down: ImageMagick allocates outside PHP's memory_limit, so nothing
        // bounded a long run. The command now does a batch and says what is
        // left.
        $definition = $this->app->make(\App\Console\Commands\ConvertStoredHeicPhotos::class)
            ->getDefinition();

        $this->assertTrue($definition->hasOption('limit'));
        $this->assertSame('25', $definition->getOption('limit')->getDefault());
    }
}
