<?php

namespace App\Services;

use App\Models\User;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PMPerformanceService
{
    /**
     * Get comprehensive performance data for a specific Project Manager
     */
    public function getPMPerformance(int $managerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $manager = User::findOrFail($managerId);

        $query = Project::where('manager_id', $managerId);

        // Apply date filters if provided
        if ($startDate) {
            $query->where('created_at', '>=', Carbon::parse($startDate));
        }
        if ($endDate) {
            $query->where('created_at', '<=', Carbon::parse($endDate));
        }

        $projects = $query->get();

        // If no projects found, return empty structure
        if ($projects->isEmpty()) {
            return [
                'manager' => $this->getManagerBasicInfo($manager),
                'project_metrics' => $this->getEmptyProjectMetrics(),
                'financial_metrics' => $this->getEmptyFinancialMetrics(),
                'time_series' => ['revenue_by_month' => [], 'completions_by_month' => []],
                'recent_projects' => [],
            ];
        }

        return [
            'manager' => $this->getManagerBasicInfo($manager),
            'project_metrics' => $this->getProjectMetrics($projects),
            'financial_metrics' => $this->getFinancialMetrics($projects),
            'time_series' => $this->getTimeSeries($managerId, $startDate, $endDate),
            'recent_projects' => $this->getRecentProjects($projects),
        ];
    }

    /**
     * Get manager basic information
     */
    private function getManagerBasicInfo(User $manager): array
    {
        return [
            'id' => $manager->id,
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => $manager->role,
        ];
    }

    /**
     * Calculate project metrics
     */
    private function getProjectMetrics($projects): array
    {
        $totalProjects = $projects->count();
        $activeProjects = $projects->where('status', Project::STATUS_ACTIVE)->count();
        $completedProjects = $projects->where('status', Project::STATUS_COMPLETED)->count();
        $pendingProjects = $projects->where('status', Project::STATUS_PLANNING)->count();
        $onHoldProjects = $projects->where('status', Project::STATUS_ON_HOLD)->count();

        $completionRate = $totalProjects > 0 ? round(($completedProjects / $totalProjects) * 100, 2) : 0;

        return [
            'total_projects' => $totalProjects,
            'active_projects' => $activeProjects,
            'completed_projects' => $completedProjects,
            'pending_projects' => $pendingProjects,
            'on_hold_projects' => $onHoldProjects,
            'completion_rate' => $completionRate,
            'status_distribution' => [
                'active' => $activeProjects,
                'completed' => $completedProjects,
                'pending' => $pendingProjects,
                'on_hold' => $onHoldProjects,
            ],
        ];
    }

    /**
     * Calculate financial metrics
     */
    private function getFinancialMetrics($projects): array
    {
        $totalRevenue = $projects->sum('revenue_generated') ?? 0;
        $totalBudget = $projects->sum('budget_amount') ?? 0;
        $totalActualCost = $projects->sum('actual_cost') ?? 0;

        // Profit margin calculation
        $profitMargin = $totalRevenue > 0 ? round((($totalRevenue - $totalActualCost) / $totalRevenue) * 100, 2) : 0;

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_budget' => round($totalBudget, 2),
            'total_actual_cost' => round($totalActualCost, 2),
            'profit_margin' => $profitMargin,
            'avg_project_revenue' => $projects->count() > 0 ? round($totalRevenue / $projects->count(), 2) : 0,
        ];
    }

    /**
     * Get time series data for charts (last 6 months)
     */
    private function getTimeSeries(int $managerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $start = $startDate ? Carbon::parse($startDate) : Carbon::now()->subMonths(6);
        $end = $endDate ? Carbon::parse($endDate) : Carbon::now();

        // Revenue by month
        $revenueByMonth = Project::where('manager_id', $managerId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('revenue_generated')
            ->select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('SUM(revenue_generated) as total_revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => $item->month,
                    'revenue' => round($item->total_revenue, 2),
                ];
            });

        // Completions by month
        $completionsByMonth = Project::where('manager_id', $managerId)
            ->where('status', Project::STATUS_COMPLETED)
            ->whereBetween('updated_at', [$start, $end])
            ->select(
                DB::raw('DATE_FORMAT(updated_at, "%Y-%m") as month'),
                DB::raw('COUNT(*) as completions')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => $item->month,
                    'completions' => $item->completions,
                ];
            });

        return [
            'revenue_by_month' => $revenueByMonth,
            'completions_by_month' => $completionsByMonth,
        ];
    }

    /**
     * Get recent projects for the manager
     */
    private function getRecentProjects($projects): array
    {
        return $projects->sortByDesc('created_at')->take(10)->map(function ($project) {
            return [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'progress_percentage' => $project->progress_percentage,
                'budget_amount' => $project->budget_amount,
                'revenue_generated' => $project->revenue_generated,
                'actual_cost' => $project->actual_cost,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'created_at' => $project->created_at,
            ];
        })->values()->toArray();
    }

    /**
     * Get all project managers with their performance metrics
     */
    public function getAllPMsWithPerformance(): array
    {
        $projectManagers = User::where('role', 'admin')->get(); // Adjust role as needed

        return $projectManagers->map(function ($manager) {
            $projects = Project::where('manager_id', $manager->id)->get();
            $metrics = $this->getProjectMetrics($projects);
            $financial = $this->getFinancialMetrics($projects);

            return [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
                'total_projects' => $metrics['total_projects'],
                'active_projects' => $metrics['active_projects'],
                'completion_rate' => $metrics['completion_rate'],
                'total_revenue' => $financial['total_revenue'],
                'efficiency_score' => $this->calculateEfficiencyScore($metrics, $financial),
            ];
        })->sortByDesc('efficiency_score')->values()->toArray();
    }

    /**
     * Get empty project metrics structure
     */
    private function getEmptyProjectMetrics(): array
    {
        return [
            'total_projects' => 0,
            'active_projects' => 0,
            'completed_projects' => 0,
            'pending_projects' => 0,
            'on_hold_projects' => 0,
            'completion_rate' => 0,
            'status_distribution' => [
                'active' => 0,
                'completed' => 0,
                'pending' => 0,
                'on_hold' => 0,
            ],
        ];
    }

    /**
     * Get empty financial metrics structure
     */
    private function getEmptyFinancialMetrics(): array
    {
        return [
            'total_revenue' => 0,
            'total_budget' => 0,
            'total_actual_cost' => 0,
            'profit_margin' => 0,
            'avg_project_revenue' => 0,
        ];
    }

    /**
     * Calculate efficiency score (0-100)
     * Based on: 40% completion rate, 30% project volume, 30% revenue per project
     */
    private function calculateEfficiencyScore(array $metrics, array $financial): float
    {
        // Completion rate score (40%)
        $completionScore = $metrics['completion_rate'] * 0.4;

        // Project volume score (30%) - normalized to 100 based on 20+ projects
        $volumeScore = min(100, ($metrics['total_projects'] / 20) * 100) * 0.3;

        // Revenue efficiency score (30%) - normalized to 100 based on $50k+ avg
        $revenuePerProject = $financial['avg_project_revenue'];
        $revenueScore = min(100, ($revenuePerProject / 50000) * 100) * 0.3;

        $totalScore = $completionScore + $volumeScore + $revenueScore;

        return round($totalScore, 2);
    }

    /**
     * Get top performing PMs
     */
    public function getTopPerformers(int $limit = 5): array
    {
        $allPMs = $this->getAllPMsWithPerformance();

        return array_slice($allPMs, 0, $limit);
    }
}
