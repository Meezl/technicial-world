<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestDocument;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A client could only ever attach photos to their job. In construction the
 * brief is often a PDF drawing or spec, so they can now attach those too —
 * held in the same document store the team already reads from.
 */
class ClientDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(User $client, array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::create([
            'name' => 'Fit-out ' . uniqid(),
            'description' => 'Test category',
        ]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(uniqid()),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out.',
            'location' => 'Westlands, Nairobi',
            'urgency' => 'medium',
            'status' => 'in_progress',
            'progress_percentage' => 20,
        ], $attributes));
    }

    private function makeTechnician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'Electrical',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    public function test_client_can_attach_a_pdf_to_their_own_job(): void
    {
        Storage::fake('public');
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $job = $this->makeJob($client);

        $this->actingAs($client)
            ->post(route('client.documents.store', $job), [
                'documents' => [
                    UploadedFile::fake()->create('roof-layout.pdf', 800, 'application/pdf'),
                    UploadedFile::fake()->create('single-line-diagram.pdf', 1200, 'application/pdf'),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $docs = ServiceRequestDocument::where('service_request_id', $job->id)->get();
        $this->assertCount(2, $docs);

        $first = $docs->firstWhere('original_name', 'roof-layout.pdf');
        $this->assertNotNull($first);
        // Client uploads are theirs, shared by nature, and labelled as such.
        $this->assertSame(ServiceRequestDocument::KIND_CLIENT_UPLOAD, $first->kind);
        $this->assertTrue($first->is_client_visible);
        $this->assertSame($client->id, $first->uploaded_by);
        $this->assertSame('roof-layout', $first->title);
        Storage::disk('public')->assertExists($first->path);
    }

    public function test_a_non_pdf_document_is_rejected(): void
    {
        Storage::fake('public');
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $job = $this->makeJob($client);

        $this->actingAs($client)
            ->post(route('client.documents.store', $job), [
                'documents' => [UploadedFile::fake()->image('snap.jpg')],
            ])
            ->assertSessionHasErrors('documents.0');

        $this->assertSame(0, ServiceRequestDocument::count());
    }

    public function test_a_client_cannot_attach_to_someone_elses_job(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $intruder = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $job = $this->makeJob($owner);

        $this->actingAs($intruder)
            ->post(route('client.documents.store', $job), [
                'documents' => [UploadedFile::fake()->create('mine.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, ServiceRequestDocument::count());
    }

    public function test_a_client_upload_reaches_the_client_page_and_the_assigned_technician(): void
    {
        Storage::fake('public');
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $technician = $this->makeTechnician();
        $job = $this->makeJob($client, [
            'technician_id' => $technician->id,
            'lead_technician_id' => $technician->id,
        ]);

        $this->actingAs($client)->post(route('client.documents.store', $job), [
            'documents' => [UploadedFile::fake()->create('brief.pdf', 300, 'application/pdf')],
        ]);

        // The client sees it back on their own request page.
        $this->actingAs($client)
            ->get(route('client.request-status', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('serviceRequest.documents', 1)
                ->where('serviceRequest.documents.0.original_name', 'brief.pdf')
                ->where('serviceRequest.documents.0.kind', ServiceRequestDocument::KIND_CLIENT_UPLOAD));

        // And it surfaces to the technician working the job, because it is
        // client-visible and their page renders shared job documents.
        $this->actingAs($technician->user)
            ->get(route('technician.jobs.show', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('job.documents', 1)
                ->where('job.documents.0.original_name', 'brief.pdf'));
    }

    public function test_a_client_can_remove_their_own_upload_but_not_an_ops_document(): void
    {
        Storage::fake('public');
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->makeJob($client);

        $mine = ServiceRequestDocument::create([
            'service_request_id' => $job->id,
            'kind' => ServiceRequestDocument::KIND_CLIENT_UPLOAD,
            'title' => 'My drawing',
            'path' => $path = 'job-documents/' . $job->request_id . '/mine.pdf',
            'original_name' => 'mine.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 100,
            'is_client_visible' => true,
            'uploaded_by' => $client->id,
        ]);
        Storage::disk('public')->put($path, 'x');

        // An ops document the client happens to be able to see must stay put.
        $opsDoc = ServiceRequestDocument::create([
            'service_request_id' => $job->id,
            'kind' => ServiceRequestDocument::KIND_APPROVAL,
            'title' => 'Signed approval',
            'path' => 'job-documents/' . $job->request_id . '/approval.pdf',
            'original_name' => 'approval.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 100,
            'is_client_visible' => true,
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($client)
            ->delete(route('client.documents.destroy', [$job, $opsDoc]))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('service_request_documents', ['id' => $opsDoc->id]);

        $this->actingAs($client)
            ->delete(route('client.documents.destroy', [$job, $mine]))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('service_request_documents', ['id' => $mine->id]);
        Storage::disk('public')->assertMissing($path);
    }
}
