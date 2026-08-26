<?php

namespace Tests\Feature;

use App\Mail\QuotationRevised;
use App\Mail\QuotationSent;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A quotation email — first send or revision — must carry the same content and
 * every attached file, and BCC the office inbox so a copy of exactly what the
 * client received is kept.
 */
class QuotationEmailAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuotedRequest(): ServiceRequest
    {
        Storage::fake('public');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        // Two office-attached materials files.
        $pathA = UploadedFile::fake()->create('breakdown.pdf', 40, 'application/pdf')->store('quotes', 'public');
        $pathB = UploadedFile::fake()->create('drawings.pdf', 40, 'application/pdf')->store('quotes', 'public');

        return ServiceRequest::create([
            'request_id' => 'REQ-QEA-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => 'pending',
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 20000,
            'quote_materials_file_paths' => [$pathA, $pathB],
        ]);
    }

    /** @return array<int,string> the `as` names of the mailable's attachments */
    private function attachmentNames($mailable): array
    {
        return array_map(fn ($a) => $a->as, $mailable->attachments());
    }

    public function test_a_sent_quotation_bccs_the_office_and_attaches_every_file(): void
    {
        config(['services.quotation_bcc' => 'info@technicianworld.co.ke']);
        $sr = $this->makeQuotedRequest();
        $ref = $sr->request_id;

        $mailable = new QuotationSent($sr);
        $mailable->assertHasBcc('info@technicianworld.co.ke');

        $names = $this->attachmentNames($mailable);
        // The generated PDF plus BOTH attached materials files.
        $this->assertContains("Quotation-{$ref}.pdf", $names);
        $this->assertContains("Materials-{$ref}-1.pdf", $names);
        $this->assertContains("Materials-{$ref}-2.pdf", $names);
    }

    public function test_a_revised_quotation_also_bccs_and_attaches_the_files(): void
    {
        config(['services.quotation_bcc' => 'info@technicianworld.co.ke']);
        $sr = $this->makeQuotedRequest();
        $ref = $sr->request_id;

        $mailable = new QuotationRevised($sr);
        $mailable->assertHasBcc('info@technicianworld.co.ke');

        // Previously a revision attached nothing at all.
        $names = $this->attachmentNames($mailable);
        $this->assertContains("Quotation-{$ref}.pdf", $names);
        $this->assertContains("Materials-{$ref}-1.pdf", $names);
        $this->assertContains("Materials-{$ref}-2.pdf", $names);
    }

    public function test_the_bcc_is_configurable_and_skipped_when_empty(): void
    {
        config(['services.quotation_bcc' => '']);
        $sr = $this->makeQuotedRequest();

        // No BCC configured — the envelope carries no BCC (the email still
        // sends, just without an office copy).
        $this->assertSame([], (new QuotationSent($sr))->envelope()->bcc);
    }
}
