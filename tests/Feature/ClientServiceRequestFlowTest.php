<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientServiceRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_submission_redirects_with_success_and_request_context(): void
    {
        Notification::fake();

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $category = ServiceCategory::create([
            'name' => 'Electrical',
            'is_active' => true,
        ]);

        $response = $this->actingAs($client)->post(route('service-requests.store'), [
            'service_category_id' => $category->id,
            'description' => 'Need an urgent inspection for a power outage affecting the main office wing.',
            'location' => 'Westlands, Nairobi',
            'urgency' => 'high',
        ]);

        $response->assertRedirect(route('client.dashboard'));
        $response->assertSessionHas('success', 'Service request submitted successfully.');
        $response->assertSessionHas('submittedRequest', function (array $submittedRequest) {
            return isset($submittedRequest['id'], $submittedRequest['request_id'])
                && $submittedRequest['service_category'] === 'Electrical';
        });
    }

    public function test_client_dashboard_counts_new_pending_request_as_active(): void
    {
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $category = ServiceCategory::create([
            'name' => 'Plumbing',
            'is_active' => true,
        ]);

        ServiceRequest::create([
            'request_id' => 'REQ-ACTIVE-001',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'A burst pipe in the kitchen requires urgent repair and pressure testing.',
            'location' => 'Kilimani, Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_CLIENT_SELF,
        ]);

        $response = $this->actingAs($client)->get(route('client.dashboard'));

        $response->assertOk();
        $response->assertViewHas('page.component', 'Client/Dashboard');
        $response->assertViewHas('page.props.stats.activeRequests', 1);
    }

    public function test_dashboard_page_receives_flash_success_after_submission_redirect(): void
    {
        Notification::fake();

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $category = ServiceCategory::create([
            'name' => 'HVAC',
            'is_active' => true,
        ]);

        $response = $this->followingRedirects()->actingAs($client)->post(route('service-requests.store'), [
            'service_category_id' => $category->id,
            'description' => 'The office AC system needs troubleshooting after repeated cooling failure.',
            'location' => 'Upper Hill, Nairobi',
            'urgency' => 'medium',
        ]);

        $response->assertOk();
        $response->assertViewHas('page.component', 'Client/Dashboard');
        $response->assertViewHas('page.props.flash.success', 'Service request submitted successfully.');
        $response->assertViewHas('page.props.stats.activeRequests', 1);
    }
}
