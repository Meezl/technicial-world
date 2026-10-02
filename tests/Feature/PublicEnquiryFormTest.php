<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The two public forms — "Open a Ticket" and the quote request on the contact
 * page — ask the same questions, offer the trades the office actually sells,
 * and both reach somebody.
 *
 * The quote form previously posted nowhere: it logged the submission to the
 * browser console, told the sender it would be answered, and dropped it.
 */
class PublicEnquiryFormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The trades are already in the database — a migration populates them, and
     * that list is the point: both forms must offer what the office sells
     * rather than a hardcoded three. One retired trade is added to prove the
     * forms do not offer work nobody does any more.
     */
    private function categories(): array
    {
        ServiceCategory::create(['name' => 'Retired Trade', 'description' => 'Test', 'is_active' => false]);

        return ServiceCategory::where('is_active', true)->orderBy('name')->pluck('name')->all();
    }

    private function submission(array $overrides = []): array
    {
        return array_merge([
            'filer_name' => 'Asha Wanjiru',
            'filer_email' => 'asha@example.com',
            'filer_phone' => '+254700000000',
            'location' => 'Kilimani, Nairobi',
            'category' => 'Roofing Services',
            'urgency' => 'normal',
            'subject' => 'Leaking roof over the kitchen',
            'description' => 'Water comes through the ceiling whenever it rains hard.',
        ], $overrides);
    }

    public function test_both_forms_offer_every_active_service_category(): void
    {
        $expected = $this->categories();

        foreach (['/open-ticket', '/contact'] as $url) {
            $categories = $this->get($url)->viewData('page')['props']['categories'];

            // Every active trade, by name, and nothing retired.
            $this->assertSame($expected, $categories, "Wrong list on {$url}.");
            $this->assertNotContains('Retired Trade', $categories);
            $this->assertGreaterThan(3, count($categories), 'The forms are still offering a hardcoded handful.');
        }
    }

    public function test_a_quote_request_is_filed_rather_than_discarded(): void
    {
        Mail::fake();
        $this->categories();
        User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => 'office@example.com']);

        $this->post('/contact', $this->submission())
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        $ticket = Ticket::firstOrFail();

        // Everything the sender told us is kept, not just a name and a message.
        $this->assertSame(Ticket::TYPE_ENQUIRY, $ticket->type);
        $this->assertSame('Asha Wanjiru', $ticket->filer_name);
        $this->assertSame('+254700000000', $ticket->filer_phone);
        $this->assertSame('Kilimani, Nairobi', $ticket->location);
        $this->assertSame('Roofing Services', $ticket->category);
        $this->assertSame('Leaking roof over the kitchen', $ticket->subject);
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->status);
        $this->assertNotNull($ticket->ticket_ref);

        // And it is on the record as having come from the website.
        $this->assertStringContainsString('Quote request', $ticket->statusLogs()->value('note'));
    }

    public function test_a_support_ticket_is_still_a_support_ticket(): void
    {
        Mail::fake();
        $this->categories();

        $this->post('/open-ticket', $this->submission(['urgency' => 'emergency']))
            ->assertSessionHas('success');

        $this->assertSame(Ticket::TYPE_SUPPORT, Ticket::firstOrFail()->type);
    }

    public function test_a_trade_the_office_does_not_sell_is_refused(): void
    {
        Mail::fake();
        $this->categories();

        foreach (['/contact', '/open-ticket'] as $url) {
            $this->post($url, $this->submission(['category' => 'Rocket Science']))
                ->assertSessionHasErrors('category');
        }

        $this->assertSame(0, Ticket::count());
    }

    /** Both forms ask for the same things, so neither accepts less than the other. */
    public function test_the_quote_form_requires_what_the_ticket_form_requires(): void
    {
        Mail::fake();
        $this->categories();

        $this->post('/contact', ['filer_name' => 'Asha', 'description' => 'Short'])
            ->assertSessionHasErrors(['filer_email', 'category', 'urgency', 'subject', 'description']);

        $this->assertSame(0, Ticket::count());
    }

    /** The office hears about an enquiry, and the sender gets an acknowledgement. */
    public function test_an_enquiry_reaches_the_office_and_the_sender(): void
    {
        Mail::fake();
        $this->categories();
        User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => 'office@example.com']);

        $this->post('/contact', $this->submission());

        Mail::assertSent(\App\Mail\TicketCreatedNotification::class);
        Mail::assertSent(\App\Mail\TicketAcknowledgement::class);
    }

    /**
     * The admin queue used to whitelist three slugs, so filtering by a real
     * trade name quietly returned everything.
     */
    public function test_the_admin_queue_can_filter_by_a_real_trade_name(): void
    {
        Mail::fake();
        $this->categories();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->post('/open-ticket', $this->submission(['category' => 'Roofing Services']));
        $this->post('/open-ticket', $this->submission([
            'category' => 'Plumbing & Fitting',
            'filer_email' => 'other@example.com',
            'subject' => 'Blocked drain',
        ]));

        $props = $this->actingAs($admin)
            ->get(route('admin.tickets.index', ['category' => 'Roofing Services']))
            ->viewData('page')['props'];

        $this->assertCount(1, $props['tickets']['data']);
        $this->assertSame('Roofing Services', $props['tickets']['data'][0]['category']);
        $this->assertContains('Plumbing & Fitting', $props['categoryOptions']);
    }
}
