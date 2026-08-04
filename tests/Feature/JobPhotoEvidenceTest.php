<?php

namespace Tests\Feature;

use App\Models\JobPhoto;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JobPhotoEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(User $client, ?Technician $technician = null): ServiceRequest
    {
        $category = ServiceCategory::create([
            'name' => 'Electrical Services ' . uniqid(),
            'description' => 'Test category',
        ]);

        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(uniqid()),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Solar install for a commercial office.',
            'location' => 'Industrial Area, Nairobi',
            'urgency' => 'medium',
            'status' => 'in_progress',
            'technician_id' => $technician?->id,
            'progress_percentage' => 20,
        ]);
    }

    private function makeTechnician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'Electrical Installations',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    public function test_client_can_upload_several_photos_to_their_own_job(): void
    {
        Storage::fake('public');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $job = $this->makeJob($client);

        $response = $this->actingAs($client)->post(route('jobs.photos.store', $job), [
            'photos' => [
                UploadedFile::fake()->image('snag-1.jpg'),
                UploadedFile::fake()->image('snag-2.jpg'),
                UploadedFile::fake()->image('snag-3.jpg'),
            ],
            'caption' => 'Leak came back on the ceiling',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $photos = JobPhoto::where('service_request_id', $job->id)->get();

        $this->assertCount(3, $photos);
        $this->assertSame(ServiceRequest::class, $photos->first()->photoable_type);
        $this->assertSame($job->id, $photos->first()->photoable_id);
        $this->assertSame('Leak came back on the ceiling', $photos->first()->caption);
        $this->assertSame(User::ROLE_CLIENT, $photos->first()->uploader_role);

        // A client's own evidence must be visible back to them.
        $this->assertTrue($photos->first()->client_visible);

        foreach ($photos as $photo) {
            Storage::disk('public')->assertExists($photo->file_path);
        }
    }

    public function test_client_cannot_upload_to_someone_elses_job(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $stranger = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $job = $this->makeJob($owner);

        $response = $this->actingAs($stranger)->post(route('jobs.photos.store', $job), [
            'photos' => [UploadedFile::fake()->image('nosey.jpg')],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, JobPhoto::where('service_request_id', $job->id)->count());
    }

    public function test_assigned_technician_can_upload_but_an_unassigned_one_cannot(): void
    {
        Storage::fake('public');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $assigned = $this->makeTechnician();
        $unassigned = $this->makeTechnician();
        $job = $this->makeJob($client, $assigned);

        $this->actingAs($assigned->user)
            ->post(route('jobs.photos.store', $job), [
                'photos' => [UploadedFile::fake()->image('site.jpg')],
            ])
            ->assertSessionHas('success');

        $this->actingAs($unassigned->user)
            ->post(route('jobs.photos.store', $job), [
                'photos' => [UploadedFile::fake()->image('not-my-job.jpg')],
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, JobPhoto::where('service_request_id', $job->id)->count());
    }

    public function test_upload_is_capped_and_rejects_non_images(): void
    {
        Storage::fake('public');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $job = $this->makeJob($client);

        $tooMany = collect(range(1, 7))
            ->map(fn ($i) => UploadedFile::fake()->image("photo-{$i}.jpg"))
            ->all();

        $this->actingAs($client)
            ->post(route('jobs.photos.store', $job), ['photos' => $tooMany])
            ->assertSessionHasErrors('photos');

        $this->actingAs($client)
            ->post(route('jobs.photos.store', $job), [
                'photos' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('photos.0');

        $this->assertSame(0, JobPhoto::where('service_request_id', $job->id)->count());
    }

    public function test_ops_photos_are_internal_until_deliberately_shared(): void
    {
        Storage::fake('public');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->makeJob($client);

        $this->actingAs($admin)->post(route('jobs.photos.store', $job), [
            'photos' => [UploadedFile::fake()->image('internal-note.jpg')],
        ])->assertSessionHas('success');

        $photo = JobPhoto::where('service_request_id', $job->id)->first();

        $this->assertFalse($photo->client_visible);
        $this->assertSame(0, JobPhoto::clientVisible()->where('service_request_id', $job->id)->count());
    }

    public function test_progress_report_photos_cannot_be_deleted_only_excluded(): void
    {
        Storage::fake('public');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $technician = $this->makeTechnician();
        $job = $this->makeJob($client, $technician);

        $report = ProgressReport::create([
            'service_request_id' => $job->id,
            'technician_id' => $technician->id,
            'submitted_by' => $technician->user->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 40,
        ]);

        $photo = $report->photos()->create([
            'service_request_id' => $job->id,
            'file_path' => UploadedFile::fake()->image('evidence.jpg')->store('progress-photos', 'public'),
            'added_by' => $technician->user->id,
        ]);

        $this->actingAs($technician->user)
            ->delete(route('jobs.photos.destroy', $photo))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('job_photos', ['id' => $photo->id]);
    }

    public function test_uploader_can_delete_their_own_job_photo(): void
    {
        Storage::fake('public');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $job = $this->makeJob($client);

        $this->actingAs($client)->post(route('jobs.photos.store', $job), [
            'photos' => [UploadedFile::fake()->image('wrong-one.jpg')],
        ]);

        $photo = JobPhoto::where('service_request_id', $job->id)->firstOrFail();

        $this->actingAs($client)
            ->delete(route('jobs.photos.destroy', $photo))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('job_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($photo->file_path);
    }

    public function test_client_sees_their_evidence_on_the_request_status_page(): void
    {
        Storage::fake('public');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->makeJob($client);

        $this->actingAs($client)->post(route('jobs.photos.store', $job), [
            'photos' => [UploadedFile::fake()->image('mine.jpg')],
        ]);
        $this->actingAs($admin)->post(route('jobs.photos.store', $job), [
            'photos' => [UploadedFile::fake()->image('internal.jpg')],
        ]);

        $response = $this->actingAs($client)->get(route('client.request-status', $job));
        $response->assertOk();

        $photos = $response->viewData('page')['props']['serviceRequest']['photos'];

        // Their own photo, and not the internal one.
        $this->assertCount(1, $photos);
        $this->assertSame(User::ROLE_CLIENT, $photos[0]['uploader_role']);
        $this->assertStringStartsWith('/storage/', $photos[0]['url']);
    }
}
