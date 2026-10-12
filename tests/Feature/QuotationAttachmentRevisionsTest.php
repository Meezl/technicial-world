<?php

namespace Tests\Feature;

use App\Mail\QuotationRevised;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Revising a quotation keeps every earlier attachment on the record — that
 * history is wanted — but the client must receive only the documents belonging
 * to the revision being sent. Receiving three versions of the same breakdown in
 * one email is the reported complaint.
 *
 * Both portals still list the whole history, split into the current batch and
 * the batches a later revision superseded.
 */
class QuotationAttachmentRevisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ServiceRequest $sr;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake('public');

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Carpentry', 'is_active' => true]);

        $this->sr = ServiceRequest::create([
            'request_id' => 'REQ-ATT001',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Fitted wardrobes',
            'location' => 'Kilimani Road',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
        ]);
    }

    /** Posts a quote (or a revision) carrying the named materials documents. */
    private function quote(float $total, array $filenames, bool $isRevision = false): void
    {
        $payload = [
            'service_request_id' => $this->sr->id,
            'labor_cost' => $total * 0.7,
            'total_amount' => $total,
            'is_revision' => $isRevision,
        ];

        foreach ($filenames as $i => $name) {
            $payload["materials_files"][$i] = UploadedFile::fake()->create($name, 30, 'application/pdf');
        }

        $this->actingAs($this->admin)
            ->post(route('admin.rfq.quote'), $payload)
            ->assertRedirect();

        $this->sr->refresh();
    }

    public function test_each_upload_is_stamped_with_the_revision_that_sent_it(): void
    {
        $this->quote(100000, ['breakdown-v1.pdf']);
        $this->quote(120000, ['breakdown-v2.pdf'], isRevision: true);

        // Nothing is thrown away — both documents are still on the request.
        $this->assertCount(2, $this->sr->allQuotationAttachmentPaths());

        $revisions = array_values($this->sr->quote_materials_file_revisions);
        $this->assertSame([0, 1], $revisions, 'first quote is revision 0, the revision is 1');
    }

    public function test_a_revision_emails_only_its_own_documents(): void
    {
        $this->quote(100000, ['breakdown-v1.pdf']);
        $this->quote(120000, ['breakdown-v2.pdf'], isRevision: true);

        $current = $this->sr->currentQuotationAttachmentPaths();
        $this->assertCount(1, $current, 'only the newest batch is current');
        $this->assertNotSame(
            $this->sr->allQuotationAttachmentPaths()[0],
            $current[0],
            'the current document is the revision upload, not the original',
        );

        // The mailable therefore carries the PDF plus exactly one materials
        // file, not one per revision ever sent.
        $names = array_map(
            fn ($a) => $a->as,
            (new QuotationRevised($this->sr))->attachments(),
        );

        $this->assertContains('Quotation-REQ-ATT001.pdf', $names);
        $this->assertContains('Materials-REQ-ATT001.pdf', $names);
        $this->assertNotContains('Materials-REQ-ATT001-2.pdf', $names);
    }

    public function test_a_revision_that_uploads_nothing_carries_the_previous_batch_forward(): void
    {
        $this->quote(100000, ['breakdown-v1.pdf', 'drawings.pdf']);
        $before = $this->sr->allQuotationAttachmentPaths();

        $this->quote(115000, [], isRevision: true);

        // The previous documents still describe the work, so they remain
        // current rather than the client being sent a revision with nothing
        // attached but the generated PDF.
        $this->assertSame($before, $this->sr->currentQuotationAttachmentPaths());
    }

    public function test_the_portals_get_the_history_split_into_current_and_superseded(): void
    {
        $this->quote(100000, ['breakdown-v1.pdf']);
        $this->quote(120000, ['breakdown-v2.pdf', 'addendum.pdf'], isRevision: true);

        $attachments = $this->sr->quotation_attachments;
        $this->assertCount(3, $attachments);

        $current = array_values(array_filter($attachments, fn ($a) => $a['is_current']));
        $superseded = array_values(array_filter($attachments, fn ($a) => !$a['is_current']));

        $this->assertCount(2, $current);
        $this->assertCount(1, $superseded);
        $this->assertSame(1, $current[0]['revision']);
        $this->assertSame(0, $superseded[0]['revision']);
        $this->assertSame('Attachment 1', $current[0]['label']);
        $this->assertStringStartsWith('/storage/', $current[0]['url']);
    }

    public function test_quotations_predating_revision_tracking_keep_every_file_current(): void
    {
        // A row quoted before the revision map existed carries no map at all.
        // Suppressing its attachments would silently stop emailing documents
        // that are still the live quotation, so all of them stay current.
        $this->sr->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_materials_file_paths' => ['quotes/legacy-a.pdf', 'quotes/legacy-b.pdf'],
            'quote_materials_file_revisions' => null,
        ]);

        $this->assertCount(2, $this->sr->fresh()->currentQuotationAttachmentPaths());
    }
}
