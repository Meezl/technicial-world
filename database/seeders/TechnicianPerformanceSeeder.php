<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Technician;
use App\Models\ServiceRequest;
use App\Models\ServiceCategory;
use App\Models\TechnicianPayment;
use App\Models\Review;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TechnicianPerformanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating test technicians and performance data...');

        // Create service categories if they don't exist
        $categories = [
            'Electrical',
            'Plumbing',
            'HVAC',
            'Carpentry',
            'Painting',
        ];

        foreach ($categories as $categoryName) {
            ServiceCategory::firstOrCreate(
                ['name' => $categoryName],
                ['description' => "Professional {$categoryName} services"]
            );
        }

        $serviceCategories = ServiceCategory::all();

        // Create test clients
        $clients = [];
        for ($i = 1; $i <= 5; $i++) {
            $clients[] = User::firstOrCreate(
                ['email' => "client{$i}@test.com"],
                [
                    'name' => "Test Client {$i}",
                    'password' => bcrypt('password'),
                    'role' => 'client',
                ]
            );
        }

        // Create technicians with varying performance levels
        $technicianData = [
            // Top performer
            [
                'name' => 'John Excellence',
                'email' => 'john.excellence@tech.com',
                'specialization' => 'Electrical',
                'availability' => 'available',
                'location' => 'Nairobi',
                'bio' => 'Highly experienced electrical technician with 10+ years in the field',
                'skills' => ['Wiring', 'Solar Installation', 'Repairs', 'Maintenance'],
                'jobs_config' => ['completed' => 25, 'ongoing' => 2, 'pending' => 1, 'avg_rating' => 4.8]
            ],
            // Good performer
            [
                'name' => 'Sarah Plumber',
                'email' => 'sarah.plumber@tech.com',
                'specialization' => 'Plumbing',
                'availability' => 'available',
                'location' => 'Mombasa',
                'bio' => 'Expert plumber specializing in residential and commercial projects',
                'skills' => ['Pipe Installation', 'Leak Repairs', 'Drain Cleaning'],
                'jobs_config' => ['completed' => 18, 'ongoing' => 3, 'pending' => 2, 'avg_rating' => 4.5]
            ],
            // Average performer
            [
                'name' => 'Mike HVAC',
                'email' => 'mike.hvac@tech.com',
                'specialization' => 'HVAC',
                'availability' => 'available',
                'location' => 'Nairobi',
                'bio' => 'HVAC specialist with focus on air conditioning systems',
                'skills' => ['AC Installation', 'AC Repair', 'Ventilation'],
                'jobs_config' => ['completed' => 12, 'ongoing' => 5, 'pending' => 3, 'avg_rating' => 3.8]
            ],
            // New technician
            [
                'name' => 'David Carpenter',
                'email' => 'david.carpenter@tech.com',
                'specialization' => 'Carpentry',
                'availability' => 'available',
                'location' => 'Kisumu',
                'bio' => 'Skilled carpenter specializing in custom furniture',
                'skills' => ['Furniture Making', 'Cabinet Installation', 'Wood Repairs'],
                'jobs_config' => ['completed' => 5, 'ongoing' => 1, 'pending' => 1, 'avg_rating' => 4.2]
            ],
            // Busy technician
            [
                'name' => 'Lisa Painter',
                'email' => 'lisa.painter@tech.com',
                'specialization' => 'Painting',
                'availability' => 'busy',
                'location' => 'Nairobi',
                'bio' => 'Professional painter for interior and exterior projects',
                'skills' => ['Interior Painting', 'Exterior Painting', 'Decorative Finishes'],
                'jobs_config' => ['completed' => 20, 'ongoing' => 8, 'pending' => 4, 'avg_rating' => 4.3]
            ],
        ];

        foreach ($technicianData as $data) {
            $this->command->info("Creating technician: {$data['name']}");

            // Create user
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => bcrypt('password'),
                    'role' => 'technician',
                ]
            );

            // Create technician
            $technician = Technician::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'technician_id' => 'TECH-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
                    'specialization' => $data['specialization'],
                    'availability' => $data['availability'],
                    'location' => $data['location'],
                    'bio' => $data['bio'],
                    'skills' => $data['skills'],
                ]
            );

            $jobsConfig = $data['jobs_config'];
            $category = $serviceCategories->where('name', $data['specialization'])->first()
                        ?? $serviceCategories->random();

            // Create completed jobs
            for ($i = 0; $i < $jobsConfig['completed']; $i++) {
                $this->createServiceRequest($technician, $clients, $category, 'completed', $jobsConfig['avg_rating']);
            }

            // Create ongoing jobs
            for ($i = 0; $i < $jobsConfig['ongoing']; $i++) {
                $this->createServiceRequest($technician, $clients, $category, 'in_progress');
            }

            // Create pending jobs
            for ($i = 0; $i < $jobsConfig['pending']; $i++) {
                $this->createServiceRequest($technician, $clients, $category, 'pending');
            }

            $this->command->info("Created {$jobsConfig['completed']} completed, {$jobsConfig['ongoing']} ongoing, {$jobsConfig['pending']} pending jobs");
        }

        $this->command->info('Seed data created successfully!');
    }

    private function createServiceRequest($technician, $clients, $category, $status, $avgRating = null)
    {
        $client = $clients[array_rand($clients)];
        $baseAmount = rand(5000, 50000);
        $revenue = $baseAmount * 1.2; // 20% markup
        $payout = $baseAmount * 0.7; // Technician gets 70% of base

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(Str::random(8)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'technician_id' => $technician->id,
            'description' => "Test service request for {$category->name}",
            'location' => $technician->location,
            'urgency' => ['low', 'medium', 'high'][rand(0, 2)],
            'status' => $status,
            'quoted_amount' => $baseAmount,
            'final_amount' => $status === 'completed' ? $baseAmount : null,
            'revenue_generated' => $status === 'completed' ? $revenue : null,
            'technician_payout' => $status === 'completed' ? $payout : null,
            'scheduled_date' => Carbon::now()->subDays(rand(1, 60)),
            'started_at' => in_array($status, ['in_progress', 'completed']) ? Carbon::now()->subDays(rand(1, 30)) : null,
            'completed_date' => $status === 'completed' ? Carbon::now()->subDays(rand(1, 20)) : null,
            'assigned_at' => Carbon::now()->subDays(rand(1, 60)),
        ]);

        // Add reviews and ratings for completed jobs
        if ($status === 'completed' && $avgRating) {
            // Add some variance to the rating
            $rating = max(1, min(5, $avgRating + (rand(-5, 5) / 10)));

            // Add rating to service request (backward compatibility)
            $serviceRequest->update([
                'rating' => $rating,
                'review' => $this->getRandomReview($rating),
            ]);

            // Also create a review in the reviews table (round to integer)
            Review::create([
                'service_request_id' => $serviceRequest->id,
                'technician_id' => $technician->id,
                'user_id' => $client->id,
                'rating' => round($rating), // Reviews table expects integer 1-5
                'comment' => $this->getRandomReview($rating),
            ]);

            // Create payment records (some paid, some pending)
            $paymentStatus = rand(1, 10) > 3 ? 'completed' : 'pending'; // 70% paid

            if ($paymentStatus === 'completed') {
                TechnicianPayment::create([
                    'payment_id' => 'PAY-' . strtoupper(Str::random(10)),
                    'technician_id' => $technician->id,
                    'service_request_id' => $serviceRequest->id,
                    'amount' => $payout,
                    'status' => 'completed',
                    'payment_method' => ['M-Pesa', 'Bank Transfer', 'Cash'][rand(0, 2)],
                    'transaction_reference' => 'TXN-' . strtoupper(Str::random(12)),
                    'paid_at' => Carbon::now()->subDays(rand(1, 15)),
                    'notes' => 'Payment for service request ' . $serviceRequest->request_id,
                ]);
            }
        }
    }

    private function getRandomReview($rating)
    {
        $positiveReviews = [
            'Excellent work! Very professional and efficient.',
            'Great service! The technician was punctual and skilled.',
            'Highly recommend! Did an amazing job.',
            'Very satisfied with the quality of work.',
            'Outstanding service! Will definitely hire again.',
            'Professional and knowledgeable. Great experience!',
        ];

        $averageReviews = [
            'Good work overall, but took longer than expected.',
            'Decent service. Got the job done.',
            'Satisfactory work. Could be better.',
            'Average experience. Nothing exceptional.',
        ];

        $negativeReviews = [
            'Not satisfied with the work quality.',
            'Had to call back for fixes.',
            'Service was okay but not great.',
        ];

        if ($rating >= 4.5) {
            return $positiveReviews[array_rand($positiveReviews)];
        } elseif ($rating >= 3.5) {
            return $averageReviews[array_rand($averageReviews)];
        } else {
            return $negativeReviews[array_rand($negativeReviews)];
        }
    }
}
