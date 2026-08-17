<?php

namespace Tests\Feature;

use App\Models\Tool;
use App\Models\ToolIssuance;
use App\Models\ToolRequest;
use App\Models\ToolRequestItem;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PPE (helmets, reflectors) is stock inventory: added with a quantity, issued
 * a few at a time from a shared pool, and returnable back onto the shelf. Each
 * issue and return is logged in the tool_issuances ledger.
 */
class PpeStockIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function makeTechnician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'General',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    public function test_admin_can_add_a_ppe_stock_item_with_a_quantity(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.tools.store'), [
                'name' => 'Safety Helmet',
                'tracking_type' => Tool::TRACKING_STOCK,
                'quantity_available' => 50,
                'category' => 'PPE',
                'condition' => 'new',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $tool = Tool::where('name', 'Safety Helmet')->firstOrFail();
        $this->assertTrue($tool->isStock());
        $this->assertSame(50, $tool->quantity_available);
        $this->assertSame(0, $tool->quantity_issued);
        $this->assertNull($tool->serial_number, 'A stock item carries no per-unit serial.');
    }

    public function test_a_stock_item_requires_a_quantity(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.tools.store'), [
                'name' => 'Reflector Vest',
                'tracking_type' => Tool::TRACKING_STOCK,
                'category' => 'PPE',
                'condition' => 'new',
            ])
            ->assertSessionHasErrors('quantity_available');
    }

    public function test_approving_a_request_issues_the_quantity_and_decrements_stock(): void
    {
        $admin = $this->admin();
        $tech = $this->makeTechnician();

        $tool = Tool::create([
            'name' => 'Safety Helmet',
            'tracking_type' => Tool::TRACKING_STOCK,
            'quantity_available' => 50,
            'category' => 'PPE',
            'condition' => 'new',
            'status' => Tool::STATUS_AVAILABLE,
        ]);

        $request = ToolRequest::create([
            'technician_id' => $tech->id,
            'urgency' => ToolRequest::URGENCY_NORMAL,
            'status' => ToolRequest::STATUS_PENDING,
        ]);
        $item = ToolRequestItem::create([
            'tool_request_id' => $request->id,
            'tool_id' => $tool->id,
            'tool_name_requested' => 'Safety Helmet',
            'quantity' => 3,
            'status' => ToolRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.tool-requests.approve', $item))
            ->assertRedirect()
            ->assertSessionHas('success');

        $tool->refresh();
        $this->assertSame(47, $tool->quantity_available, '3 of 50 issued leaves 47');
        $this->assertSame(3, $tool->quantity_issued);

        $issuance = ToolIssuance::where('tool_id', $tool->id)->firstOrFail();
        $this->assertSame($tech->id, $issuance->technician_id);
        $this->assertSame(3, $issuance->quantity);
        $this->assertSame($item->id, $issuance->tool_request_item_id);
        $this->assertSame(ToolIssuance::STATUS_ISSUED, $issuance->status);
        $this->assertSame(3, $issuance->quantity_outstanding);
    }

    public function test_a_stock_item_cannot_be_over_issued(): void
    {
        $admin = $this->admin();
        $tech = $this->makeTechnician();

        $tool = Tool::create([
            'name' => 'Reflector Vest',
            'tracking_type' => Tool::TRACKING_STOCK,
            'quantity_available' => 2,
            'category' => 'PPE',
            'condition' => 'new',
            'status' => Tool::STATUS_AVAILABLE,
        ]);

        $request = ToolRequest::create([
            'technician_id' => $tech->id,
            'urgency' => ToolRequest::URGENCY_NORMAL,
            'status' => ToolRequest::STATUS_PENDING,
        ]);
        $item = ToolRequestItem::create([
            'tool_request_id' => $request->id,
            'tool_id' => $tool->id,
            'tool_name_requested' => 'Reflector Vest',
            'quantity' => 5,
            'status' => ToolRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.tool-requests.approve', $item), ['issue_quantity' => 5])
            ->assertSessionHasErrors('issue_quantity');

        $tool->refresh();
        $this->assertSame(2, $tool->quantity_available, 'nothing issued on a failed over-issue');
        $this->assertSame(0, ToolIssuance::where('tool_id', $tool->id)->count());
    }

    public function test_issued_ppe_can_be_returned_in_parts_and_restocks(): void
    {
        $admin = $this->admin();
        $tech = $this->makeTechnician();

        $tool = Tool::create([
            'name' => 'Safety Helmet',
            'tracking_type' => Tool::TRACKING_STOCK,
            'quantity_available' => 10,
            'category' => 'PPE',
            'condition' => 'new',
            'status' => Tool::STATUS_AVAILABLE,
        ]);

        $issuance = $tool->issueQuantity($tech, 4, null, $admin->id);
        $tool->refresh();
        $this->assertSame(6, $tool->quantity_available);

        // Return 1 of the 4.
        $this->actingAs($admin)
            ->post(route('admin.tool-issuances.return', $issuance), ['quantity' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        $tool->refresh();
        $issuance->refresh();
        $this->assertSame(7, $tool->quantity_available);
        $this->assertSame(3, $tool->quantity_issued);
        $this->assertSame(ToolIssuance::STATUS_PARTIALLY_RETURNED, $issuance->status);
        $this->assertSame(3, $issuance->quantity_outstanding);

        // Over-returning the remainder is refused.
        $this->actingAs($admin)
            ->post(route('admin.tool-issuances.return', $issuance), ['quantity' => 4])
            ->assertSessionHas('error');

        // Return the remaining 3 — fully closed.
        $this->actingAs($admin)
            ->post(route('admin.tool-issuances.return', $issuance), ['quantity' => 3]);

        $tool->refresh();
        $issuance->refresh();
        $this->assertSame(10, $tool->quantity_available, 'all 4 back on the shelf');
        $this->assertSame(0, $tool->quantity_issued);
        $this->assertSame(ToolIssuance::STATUS_RETURNED, $issuance->status);
        $this->assertNotNull($issuance->returned_at);
    }

    public function test_a_technician_can_return_their_own_ppe(): void
    {
        $admin = $this->admin();
        $tech = $this->makeTechnician();
        $other = $this->makeTechnician();

        $tool = Tool::create([
            'name' => 'Safety Helmet',
            'tracking_type' => Tool::TRACKING_STOCK,
            'quantity_available' => 10,
            'category' => 'PPE',
            'condition' => 'new',
            'status' => Tool::STATUS_AVAILABLE,
        ]);

        $issuance = $tool->issueQuantity($tech, 3, null, $admin->id);
        $tool->refresh();
        $this->assertSame(7, $tool->quantity_available);

        // Another technician cannot return PPE that is not theirs.
        $this->actingAs($other->user)
            ->post(route('technician.tool-issuances.return', $issuance), ['quantity' => 1])
            ->assertForbidden();

        // The holder returns 2 — restocks and logs it.
        $this->actingAs($tech->user)
            ->post(route('technician.tool-issuances.return', $issuance), ['quantity' => 2])
            ->assertRedirect()
            ->assertSessionHas('success');

        $tool->refresh();
        $issuance->refresh();
        $this->assertSame(9, $tool->quantity_available);
        $this->assertSame(1, $tool->quantity_issued);
        $this->assertSame(1, $issuance->quantity_outstanding);
        $this->assertSame(ToolIssuance::STATUS_PARTIALLY_RETURNED, $issuance->status);

        // They cannot return more than they still hold.
        $this->actingAs($tech->user)
            ->post(route('technician.tool-issuances.return', $issuance), ['quantity' => 5])
            ->assertSessionHas('error');
    }

    public function test_serialized_tool_issuing_is_unchanged(): void
    {
        $admin = $this->admin();
        $tech = $this->makeTechnician();

        $tool = Tool::create([
            'name' => 'Cordless Drill',
            'tracking_type' => Tool::TRACKING_SERIALIZED,
            'serial_number' => 'SN-' . uniqid(),
            'category' => 'Power Tools',
            'condition' => 'good',
            'status' => Tool::STATUS_AVAILABLE,
        ]);

        $request = ToolRequest::create([
            'technician_id' => $tech->id,
            'urgency' => ToolRequest::URGENCY_NORMAL,
            'status' => ToolRequest::STATUS_PENDING,
        ]);
        $item = ToolRequestItem::create([
            'tool_request_id' => $request->id,
            'tool_id' => $tool->id,
            'tool_name_requested' => 'Cordless Drill',
            'quantity' => 1,
            'status' => ToolRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.tool-requests.approve', $item))
            ->assertRedirect()
            ->assertSessionHas('success');

        $tool->refresh();
        $this->assertSame(Tool::STATUS_ISSUED, $tool->status);
        $this->assertSame($tech->id, $tool->technician_id);
        // Serialized tools do not touch the stock ledger.
        $this->assertSame(0, ToolIssuance::where('tool_id', $tool->id)->count());
    }
}
