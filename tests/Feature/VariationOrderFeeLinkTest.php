<?php

namespace Tests\Feature;

use App\Models\CompensationAmendment;
use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\CompensationAmendmentService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4 — a technician fee change cites the scope change behind it.
 *
 * "Why did this technician's fee move on this job?" needs to be answerable
 * months later, not only from prose in a justification field.
 */
class VariationOrderFeeLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $techUser = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $technician = Technician::create([
            'user_id' => $techUser->id,
            'technician_id' => 'TECH-VO-' . strtoupper(substr(uniqid(), -4)),
            'specialization' => 'Flooring',
            'trade' => Technician::TRADE_FITTER,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-FEE-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 100000,
            'progress_percentage' => 50,
        ]);

        JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $technician->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 40000,
        ]);

        return [$sr, $technician, $admin, $pm];
    }

    private function feePayload(array $overrides = []): array
    {
        return array_merge([
            'original_amount' => 40000,
            'proposed_amount' => 55000,
            'justification' => 'Extra two days on site for the additional store room works.',
        ], $overrides);
    }

    private function raiseVariation(ServiceRequest $sr, User $actor, array $extra = []): VariationOrder
    {
        return app(VariationOrderService::class)->create($sr, array_merge([
            'reason' => 'Additional store room',
            'items' => [['category' => 'labor', 'description' => 'Extra works', 'quantity' => 1, 'unit_price' => 30000]],
        ], $extra), $actor);
    }

    public function test_a_fee_change_on_a_job_with_variations_must_cite_one(): void
    {
        [$sr, $technician, $admin, $pm] = $this->makeJob();
        $this->raiseVariation($sr, $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cite the one this fee change relates to');

        app(CompensationAmendmentService::class)->request(
            $sr->fresh(),
            $this->feePayload(['technician_id' => $technician->id]),
            $pm
        );
    }

    /** No variations on the job, nothing to cite — the rule stays out of the way. */
    public function test_a_fee_change_on_a_plain_job_needs_no_variation(): void
    {
        [$sr, $technician, , $pm] = $this->makeJob();

        $amendment = app(CompensationAmendmentService::class)->request(
            $sr,
            $this->feePayload(['technician_id' => $technician->id]),
            $pm
        );

        $this->assertNull($amendment->variation_order_id);
        $this->assertSame(15000.0, $amendment->delta());
    }

    public function test_a_cited_fee_change_links_both_ways(): void
    {
        [$sr, $technician, $admin, $pm] = $this->makeJob();
        $vo = $this->raiseVariation($sr, $admin);

        $amendment = app(CompensationAmendmentService::class)->request(
            $sr->fresh(),
            $this->feePayload(['technician_id' => $technician->id, 'variation_order_id' => $vo->id]),
            $pm
        );

        // Fee change → variation
        $this->assertSame($vo->id, $amendment->variationOrder->id);
        $this->assertSame($vo->vo_number, $amendment->variationOrder->vo_number);

        // Variation → fee changes
        $this->assertTrue($vo->compensationAmendments->contains($amendment));
    }

    public function test_a_variation_from_another_job_cannot_be_cited(): void
    {
        [$sr, $technician, $admin, $pm] = $this->makeJob();
        [$otherSr, , $otherAdmin] = $this->makeJob();
        $foreign = $this->raiseVariation($otherSr, $otherAdmin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different job');

        app(CompensationAmendmentService::class)->request(
            $sr->fresh(),
            $this->feePayload(['technician_id' => $technician->id, 'variation_order_id' => $foreign->id]),
            $pm
        );
    }

    /**
     * The zero-income variation earning its keep: a fee change with no
     * client-side cause still gets a numbered card to cite.
     */
    public function test_a_zero_income_variation_carries_an_internal_fee_change(): void
    {
        [$sr, $technician, $admin, $pm] = $this->makeJob();

        $internal = app(VariationOrderService::class)->create($sr, [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'reason' => 'Technician underquoted the grinding — correcting the fee, no client change',
        ], $admin);

        $amendment = app(CompensationAmendmentService::class)->request(
            $sr->fresh(),
            $this->feePayload(['technician_id' => $technician->id, 'variation_order_id' => $internal->id]),
            $pm
        );

        $this->assertSame($internal->id, $amendment->variation_order_id);
        $this->assertTrue($internal->isZeroIncome());
        $this->assertSame('0.00', $internal->net_amount, 'The client is unaffected.');
    }

    public function test_the_fee_impact_of_a_variation_is_reportable(): void
    {
        Notification::fake();
        Mail::fake();
        [$sr, $technician, $admin, $pm] = $this->makeJob();
        $vo = $this->raiseVariation($sr, $admin);

        $amendment = app(CompensationAmendmentService::class)->request(
            $sr->fresh(),
            $this->feePayload(['technician_id' => $technician->id, 'variation_order_id' => $vo->id]),
            $pm
        );
        $amendment->update(['status' => CompensationAmendment::STATUS_APPROVED]);

        $impact = app(CompensationAmendmentService::class)->feeImpactOf($vo->fresh());

        $this->assertSame($vo->vo_number, $impact['vo_number']);
        $this->assertSame(30000.0, $impact['client_amount'], 'What the client pays.');
        $this->assertSame(15000.0, $impact['fee_movement'], 'What we pay out.');
        $this->assertCount(1, $impact['amendments']);
    }

    /** A pending fee change is not yet a cost. */
    public function test_only_approved_fee_changes_count_toward_the_impact(): void
    {
        [$sr, $technician, $admin, $pm] = $this->makeJob();
        $vo = $this->raiseVariation($sr, $admin);

        app(CompensationAmendmentService::class)->request(
            $sr->fresh(),
            $this->feePayload(['technician_id' => $technician->id, 'variation_order_id' => $vo->id]),
            $pm
        );

        $this->assertSame(0.0, app(CompensationAmendmentService::class)->feeImpactOf($vo->fresh())['fee_movement']);
    }

    public function test_a_fee_reduction_is_a_negative_movement(): void
    {
        [$sr, $technician, $admin, $pm] = $this->makeJob();
        $vo = $this->raiseVariation($sr, $admin, [
            'reason' => 'Scope removed from this technician',
            'items' => [['category' => 'labor', 'description' => 'Descope', 'quantity' => 1, 'unit_price' => -10000]],
        ]);

        $amendment = app(CompensationAmendmentService::class)->request(
            $sr->fresh(),
            $this->feePayload([
                'technician_id' => $technician->id,
                'variation_order_id' => $vo->id,
                'proposed_amount' => 32000,
            ]),
            $pm
        );

        $this->assertSame(-8000.0, $amendment->delta());
    }

    /** Driven through the endpoint a PM actually uses. */
    public function test_the_pm_endpoint_enforces_the_link(): void
    {
        [$sr, $technician, $admin, $pm] = $this->makeJob();
        $this->raiseVariation($sr, $admin);

        $this->actingAs($pm)
            ->post(route('pm.compensation.request', $sr), $this->feePayload(['technician_id' => $technician->id]))
            ->assertSessionHasErrors('variation_order_id');

        $this->assertSame(0, CompensationAmendment::where('service_request_id', $sr->id)->count());
    }
}
