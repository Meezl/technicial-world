<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The client Payments screen reads the contract value — the original quote
 * plus every approved variation — not the base quote, and shows the variations
 * that moved it so the new total is never unexplained.
 */
class ClientPaymentsContractValueTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-CPCV-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 72000,
        ]);

        return [$sr, $client, $admin];
    }

    public function test_payments_total_reads_the_contract_value_after_an_approved_variation(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();

        // Admin raises and sends a +7,500 variation; the client approves it.
        $this->actingAs($admin)->post(route('variations.store', $sr), [
            'reason' => 'Extra works agreed on site',
            'send_now' => true,
            'items' => [
                ['category' => 'labor', 'description' => 'Additional works', 'quantity' => 1, 'unit_price' => 7500],
            ],
        ])->assertSessionHasNoErrors();

        $vo = $sr->variationOrders()->firstOrFail();
        $this->actingAs($client)->postJson(route('client.variations.approve', $vo))->assertOk();

        $this->actingAs($client)
            ->get(route('client.payments'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Payments')
                // Headline "quoted" is the merged figure.
                ->where('paymentsByJob.0.quote_amount', 79500)
                ->where('paymentsByJob.0.contract_value', 79500)
                ->where('paymentsByJob.0.original_quote', 72000)
                ->where('paymentsByJob.0.has_contract_change', true)
                // Balance owed tracks the contract value, not the base quote.
                ->where('paymentsByJob.0.balance', 79500)
                // The variation behind the change is named.
                ->where('paymentsByJob.0.approved_variations.0.vo_number', $vo->vo_number)
                ->where('paymentsByJob.0.approved_variations.0.net_amount', 7500)
                // Portfolio total rolls up on the contract value too.
                ->where('summary.total_quoted', 79500));
    }

    public function test_a_job_with_no_variation_shows_no_contract_change(): void
    {
        [$sr, $client] = $this->makeJob();

        $this->actingAs($client)
            ->get(route('client.payments'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('paymentsByJob.0.quote_amount', 72000)
                ->where('paymentsByJob.0.original_quote', 72000)
                ->where('paymentsByJob.0.has_contract_change', false)
                ->where('paymentsByJob.0.approved_variations', []));
    }
}
