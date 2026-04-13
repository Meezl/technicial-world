<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Project;
use Carbon\Carbon;

class PMPerformanceSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating test Project Managers and projects...');

        $pms = [
            ['name' => 'Alice Johnson', 'email' => 'alice.johnson@pm.com', 'projects' => 25, 'completion_rate' => 85, 'avg_revenue' => 45000],
            ['name' => 'Bob Williams', 'email' => 'bob.williams@pm.com', 'projects' => 18, 'completion_rate' => 78, 'avg_revenue' => 38000],
            ['name' => 'Carol Davis', 'email' => 'carol.davis@pm.com', 'projects' => 12, 'completion_rate' => 90, 'avg_revenue' => 52000],
        ];

        foreach ($pms as $pmData) {
            $this->command->info("Creating PM: {$pmData['name']}");

            $pm = User::firstOrCreate(
                ['email' => $pmData['email']],
                [
                    'name' => $pmData['name'],
                    'password' => bcrypt('password'),
                    'role' => 'admin',
                ]
            );

            $totalProjects = $pmData['projects'];
            $completedCount = round($totalProjects * ($pmData['completion_rate'] / 100));
            $activeCount = round($totalProjects * 0.2);
            $pendingCount = $totalProjects - $completedCount - $activeCount;

            // Create completed projects
            for ($i = 0; $i < $completedCount; $i++) {
                $this->createProject($pm->id, 'completed', $pmData['avg_revenue']);
            }

            // Create active projects
            for ($i = 0; $i < $activeCount; $i++) {
                $this->createProject($pm->id, 'active', $pmData['avg_revenue']);
            }

            // Create pending projects
            for ($i = 0; $i < $pendingCount; $i++) {
                $this->createProject($pm->id, 'planning', 0);
            }

            $this->command->info("Created {$totalProjects} projects for {$pmData['name']}");
        }

        $this->command->info('PM test data created successfully!');
    }

    private function createProject($managerId, $status, $avgRevenue)
    {
        $budget = rand(20000, 80000);
        $revenue = $status === 'completed' ? $avgRevenue + rand(-10000, 10000) : 0;
        $actualCost = $status === 'completed' ? $budget * 0.75 : 0;

        Project::create([
            'name' => 'Project ' . strtoupper(\Str::random(6)),
            'description' => 'Test project for PM performance tracking',
            'status' => $status,
            'manager_id' => $managerId,
            'created_by' => $managerId,
            'start_date' => Carbon::now()->subDays(rand(30, 180)),
            'end_date' => $status === 'completed' ? Carbon::now()->subDays(rand(1, 30)) : Carbon::now()->addDays(rand(30, 90)),
            'progress_percentage' => $status === 'completed' ? 100 : ($status === 'active' ? rand(20, 80) : 0),
            'budget_amount' => $budget,
            'actual_cost' => $actualCost,
            'revenue_generated' => $revenue,
        ]);
    }
}
