<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Technician;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Quotation;
use App\Models\QuotationLineItem;
use App\Models\JobAssignment;
use App\Models\ProgressReport;
use App\Models\TechnicianDocument;
use App\Models\TechnicianLead;
use App\Models\Payment;
use App\Models\PaymentRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ==================== USERS ====================

        // Admin
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'phone' => '+254700000001',
            'address' => 'Westlands, Nairobi',
            'email_verified_at' => now(),
        ]);

        // Project Managers
        $pm1 = User::create([
            'name' => 'James Kariuki',
            'email' => 'pm@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'project_manager',
            'phone' => '+254700000002',
            'address' => 'Kilimani, Nairobi',
            'email_verified_at' => now(),
        ]);

        $pm2 = User::create([
            'name' => 'Mary Wanjiru',
            'email' => 'pm2@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'project_manager',
            'phone' => '+254700000003',
            'address' => 'Karen, Nairobi',
            'email_verified_at' => now(),
        ]);

        // Clients
        $client1 = User::create([
            'name' => 'John Mwangi',
            'email' => 'client@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'client',
            'phone' => '+254711111111',
            'address' => '123 Ngong Road, Nairobi',
            'email_verified_at' => now(),
        ]);

        $client2 = User::create([
            'name' => 'Sarah Oduya',
            'email' => 'client2@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'client',
            'phone' => '+254722222222',
            'address' => '456 Mombasa Road, Nairobi',
            'email_verified_at' => now(),
        ]);

        $client3 = User::create([
            'name' => 'Peter Kamau',
            'email' => 'client3@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'client',
            'phone' => '+254733333333',
            'address' => '789 Thika Road, Nairobi',
            'email_verified_at' => now(),
        ]);

        // Technician Users
        $techUser1 = User::create([
            'name' => 'Tom Ochieng',
            'email' => 'tech@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'technician',
            'phone' => '+254744444444',
            'email_verified_at' => now(),
        ]);

        $techUser2 = User::create([
            'name' => 'David Kipchoge',
            'email' => 'tech2@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'technician',
            'phone' => '+254755555555',
            'email_verified_at' => now(),
        ]);

        $techUser3 = User::create([
            'name' => 'Grace Akinyi',
            'email' => 'tech3@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'technician',
            'phone' => '+254766666666',
            'email_verified_at' => now(),
        ]);

        $techUser4 = User::create([
            'name' => 'Samuel Maina',
            'email' => 'tech4@technicianworld.co.ke',
            'password' => Hash::make('password'),
            'role' => 'technician',
            'phone' => '+254777777777',
            'email_verified_at' => now(),
        ]);

        // ==================== SERVICE CATEGORIES ====================

        $categories = [
            ['name' => 'Electrical', 'icon' => 'fas fa-bolt', 'description' => 'All electrical installations and repairs'],
            ['name' => 'Plumbing', 'icon' => 'fas fa-tint', 'description' => 'Plumbing installations and repairs'],
            ['name' => 'Painting', 'icon' => 'fas fa-paint-roller', 'description' => 'Interior and exterior painting'],
            ['name' => 'Carpentry', 'icon' => 'fas fa-hammer', 'description' => 'Woodwork and carpentry services'],
            ['name' => 'Masonry', 'icon' => 'fas fa-cubes', 'description' => 'Bricklaying and masonry work'],
            ['name' => 'HVAC', 'icon' => 'fas fa-wind', 'description' => 'Heating ventilation and air conditioning'],
            ['name' => 'Welding', 'icon' => 'fas fa-fire', 'description' => 'Metal fabrication and welding'],
            ['name' => 'General Maintenance', 'icon' => 'fas fa-wrench', 'description' => 'General building maintenance'],
        ];

        foreach ($categories as $cat) {
            ServiceCategory::firstOrCreate(['name' => $cat['name']], $cat);
        }

        // ==================== TECHNICIANS ====================

        $tech1 = Technician::create([
            'user_id' => $techUser1->id,
            'technician_id' => 'TECH-001',
            'specialization' => 'Master Electrician',
            'trade' => 'electrician',
            'location' => 'Nairobi',
            'availability' => 'available',
            'rating' => 4.8,
            'total_jobs' => 150,
            'bio' => 'Experienced electrician with 10+ years in commercial and residential installations.',
            'experience_narrative' => 'Started as an apprentice at Kenya Power, progressed to senior electrical engineer. Specialized in solar panel installations and industrial wiring.',
            'skills' => ['electrical wiring', 'solar installation', 'panel boards', 'lighting'],
            'vetting_status' => 'approved',
            'vetted_by' => $admin->id,
            'vetted_at' => now()->subMonths(6),
            'onboarded_by' => $pm1->id,
            'is_active' => true,
        ]);

        $tech2 = Technician::create([
            'user_id' => $techUser2->id,
            'technician_id' => 'TECH-002',
            'specialization' => 'Senior Plumber',
            'trade' => 'plumber',
            'location' => 'Nairobi',
            'availability' => 'available',
            'rating' => 4.5,
            'total_jobs' => 95,
            'bio' => 'Certified plumber specializing in large-scale water systems.',
            'experience_narrative' => 'Trained at Nairobi Water Company. Expert in sewage systems, water treatment, and pressure systems.',
            'skills' => ['pipe fitting', 'drainage', 'water systems', 'sewage'],
            'vetting_status' => 'approved',
            'vetted_by' => $admin->id,
            'vetted_at' => now()->subMonths(4),
            'onboarded_by' => $pm1->id,
            'is_active' => true,
        ]);

        $tech3 = Technician::create([
            'user_id' => $techUser3->id,
            'technician_id' => 'TECH-003',
            'specialization' => 'Interior Painter',
            'trade' => 'painter',
            'location' => 'Nairobi',
            'availability' => 'busy',
            'rating' => 4.2,
            'total_jobs' => 72,
            'bio' => 'Expert painter with an eye for detail and modern finishes.',
            'skills' => ['interior painting', 'exterior painting', 'texture coating', 'wallpaper'],
            'vetting_status' => 'approved',
            'vetted_by' => $admin->id,
            'vetted_at' => now()->subMonths(3),
            'onboarded_by' => $pm2->id,
            'is_active' => true,
        ]);

        $tech4 = Technician::create([
            'user_id' => $techUser4->id,
            'technician_id' => 'TECH-004',
            'specialization' => 'Carpenter',
            'trade' => 'carpenter',
            'location' => 'Mombasa',
            'availability' => 'available',
            'rating' => 4.0,
            'total_jobs' => 30,
            'bio' => 'Skilled carpenter with focus on custom furniture and fittings.',
            'skills' => ['furniture making', 'cabinetry', 'doors & windows', 'flooring'],
            'vetting_status' => 'under_review',
            'onboarded_by' => $pm2->id,
            'is_active' => true,
        ]);

        // ==================== SERVICE REQUESTS (Various States) ====================

        $cat_electrical = ServiceCategory::where('name', 'Electrical')->first();
        $cat_plumbing = ServiceCategory::where('name', 'Plumbing')->first();
        $cat_painting = ServiceCategory::where('name', 'Painting')->first();

        // 1. Draft RFQ
        ServiceRequest::create([
            'request_id' => 'REQ-20260413-A001',
            'job_reference' => 'TW-2026-0001',
            'user_id' => $client1->id,
            'service_category_id' => $cat_electrical->id,
            'description' => 'Complete rewiring of a 3-bedroom apartment. Old aluminum wiring needs replacement with copper.',
            'location' => 'Kilimani, Nairobi',
            'urgency' => 'medium',
            'status' => 'draft_rfq',
            'rfq_status' => 'pending',
        ]);

        // 2. Awaiting PM Assignment
        $sr2 = ServiceRequest::create([
            'request_id' => 'REQ-20260413-A002',
            'job_reference' => 'TW-2026-0002',
            'user_id' => $client2->id,
            'service_category_id' => $cat_plumbing->id,
            'description' => 'Install new water heating system and repair bathroom plumbing in a commercial building.',
            'location' => 'Westlands, Nairobi',
            'urgency' => 'high',
            'status' => 'awaiting_pm_assignment',
            'rfq_status' => 'pending',
        ]);

        // 3. Awaiting Quote Approval (with quotation)
        $sr3 = ServiceRequest::create([
            'request_id' => 'REQ-20260413-A003',
            'job_reference' => 'TW-2026-0003',
            'user_id' => $client1->id,
            'assigned_pm_id' => $pm1->id,
            'service_category_id' => $cat_painting->id,
            'description' => 'Full interior painting of a 4-bedroom villa. High-end finish required.',
            'location' => 'Karen, Nairobi',
            'urgency' => 'low',
            'status' => 'awaiting_quote_approval',
            'rfq_status' => 'quoted',
            'quote_amount' => 185000,
        ]);

        $quotation = Quotation::create([
            'service_request_id' => $sr3->id,
            'created_by' => $pm1->id,
            'version' => 1,
            'status' => 'sent',
            'materials_total' => 85000,
            'labor_total' => 80000,
            'transport_total' => 20000,
            'grand_total' => 185000,
            'payment_terms' => ['deposit' => 30, 'stages' => [50, 20]],
            'delivery_timeline' => '2 weeks from start date',
            'valid_until' => now()->addDays(14),
        ]);

        QuotationLineItem::create([
            'quotation_id' => $quotation->id, 'category' => 'material',
            'description' => 'Premium interior paint (50L)', 'quantity' => 10,
            'unit' => 'cans', 'unit_price' => 5500, 'total_price' => 55000, 'sort_order' => 1,
        ]);
        QuotationLineItem::create([
            'quotation_id' => $quotation->id, 'category' => 'material',
            'description' => 'Primer and sealers', 'quantity' => 5,
            'unit' => 'cans', 'unit_price' => 3000, 'total_price' => 15000, 'sort_order' => 2,
        ]);
        QuotationLineItem::create([
            'quotation_id' => $quotation->id, 'category' => 'material',
            'description' => 'Sandpaper, brushes, rollers', 'quantity' => 1,
            'unit' => 'set', 'unit_price' => 15000, 'total_price' => 15000, 'sort_order' => 3,
        ]);
        QuotationLineItem::create([
            'quotation_id' => $quotation->id, 'category' => 'labor',
            'description' => 'Painting team (4 painters × 10 days)', 'quantity' => 40,
            'unit' => 'man-days', 'unit_price' => 2000, 'total_price' => 80000, 'sort_order' => 4,
        ]);
        QuotationLineItem::create([
            'quotation_id' => $quotation->id, 'category' => 'transport',
            'description' => 'Materials transport', 'quantity' => 4,
            'unit' => 'trips', 'unit_price' => 5000, 'total_price' => 20000, 'sort_order' => 5,
        ]);

        // 4. In Progress (with technician assigned)
        $sr4 = ServiceRequest::create([
            'request_id' => 'REQ-20260410-B001',
            'job_reference' => 'TW-2026-0004',
            'user_id' => $client3->id,
            'assigned_pm_id' => $pm1->id,
            'service_category_id' => $cat_electrical->id,
            'technician_id' => $tech1->id,
            'lead_technician_id' => $tech1->id,
            'description' => 'Solar panel installation for a commercial office. 10kW system.',
            'location' => 'Industrial Area, Nairobi',
            'urgency' => 'medium',
            'status' => 'in_progress',
            'rfq_status' => 'approved',
            'quote_amount' => 450000,
            'progress_percentage' => 45,
            'assigned_at' => now()->subDays(5),
            'started_at' => now()->subDays(4),
            'technician_arrived' => true,
        ]);

        JobAssignment::create([
            'service_request_id' => $sr4->id,
            'technician_id' => $tech1->id,
            'assigned_by' => $pm1->id,
            'agreed_compensation' => 120000,
            'compensation_notes' => '10kW solar installation - includes mounting and wiring',
            'status' => 'accepted',
            'expected_start' => now()->subDays(5),
            'expected_end' => now()->addDays(10),
            'actual_start' => now()->subDays(4),
        ]);

        // Progress reports for this job
        ProgressReport::create([
            'service_request_id' => $sr4->id,
            'technician_id' => $tech1->id,
            'submitted_by' => $techUser1->id,
            'report_date' => now()->subDays(3),
            'percent_complete' => 20,
            'notes' => 'Completed roof survey and mounting frame installation.',
            'is_validated' => true,
            'validated_by' => $pm1->id,
            'validated_at' => now()->subDays(3),
            'validated_percent' => 20,
        ]);

        ProgressReport::create([
            'service_request_id' => $sr4->id,
            'technician_id' => $tech1->id,
            'submitted_by' => $techUser1->id,
            'report_date' => now()->subDays(1),
            'percent_complete' => 45,
            'notes' => 'Panels mounted. Starting wiring tomorrow.',
            'is_validated' => true,
            'validated_by' => $pm1->id,
            'validated_at' => now()->subDays(1),
            'validated_percent' => 45,
        ]);

        // 5. Completed pending confirmation
        $sr5 = ServiceRequest::create([
            'request_id' => 'REQ-20260401-C001',
            'job_reference' => 'TW-2026-0005',
            'user_id' => $client2->id,
            'assigned_pm_id' => $pm2->id,
            'service_category_id' => $cat_plumbing->id,
            'technician_id' => $tech2->id,
            'description' => 'Full bathroom renovation including tiling and fixture installation.',
            'location' => 'Lavington, Nairobi',
            'urgency' => 'low',
            'status' => 'completed_pending_confirmation',
            'rfq_status' => 'approved',
            'quote_amount' => 280000,
            'progress_percentage' => 100,
            'assigned_at' => now()->subDays(20),
            'started_at' => now()->subDays(18),
            'completed_date' => now()->subDays(1),
        ]);

        // 6. Suspended job
        ServiceRequest::create([
            'request_id' => 'REQ-20260405-D001',
            'job_reference' => 'TW-2026-0006',
            'user_id' => $client3->id,
            'assigned_pm_id' => $pm1->id,
            'service_category_id' => $cat_electrical->id,
            'technician_id' => $tech1->id,
            'description' => 'Office lighting upgrade to LED. Full floor retrofit.',
            'location' => 'CBD, Nairobi',
            'urgency' => 'medium',
            'status' => 'suspended',
            'rfq_status' => 'approved',
            'quote_amount' => 320000,
            'progress_percentage' => 30,
            'suspension_reason' => 'Client payment overdue for second installment.',
            'suspended_at' => now()->subDays(3),
        ]);

        // ==================== TECHNICIAN LEADS ====================

        TechnicianLead::create([
            'name' => 'Michael Otieno',
            'email' => 'michael.otieno@gmail.com',
            'phone' => '+254788888888',
            'trade' => 'electrician',
            'experience' => '5 years experience in residential wiring. Certified by KPLC.',
            'location' => 'Kisumu',
            'status' => 'new',
        ]);

        TechnicianLead::create([
            'name' => 'Faith Njeri',
            'email' => 'faith.njeri@gmail.com',
            'phone' => '+254799999999',
            'trade' => 'plumber',
            'experience' => '3 years in commercial plumbing. Worked on several hotel projects.',
            'location' => 'Nakuru',
            'status' => 'contacted',
            'notes' => 'Spoke with her on phone. Very interested. Sending docs next week.',
        ]);

        // ==================== TECHNICIAN DOCUMENTS (sample) ====================

        TechnicianDocument::create([
            'technician_id' => $tech1->id,
            'document_type' => 'id_card',
            'file_path' => 'documents/tech1_id.pdf',
            'file_name' => 'National ID - Tom Ochieng',
            'verified' => true,
            'verified_by' => $admin->id,
            'verified_at' => now()->subMonths(6),
        ]);

        TechnicianDocument::create([
            'technician_id' => $tech1->id,
            'document_type' => 'technical_cert',
            'file_path' => 'documents/tech1_cert.pdf',
            'file_name' => 'Electrical Engineering Certificate',
            'verified' => true,
            'verified_by' => $admin->id,
            'verified_at' => now()->subMonths(6),
        ]);

        TechnicianDocument::create([
            'technician_id' => $tech1->id,
            'document_type' => 'nca_license',
            'file_path' => 'documents/tech1_nca.pdf',
            'file_name' => 'NCA License - Class 1',
            'verified' => true,
            'verified_by' => $admin->id,
            'verified_at' => now()->subMonths(6),
            'expiry_date' => now()->addMonths(18),
        ]);

        echo "✅ Seed data created successfully!\n";
        echo "   📧 Admin: admin@technicianworld.co.ke / password\n";
        echo "   📧 PM: pm@technicianworld.co.ke / password\n";
        echo "   📧 Client: client@technicianworld.co.ke / password\n";
        echo "   📧 Technician: tech@technicianworld.co.ke / password\n";
    }
}
