<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\Payment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The admin Payments page can be searched by client and by the references
 * ops quote (REQ no., M-Pesa receipt, phone), and filtered to one client —
 * a person or a corporate account.
 */
class AdminPaymentsSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);
    }

    private function jobFor(User $client, array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
        ], $attributes));
    }

    private function paymentFor(ServiceRequest $job, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'payment_id' => Payment::generatePaymentId(),
            'service_request_id' => $job->id,
            'user_id' => $job->user_id,
            'amount' => 1000,
            'status' => 'completed',
            'payment_method' => 'mpesa',
            'phone_number' => '254700000000',
            'account_reference' => $job->request_id,
            'paid_at' => now(),
        ], $attributes));
    }

    private function paymentIdsFor(array $query): array
    {
        $ids = [];
        $this->actingAs($this->admin)
            ->get('/admin/payments?' . http_build_query($query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$ids) {
                $ids = collect($page->toArray()['props']['payments'])->pluck('id')->sort()->values()->all();
            });

        return $ids;
    }

    public function test_search_matches_client_name_partially_and_case_insensitively(): void
    {
        $wanjiru = User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'Mercy Wanjiru']);
        $otieno = User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'James Otieno']);
        $hers = $this->paymentFor($this->jobFor($wanjiru));
        $this->paymentFor($this->jobFor($otieno));

        $this->assertSame([$hers->id], $this->paymentIdsFor(['search' => 'wanJI']));
    }

    public function test_search_matches_req_number_and_mpesa_receipt(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $byReq = $this->paymentFor($this->jobFor($client, ['request_id' => 'REQ-ZX9001']));
        $byReceipt = $this->paymentFor($this->jobFor($client), ['mpesa_receipt_number' => 'TKL7Q2ABCD']);

        $this->assertSame([$byReq->id], $this->paymentIdsFor(['search' => 'zx900']));
        $this->assertSame([$byReceipt->id], $this->paymentIdsFor(['search' => 'tkl7q2']));
    }

    public function test_client_filter_narrows_to_one_person(): void
    {
        $a = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $b = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $theirs = $this->paymentFor($this->jobFor($a));
        $this->paymentFor($this->jobFor($b));

        $this->assertSame([$theirs->id], $this->paymentIdsFor(['client' => 'user:' . $a->id]));
    }

    public function test_client_filter_and_search_find_a_corporate_account(): void
    {
        $org = ClientOrganisation::create([
            'name' => 'Acme Property Managers',
            'code' => 'ACME' . strtoupper(substr(uniqid(), -4)),
            'billing_email' => 'ap@acme.example',
            'is_active' => true,
        ]);
        $requester = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $other = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $corporate = $this->paymentFor($this->jobFor($requester, ['client_organisation_id' => $org->id, 'segment' => ServiceRequest::SEGMENT_CORPORATE]));
        $this->paymentFor($this->jobFor($other));

        $this->assertSame([$corporate->id], $this->paymentIdsFor(['client' => 'org:' . $org->id]));
        $this->assertSame([$corporate->id], $this->paymentIdsFor(['search' => 'acme prop']));
    }

    public function test_page_offers_clients_for_the_client_picker(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'Grace Akinyi']);
        $this->jobFor($client);

        $this->actingAs($this->admin)
            ->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.0.value', 'user:' . $client->id)
                ->where('clients.0.label', 'Grace Akinyi'));
    }

    public function test_a_malformed_client_value_is_ignored(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $payment = $this->paymentFor($this->jobFor($client));

        $this->assertSame([$payment->id], $this->paymentIdsFor(['client' => 'nonsense']));
    }
}
