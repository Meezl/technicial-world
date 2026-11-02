<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ReqBillingMilestone;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Correcting a variation in place.
 *
 * The only way to fix a rate used to be withdrawing the variation and raising
 * another. A draft or one the client has not answered can now be edited and
 * keeps its number; an approved one is still final, and a declined one is
 * revised so the client's refusal stays on record.
 */
class VariationOrderEditTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Painting', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-ED-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'External paint',
            'location' => 'Kilimani',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 100000,
        ]);

        return [$sr, $admin];
    }

    private function service(): VariationOrderService
    {
        return app(VariationOrderService::class);
    }

    private function raise(ServiceRequest $sr, User $admin, ?array $billing = null): VariationOrder
    {
        return $this->service()->create($sr, [
            'reason' => 'External Paint Work',
            'items' => [
                ['category' => 'material', 'description' => 'Crown Permaplast - Orange', 'quantity' => 2, 'unit' => '4L can', 'unit_price' => 3950],
                ['category' => 'labor', 'description' => 'Paint work, external', 'quantity' => 52, 'unit' => 'm²', 'unit_price' => 150],
            ],
            'billing' => $billing,
        ], $admin);
    }

    public function test_lines_keep_their_unit(): void
    {
        [$sr, $admin] = $this->makeJob();

        $vo = $this->raise($sr, $admin);
        $paint = $vo->items->first();

        $this->assertSame('4L can', $paint->unit);
        $this->assertSame('2.00', $paint->quantity);
        $this->assertSame('3950.00', $paint->unit_price);
        $this->assertSame('7900.00', $paint->total_price);
        $this->assertSame('15700.00', $vo->net_amount);
    }

    public function test_a_variation_awaiting_the_client_can_be_edited_in_place(): void
    {
        Mail::fake();
        [$sr, $admin] = $this->makeJob();

        $vo = $this->raise($sr, $admin, ['deposit' => 5000]);
        $this->service()->sendToClient($vo, $admin);

        $edited = $this->service()->update($vo->fresh(), [
            'reason' => 'External Paint Work, revised quantities',
            'additional_days' => 3,
            'items' => [
                ['category' => 'material', 'description' => 'Crown Permaplast - Orange', 'quantity' => 3, 'unit' => '4L can', 'unit_price' => 3950],
            ],
            'billing' => null,
        ], $admin);

        $this->assertSame($vo->id, $edited->id);
        $this->assertSame($vo->vo_number, $edited->vo_number);
        $this->assertSame(VariationOrder::STATUS_PENDING_CLIENT, $edited->status);
        $this->assertSame('11850.00', $edited->net_amount);
        $this->assertSame(3, $edited->additional_days);
        $this->assertCount(1, $edited->items);

        // The schedule is laid out again from the new figure.
        $schedule = ReqBillingMilestone::where('variation_order_id', $vo->id)->get();
        $this->assertCount(1, $schedule);
        $this->assertSame(11850.0, (float) $schedule->first()->amount);

        // What the client was first shown can still be read back.
        $log = AuditLog::where('auditable_type', VariationOrder::class)
            ->where('auditable_id', $vo->id)
            ->latest('id')
            ->first();
        $this->assertSame(15700.0, (float) $log->old_values['net_amount']);
        $this->assertCount(2, $log->old_values['items']);
    }

    public function test_an_approved_variation_cannot_be_edited(): void
    {
        [$sr, $admin] = $this->makeJob();

        $vo = $this->raise($sr, $admin);
        $this->service()->approve($vo, $admin, app(BillingService::class));

        $this->expectException(RuntimeException::class);
        $this->service()->update($vo->fresh(), ['reason' => 'Changed', 'items' => []], $admin);
    }

    public function test_a_declined_variation_is_revised_not_edited(): void
    {
        [$sr, $admin] = $this->makeJob();

        $vo = $this->raise($sr, $admin);
        $this->service()->decline($vo, $admin, 'Too dear');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Revise it instead');
        $this->service()->update($vo->fresh(), ['reason' => 'Cheaper', 'items' => []], $admin);
    }

    public function test_the_edit_endpoint_saves_and_can_re_send(): void
    {
        Mail::fake();
        [$sr, $admin] = $this->makeJob();

        $vo = $this->raise($sr, $admin);

        $this->actingAs($admin)
            ->put(route('variations.update', $vo), [
                'reason' => 'External Paint Work',
                'items' => [
                    ['category' => 'material', 'description' => 'Crown Permaplast - Orange', 'quantity' => 2, 'unit' => '4L can', 'unit_price' => 4000],
                ],
                'send_now' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $vo->refresh();
        $this->assertSame('8000.00', $vo->net_amount);
        $this->assertSame(VariationOrder::STATUS_PENDING_CLIENT, $vo->status);
        $this->assertNotNull($vo->sent_at);
    }
}
