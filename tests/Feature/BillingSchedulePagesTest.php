<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin RFQ list and the client portal both read `billing_milestones` off
 * a serialised service request. That used to be a JSON column and is now an
 * accessor over the schedule rows, so these pages are the contract to protect.
 */
class BillingSchedulePagesTest extends TestCase
{
    use RefreshDatabase;

    private function seedJob(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Electrical', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-PAGES-' . uniqid(),
            'user_id' => $client->id,
            'assigned_pm_id' => $admin->id,
            'service_category_id' => $category->id,
            'description' => 'Conduit installation',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 72000,
            'progress_percentage' => 60,
        ]);

        app(BillingService::class)->replaceUnbilledMilestones($sr, [
            ['label' => 'Deposit', 'progress_pct' => 1, 'amount' => 35000],
            ['label' => 'Mid-way', 'progress_pct' => 50, 'amount' => 37000],
        ]);

        return [$sr, $client, $admin];
    }

    public function test_admin_rfq_page_renders_the_schedule(): void
    {
        [$sr, , $admin] = $this->seedJob();

        $this->actingAs($admin)
            ->get(route('admin.rfq'))
            ->assertOk()
            ->assertSee('Deposit')
            ->assertSee('Mid-way');
    }

    public function test_client_dashboard_renders(): void
    {
        [, $client] = $this->seedJob();

        $this->actingAs($client)
            ->get(route('client.dashboard'))
            ->assertOk();
    }

    /**
     * The schedule must be eager-loaded — the accessor runs on every
     * serialised row, so a missing $with means one query per RFQ.
     */
    public function test_schedule_is_eager_loaded_not_queried_per_row(): void
    {
        $this->seedJob();
        $this->seedJob();
        $this->seedJob();

        $rows = ServiceRequest::all();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $rows->toArray();
        $extra = count(\Illuminate\Support\Facades\DB::getQueryLog());

        $this->assertSame(0, $extra, 'Serialising service requests must not fire extra queries.');
        $this->assertCount(2, $rows->first()->billing_milestones);
    }
}
