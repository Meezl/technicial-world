<?php

namespace Tests\Feature;

use App\Models\Tool;
use App\Models\ToolRequest;
use App\Models\ToolRequestItem;
use App\Models\Technician;
use App\Models\User;
use App\Notifications\ToolRequestDecisionNotification;
use App\Notifications\ToolRequestSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A technician can raise a tool request, and each side is told what happens to
 * it: the office when it comes in, the technician when it is accepted, issued
 * or rejected.
 */
class ToolRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_technician_can_submit_a_request_and_admins_are_notified(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();
        $tool = Tool::create([
            'name' => 'Safety Helmet',
            'tracking_type' => Tool::TRACKING_STOCK,
            'quantity_available' => 20,
            'category' => 'PPE',
            'condition' => 'new',
            'status' => Tool::STATUS_AVAILABLE,
        ]);

        $this->actingAs($tech->user)
            ->post(route('technician.tool-requests.store'), [
                'urgency' => 'high',
                'items' => [
                    ['tool_id' => $tool->id, 'quantity' => 2],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The request actually persisted.
        $this->assertDatabaseHas('tool_requests', ['technician_id' => $tech->id]);
        $this->assertDatabaseHas('tool_request_items', ['tool_id' => $tool->id, 'quantity' => 2]);

        Notification::assertSentTo($admin, ToolRequestSubmittedNotification::class);
    }

    public function test_the_technician_is_notified_when_their_item_is_issued(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();
        $tool = Tool::create([
            'name' => 'Reflector Vest',
            'tracking_type' => Tool::TRACKING_STOCK,
            'quantity_available' => 10,
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
            'quantity' => 2,
            'status' => ToolRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.tool-requests.approve', $item))
            ->assertRedirect();

        Notification::assertSentTo(
            $tech->user,
            ToolRequestDecisionNotification::class,
            fn ($notification) => $notification->action === ToolRequestDecisionNotification::ACTION_ASSIGNED
        );
    }

    public function test_the_technician_is_notified_when_their_item_is_rejected(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $request = ToolRequest::create([
            'technician_id' => $tech->id,
            'urgency' => ToolRequest::URGENCY_NORMAL,
            'status' => ToolRequest::STATUS_PENDING,
        ]);
        $item = ToolRequestItem::create([
            'tool_request_id' => $request->id,
            'tool_name_requested' => 'Angle Grinder',
            'quantity' => 1,
            'status' => ToolRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.tool-requests.reject', $item), [
                'decision_notes' => 'Out of stock this week.',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $tech->user,
            ToolRequestDecisionNotification::class,
            fn ($notification) => $notification->action === ToolRequestDecisionNotification::ACTION_REJECTED
        );
    }
}
