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
        $admin = User::firstOrCreate(
            ['email' => 'admin@technicianworld.co.ke'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('tech@123!!'),
                'role' => 'admin',
                'phone' => '+254700000001',
                'address' => 'Westlands, Nairobi',
                'email_verified_at' => now(),
            ]
        );

        // Project Managers
        $pm1 = User::firstOrCreate(
            ['email' => 'pm@technicianworld.co.ke'],
            [
                'name' => 'James Kariuki',
                'password' => Hash::make('tech@123!!'),
                'role' => 'project_manager',
                'phone' => '+254700000002',
                'address' => 'Kilimani, Nairobi',
                'email_verified_at' => now(),
            ]
        );

        $pm2 = User::firstOrCreate(
            ['email' => 'pm2@technicianworld.co.ke'],
            [
                'name' => 'Mary Wanjiru',
                'password' => Hash::make('tech@123!!'),
                'role' => 'project_manager',
                'phone' => '+254700000003',
                'address' => 'Karen, Nairobi',
                'email_verified_at' => now(),
            ]
        );

        // Clients
        $client1 = User::firstOrCreate(
            ['email' => 'client@technicianworld.co.ke'],
            [
                'name' => 'John Mwangi',
                'password' => Hash::make('client@123!!'),
                'role' => 'client',
                'phone' => '+254711111111',
                'address' => '123 Ngong Road, Nairobi',
                'email_verified_at' => now(),
            ]
        );

        $client2 = User::firstOrCreate(
            ['email' => 'client2@technicianworld.co.ke'],
            [
                'name' => 'Sarah Oduya',
                'password' => Hash::make('client@123!!'),
                'role' => 'client',
                'phone' => '+254722222222',
                'address' => '456 Mombasa Road, Nairobi',
                'email_verified_at' => now(),
            ]
        );

        $client3 = User::firstOrCreate(
            ['email' => 'client3@technicianworld.co.ke'],
            [
                'name' => 'Peter Kamau',
                'password' => Hash::make('client@123!!'),
                'role' => 'client',
                'phone' => '+254733333333',
                'address' => '789 Thika Road, Nairobi',
                'email_verified_at' => now(),
            ]
        );

        // Technician Users
        $techUser1 = User::firstOrCreate(
            ['email' => 'tech@technicianworld.co.ke'],
            [
                'name' => 'Tom Ochieng',
                'password' => Hash::make('tech@123!!'),
                'role' => 'technician',
                'phone' => '+254744444444',
                'email_verified_at' => now(),
            ]
        );

        $techUser2 = User::firstOrCreate(
            ['email' => 'tech2@technicianworld.co.ke'],
            [
                'name' => 'David Kipchoge',
                'password' => Hash::make('tech@123!!'),
                'role' => 'technician',
                'phone' => '+254755555555',
                'email_verified_at' => now(),
            ]
        );

        $techUser3 = User::firstOrCreate(
            ['email' => 'tech3@technicianworld.co.ke'],
            [
                'name' => 'Grace Akinyi',
                'password' => Hash::make('tech@123!!'),
                'role' => 'technician',
                'phone' => '+254766666666',
                'email_verified_at' => now(),
            ]
        );

        $techUser4 = User::firstOrCreate(
            ['email' => 'tech4@technicianworld.co.ke'],
            [
                'name' => 'Samuel Maina',
                'password' => Hash::make('tech@123!!'),
                'role' => 'technician',
                'phone' => '+254777777777',
                'email_verified_at' => now(),
            ]
        );

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

        Technician::firstOrCreate(
            ['user_id' => $techUser1->id],
            [
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
            ]
        );

        Technician::firstOrCreate(
            ['user_id' => $techUser2->id],
            [
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
            ]
        );

        Technician::firstOrCreate(
            ['user_id' => $techUser3->id],
            [
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
            ]
        );

        Technician::firstOrCreate(
            ['user_id' => $techUser4->id],
            [
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
            ]
        );
    }
}
