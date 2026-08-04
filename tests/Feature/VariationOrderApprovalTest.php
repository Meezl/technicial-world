<?php

namespace Tests\Feature;

use App\Mail\VariationOrderIssued;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 2 — putting a variation in front of the client.
 *
 * The client is asked about the delta only. Re-sending a whole 79,500
 * quotation to someone who owed 7,500 is what started all of this.
 */
class VariationOrderApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-VOA-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 72000,
        ], $overrides));

        return [$sr, $client, $admin, $pm];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'reason' => 'Variation agreed on site while work was ongoing',
            'items' => [
                ['category' => 'labor', 'description' => 'Additional works', 'quantity' => 1, 'unit_price' => 7500],
            ],
        ], $overrides);
    }

    /** The complaint, end to end, through the real routes. */
    public function test_the_client_is_asked_about_the_delta_only(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();
        $billing = app(BillingService::class);

        // Client has already settled the original 72,000.
        PaymentRequest::create([
            'payment_request_id' => 'PAY-VOA-1',
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'percentage' => 100,
            'amount' => 72000,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('variations.store', $sr), $this->payload(['send_now' => true]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $vo = $sr->variationOrders()->firstOrFail();
        $this->assertSame(VariationOrder::STATUS_PENDING_CLIENT, $vo->status);
        $this->assertNotNull($vo->sent_at);

        // Still 72,000 — a pending variation must not move the contract.
        $this->assertSame(72000.0, $billing->contractValue($sr->fresh()));

        $this->actingAs($client)
            ->postJson(route('client.variations.approve', $vo))
            ->assertOk()
            ->assertJson(['success' => true, 'contract_value' => 79500]);

        $summary = $billing->summary($sr->fresh());
        $this->assertSame(79500.0, $summary['contract_value']);
        $this->assertSame(72000.0, $summary['settled']);
        $this->assertSame(7500.0, $summary['outstanding'], 'The client owes the variation and nothing else.');

        // Approval invoiced it immediately, citing both references.
        $bill = $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->sole();
        $this->assertSame('7500.00', $bill->amount);
        $this->assertSame($vo->id, $bill->variation_order_id);
        $this->assertStringContainsString($vo->vo_number, $bill->notes);
        $this->assertStringContainsString($sr->request_id, $bill->notes);
    }

    public function test_the_card_carries_the_delta_and_the_projected_value(): void
    {
        Mail::fake();
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload(['send_now' => true]));

        Mail::assertSent(VariationOrderIssued::class, function (VariationOrderIssued $mail) use ($sr) {
            $rendered = $mail->render();
            $vo = $sr->variationOrders()->first();

            return str_contains($mail->envelope()->subject, $vo->vo_number)
                && str_contains($rendered, '7,500.00')      // the delta
                && str_contains($rendered, '79,500.00')     // projected value
                && str_contains($rendered, 'change to your existing job');
        });
    }

    public function test_a_zero_income_variation_can_never_be_sent(): void
    {
        Mail::fake();
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)
            ->post(route('variations.store', $sr), [
                'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
                'reason' => 'Technician fee adjustment — extra day on site',
                'send_now' => true,
            ])
            ->assertSessionHasErrors('reason');

        Mail::assertNothingSent();
    }

    public function test_a_zero_income_variation_is_approved_internally(): void
    {
        Mail::fake();
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'reason' => 'Technician fee adjustment — extra day on site',
        ])->assertSessionHasNoErrors();

        $vo = $sr->variationOrders()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('variations.approve-internal', $vo))
            ->assertSessionHasNoErrors();

        $this->assertSame(VariationOrder::STATUS_APPROVED, $vo->fresh()->status);
        // Internal money only — the contract is untouched.
        $this->assertSame(72000.0, app(BillingService::class)->contractValue($sr->fresh()));
        Mail::assertNothingSent();
    }

    public function test_a_chargeable_variation_cannot_be_approved_internally(): void
    {
        Mail::fake();
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload());
        $vo = $sr->variationOrders()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('variations.approve-internal', $vo))
            ->assertSessionHasErrors('variation');

        $this->assertSame(VariationOrder::STATUS_DRAFT, $vo->fresh()->status);
    }

    public function test_another_client_cannot_approve_someone_elses_variation(): void
    {
        Mail::fake();
        [$sr, , $admin] = $this->makeJob();
        $stranger = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload(['send_now' => true]));
        $vo = $sr->variationOrders()->firstOrFail();

        $this->actingAs($stranger)
            ->postJson(route('client.variations.approve', $vo))
            ->assertStatus(422);

        $this->assertSame(VariationOrder::STATUS_PENDING_CLIENT, $vo->fresh()->status);
    }

    public function test_a_client_cannot_approve_one_that_was_never_sent(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload());
        $vo = $sr->variationOrders()->firstOrFail();

        $this->actingAs($client)
            ->postJson(route('client.variations.approve', $vo))
            ->assertStatus(422);
    }

    public function test_a_declined_variation_leaves_the_contract_alone(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload(['send_now' => true]));
        $vo = $sr->variationOrders()->firstOrFail();

        $this->actingAs($client)
            ->postJson(route('client.variations.decline', $vo), ['reason' => 'Too expensive'])
            ->assertOk();

        $vo->refresh();
        $this->assertSame(VariationOrder::STATUS_DECLINED, $vo->status);
        $this->assertSame('Too expensive', $vo->decline_reason);
        $this->assertSame(72000.0, app(BillingService::class)->contractValue($sr->fresh()));
    }

    public function test_the_client_portal_never_receives_an_internal_variation(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload(['send_now' => true]));
        $this->actingAs($admin)->post(route('variations.store', $sr), [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'reason' => 'Technician fee bump — internal only',
        ]);

        $this->actingAs($client)
            ->get(route('client.request-status', $sr))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('serviceRequest.variation_orders', 1)
                ->where('serviceRequest.variation_orders.0.origin', VariationOrder::ORIGIN_TW)
            )
            ->assertDontSee('internal only');
    }

    public function test_a_client_raised_variation_still_needs_the_price_accepted(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload([
            'origin' => VariationOrder::ORIGIN_CLIENT,
            'reason' => 'Client asked for the store room to be included',
            'send_now' => true,
        ]));

        $vo = $sr->variationOrders()->firstOrFail();
        $this->assertSame(VariationOrder::ORIGIN_CLIENT, $vo->origin);
        $this->assertSame(VariationOrder::STATUS_PENDING_CLIENT, $vo->status, 'The priced figure still needs accepting.');

        $this->actingAs($client)->postJson(route('client.variations.approve', $vo))->assertOk();
        $this->assertSame(79500.0, app(BillingService::class)->contractValue($sr->fresh()));
    }

    public function test_a_variation_cannot_be_sent_twice_after_approval(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload(['send_now' => true]));
        $vo = $sr->variationOrders()->firstOrFail();
        $this->actingAs($client)->postJson(route('client.variations.approve', $vo));

        $this->actingAs($admin)
            ->post(route('variations.send', $vo->fresh()))
            ->assertSessionHasErrors('variation');
    }

    public function test_the_ledger_reaches_both_portals(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('variations.store', $sr), $this->payload(['send_now' => true]));
        $vo = $sr->variationOrders()->firstOrFail();
        $this->actingAs($client)->postJson(route('client.variations.approve', $vo));

        $this->actingAs($admin)
            ->get(route('admin.jobs.show', $sr))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('variationLedger.base_quote', 72000)
                ->where('variationLedger.contract_value', 79500)
                ->has('variationLedger.entries', 2)
            );

        $this->actingAs($client)
            ->get(route('client.request-status', $sr))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('variationLedger.contract_value', 79500)
            );
    }
}
