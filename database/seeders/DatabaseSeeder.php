<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        // Create client user
        User::factory()->create([
            'name' => 'John Client',
            'email' => 'client@example.com',
            'role' => 'client',
        ]);

        // Create technician user
        $technicianUser = User::factory()->create([
            'name' => 'Tom Technician',
            'email' => 'technician@example.com',
            'role' => 'technician',
        ]);

        // Create service categories
        \App\Models\ServiceCategory::create([
            'name' => 'Electrical',
            'icon' => 'fas fa-bolt',
            'description' => 'All electrical installations and repairs'
        ]);

        \App\Models\ServiceCategory::create([
            'name' => 'Plumbing',
            'icon' => 'fas fa-tint',
            'description' => 'Plumbing installations and repairs'
        ]);

        \App\Models\ServiceCategory::create([
            'name' => 'Painting',
            'icon' => 'fas fa-paint-roller',
            'description' => 'Interior and exterior painting services'
        ]);

        // Create technician profile
        \App\Models\Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-001',
            'specialization' => 'Master Electrician',
            'location' => 'Nairobi',
            'availability' => 'available',
            'rating' => 4.8,
            'total_jobs' => 150,
            'bio' => 'Experienced electrician with over 10 years in the field.',
            'skills' => ['electrical wiring', 'lighting installation', 'electrical repairs']
        ]);
    }
}
