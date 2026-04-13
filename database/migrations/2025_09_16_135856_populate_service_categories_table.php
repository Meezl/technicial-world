<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $categories = [
            [
                'name' => 'Electrical Services',
                'description' => 'From new installations to repairs and safety checks, our certified electricians ensure your property is powered safely and efficiently.',
                'icon' => 'fas fa-bolt',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Plumbing & Fitting',
                'description' => 'We handle everything from leaky faucets to complete piping systems for new constructions, ensuring reliable water flow and drainage.',
                'icon' => 'fas fa-tint',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Painting & Decorating',
                'description' => 'Our professional painters provide high-quality interior and exterior painting services to give your space a fresh, new look.',
                'icon' => 'fas fa-paint-roller',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Tiling & Flooring',
                'description' => 'Expert installation of various flooring types, including ceramic tiles, hardwood, and more for both residential and commercial projects.',
                'icon' => 'fas fa-th',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Carpentry & Woodwork',
                'description' => 'Custom furniture, built-in storage solutions, door and window installations, and general carpentry services.',
                'icon' => 'fas fa-hammer',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'HVAC Services',
                'description' => 'Heating, ventilation, and air conditioning installation, maintenance, and repair services.',
                'icon' => 'fas fa-fan',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Masonry & Construction',
                'description' => 'Structural work, concrete services, brickwork, and general construction projects.',
                'icon' => 'fas fa-hard-hat',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Roofing Services',
                'description' => 'Roof installation, repairs, maintenance, and waterproofing services for residential and commercial properties.',
                'icon' => 'fas fa-home',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Landscaping & Gardening',
                'description' => 'Garden design, lawn care, irrigation systems, and outdoor space maintenance.',
                'icon' => 'fas fa-seedling',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'General Maintenance',
                'description' => 'Property maintenance, repairs, and general handyman services for homes and offices.',
                'icon' => 'fas fa-tools',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('service_categories')->insert($categories);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('service_categories')->truncate();
    }
};
