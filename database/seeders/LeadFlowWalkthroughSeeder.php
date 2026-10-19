<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A job staffed the way ops staff one, for walking the lead/crew reporting
 * flow by hand on a local machine.
 *
 * Everything up to "ready to be staffed" is set up here — client, crew,
 * approved quotation, labour budget — because none of that is what the
 * walkthrough is about. The staffing itself (naming a lead, cutting tasks,
 * putting people on them) is left to be done through the admin screens, since
 * that is half of what is being checked.
 *
 * Local development only. The password below is a fixture, shared by every
 * account this seeder makes, and exists so a walkthrough can sign in as each
 * of them in turn. It is not used anywhere else and must never be seeded
 * outside a local database.
 */
class LeadFlowWalkthroughSeeder extends Seeder
{
    public const PASSWORD = 'walkthrough-local-only';

    public const ADMIN_EMAIL = 'walkthrough.admin@example.test';
    public const CLIENT_EMAIL = 'walkthrough.client@example.test';
    public const LEAD_EMAIL = 'walkthrough.lead@example.test';
    public const PAINTER_EMAIL = 'walkthrough.painter@example.test';
    public const ELECTRICIAN_EMAIL = 'walkthrough.electrician@example.test';
    public const SUCCESSOR_EMAIL = 'walkthrough.successor@example.test';

    public function run(): void
    {
        if (!app()->environment('local')) {
            throw new \RuntimeException('The walkthrough seeder is for local development only.');
        }

        $admin = $this->user(self::ADMIN_EMAIL, 'Walkthrough Admin', User::ROLE_ADMIN);
        $client = $this->user(self::CLIENT_EMAIL, 'Kobo Safaris Ltd', User::ROLE_CLIENT);

        $lead = $this->technician(self::LEAD_EMAIL, 'Joseph Kulikya', 'Carpentry & Woodwork');
        $this->technician(self::PAINTER_EMAIL, 'Albanus Mbili', 'Painting & Decorating');
        $this->technician(self::ELECTRICIAN_EMAIL, 'Dennis Kirira', 'Electrical');
        $this->technician(self::SUCCESSOR_EMAIL, 'Mercy Wanjiru', 'Carpentry & Woodwork');

        $category = ServiceCategory::firstOrCreate(
            ['name' => 'Carpentry & Woodwork'],
            ['is_active' => true]
        );

        $job = ServiceRequest::updateOrCreate(
            ['request_id' => 'REQ-WALKTHRU'],
            [
                'user_id' => $client->id,
                'service_category_id' => $category->id,
                'description' => 'Construct 2 partitions to define a storage space. Height of partition 3 metres.',
                'location' => 'Riara Road 523',
                'urgency' => 'high',
                'status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
                // Approved, so the staffing gate is open and the walkthrough
                // starts where it is meant to.
                'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
                'quote_amount' => 800000,
                'has_sub_tasks' => true,
                'progress_percentage' => 0,
                'assigned_pm_id' => $admin->id,
            ]
        );

        ServiceRequestBudget::updateOrCreate(
            ['service_request_id' => $job->id],
            ['labor_budget' => 300000, 'materials_budget' => 200000]
        );

        $this->command?->info("Job {$job->request_id} is ready to be staffed (id {$job->id}).");
        $this->command?->info('Lead candidate: ' . $lead->user->name);
    }

    private function user(string $email, string $name, string $role): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role' => $role,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ]
        );
    }

    private function technician(string $email, string $name, string $specialization): Technician
    {
        $user = $this->user($email, $name, User::ROLE_TECHNICIAN);

        return Technician::updateOrCreate(
            ['user_id' => $user->id],
            [
                'technician_id' => 'TECH-' . strtoupper(substr(md5($email), 0, 6)),
                'specialization' => $specialization,
                'location' => 'Nairobi',
                'availability' => 'available',
                'national_id' => substr(str_pad((string) crc32($email), 8, '0', STR_PAD_LEFT), 0, 8),
            ]
        );
    }
}
