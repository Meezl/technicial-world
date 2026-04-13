<?php

namespace App\Services;

use App\Models\Technician;
use App\Models\ServiceRequest;
use App\Models\TechnicianPayment;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

class TechnicianPerformanceService
{
    /**
     * Get comprehensive performance data for a specific technician
     */
    public function getTechnicianPerformance(int $technicianId): array
    {
        $technician = Technician::with(['user', 'serviceRequests', 'technicianPayments', 'reviews.user'])
            ->findOrFail($technicianId);

        return [
            'technician' => $this->getTechnicianBasicInfo($technician),
            'job_metrics' => $this->getJobMetrics($technician),
            'financial_tracking' => $this->getFinancialTracking($technician),
            'performance_engine' => $this->getPerformanceEngine($technician),
            'match_score' => $this->calculateMatchScore($technician),
        ];
    }

    /**
     * Get technician basic information
     */
    private function getTechnicianBasicInfo(Technician $technician): array
    {
        $matchScore = $this->calculateMatchScore($technician);

        return [
            'id' => $technician->id,
            'name' => $technician->user->name ?? 'N/A',
            'email' => $technician->user->email ?? 'N/A',
            'technician_id' => $technician->technician_id,
            'specialization' => $technician->specialization,
            'location' => $technician->location,
            'availability' => $technician->availability,
            'bio' => $technician->bio,
            'skills' => $technician->skills,
            'is_top_rated' => $matchScore > 80,
            'is_recommended' => $matchScore > 80,
        ];
    }

    /**
     * Calculate job metrics with status breakdown
     */
    private function getJobMetrics(Technician $technician): array
    {
        $serviceRequests = $technician->serviceRequests;

        $totalJobs = $serviceRequests->count();
        $completed = $serviceRequests->where('status', 'completed')->count();
        $ongoing = $serviceRequests->whereIn('status', ['in_progress', 'approved'])->count();
        $pending = $serviceRequests->where('status', 'pending')->count();

        return [
            'total_jobs' => $totalJobs,
            'completed' => $completed,
            'ongoing' => $ongoing,
            'pending' => $pending,
            'completion_rate' => $totalJobs > 0 ? round(($completed / $totalJobs) * 100, 2) : 0,
            'breakdown_percentages' => [
                'completed' => $totalJobs > 0 ? round(($completed / $totalJobs) * 100, 2) : 0,
                'ongoing' => $totalJobs > 0 ? round(($ongoing / $totalJobs) * 100, 2) : 0,
                'pending' => $totalJobs > 0 ? round(($pending / $totalJobs) * 100, 2) : 0,
            ],
        ];
    }

    /**
     * Calculate financial tracking data
     */
    private function getFinancialTracking(Technician $technician): array
    {
        // Total Revenue from all jobs
        $totalRevenue = $technician->serviceRequests()
            ->whereNotNull('revenue_generated')
            ->sum('revenue_generated');

        // Total Earned Payouts (what technician should receive)
        $totalEarnedPayouts = $technician->serviceRequests()
            ->whereNotNull('technician_payout')
            ->sum('technician_payout');

        // Payments Made to Technician
        $paymentsMade = $technician->technicianPayments()
            ->where('status', 'completed')
            ->sum('amount');

        // Outstanding Balance
        $outstandingBalance = $totalEarnedPayouts - $paymentsMade;

        // Recent payments
        $recentPayments = $technician->technicianPayments()
            ->with('serviceRequest')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'payment_id' => $payment->payment_id,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'payment_method' => $payment->payment_method,
                    'transaction_reference' => $payment->transaction_reference,
                    'paid_at' => $payment->paid_at,
                    'service_request_id' => $payment->service_request_id,
                    'notes' => $payment->notes,
                    'created_at' => $payment->created_at,
                ];
            });

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_earned_payouts' => round($totalEarnedPayouts, 2),
            'payments_made' => round($paymentsMade, 2),
            'outstanding_balance' => round($outstandingBalance, 2),
            'recent_payments' => $recentPayments,
        ];
    }

    /**
     * Get performance engine data (ratings and feedback)
     */
    private function getPerformanceEngine(Technician $technician): array
    {
        // Calculate from Reviews table
        $reviews = $technician->reviews()->with('user')->get();
        $averageRating = $reviews->avg('rating') ?? 0;

        // Also check service_requests rating field for backward compatibility
        $serviceRequestRatings = $technician->serviceRequests()
            ->whereNotNull('rating')
            ->pluck('rating');

        // Combine both sources
        $allRatings = $reviews->pluck('rating')->concat($serviceRequestRatings);
        $finalAverageRating = $allRatings->count() > 0 ? $allRatings->avg() : 0;

        $totalReviews = $reviews->count() + $serviceRequestRatings->count();

        // Get feedback comments
        $feedback = $reviews->map(function ($review) {
            return [
                'id' => $review->id,
                'rating' => (float) $review->rating,
                'comment' => $review->comment,
                'client_name' => $review->user->name ?? 'Anonymous',
                'created_at' => $review->created_at,
            ];
        });

        // Also include reviews from service_requests
        $serviceRequestReviews = $technician->serviceRequests()
            ->whereNotNull('review')
            ->whereNotNull('rating')
            ->with('user')
            ->get()
            ->map(function ($request) {
                return [
                    'id' => 'sr_' . $request->id,
                    'rating' => (float) $request->rating,
                    'comment' => $request->review,
                    'client_name' => $request->user->name ?? 'Anonymous',
                    'created_at' => $request->completed_date ?? $request->updated_at,
                ];
            });

        $allFeedback = $feedback->concat($serviceRequestReviews)->sortByDesc('created_at')->values();

        return [
            'average_rating' => round($finalAverageRating, 2),
            'total_reviews' => $totalReviews,
            'feedback' => $allFeedback,
            'rating_distribution' => [
                '5_star' => $allRatings->where('>=', 4.5)->count(),
                '4_star' => $allRatings->whereBetween('>=', [3.5, 4.5])->count(),
                '3_star' => $allRatings->whereBetween('>=', [2.5, 3.5])->count(),
                '2_star' => $allRatings->whereBetween('>=', [1.5, 2.5])->count(),
                '1_star' => $allRatings->where('<', 1.5)->count(),
            ],
        ];
    }

    /**
     * Calculate Technician Match Score (0-100)
     * Weighted: 50% rating, 30% completion rate, 20% workload
     */
    public function calculateMatchScore(Technician $technician): float
    {
        // 50% weight on average rating
        $reviews = $technician->reviews;
        $serviceRequestRatings = $technician->serviceRequests()
            ->whereNotNull('rating')
            ->pluck('rating');

        $allRatings = $reviews->pluck('rating')->concat($serviceRequestRatings);
        $averageRating = $allRatings->count() > 0 ? $allRatings->avg() : 0;
        $ratingScore = ($averageRating / 5) * 50;

        // 30% weight on job completion rate
        $totalJobs = $technician->serviceRequests->count();
        $completedJobs = $technician->serviceRequests->where('status', 'completed')->count();
        $completionRate = $totalJobs > 0 ? ($completedJobs / $totalJobs) : 0;
        $completionScore = $completionRate * 30;

        // 20% weight on current workload (inverse - lower workload = higher score)
        $ongoingJobs = $technician->serviceRequests()
            ->whereIn('status', ['in_progress', 'approved', 'pending'])
            ->count();

        // Normalize workload (assuming 10+ jobs is max workload)
        $workloadFactor = max(0, 1 - ($ongoingJobs / 10));
        $workloadScore = $workloadFactor * 20;

        $totalScore = $ratingScore + $completionScore + $workloadScore;

        return round($totalScore, 2);
    }

    /**
     * Get top recommended technicians for job assignment
     */
    public function getTopRecommendedTechnicians(int $limit = 3, ?string $specialization = null): array
    {
        $query = Technician::with(['user', 'serviceRequests']);

        if ($specialization) {
            $query->where('specialization', $specialization);
        }

        $technicians = $query->get();

        $recommendations = $technicians->map(function ($technician) {
            $matchScore = $this->calculateMatchScore($technician);
            $jobMetrics = $this->getJobMetrics($technician);
            $performanceEngine = $this->getPerformanceEngine($technician);

            return [
                'id' => $technician->id,
                'name' => $technician->user->name ?? 'N/A',
                'technician_id' => $technician->technician_id,
                'specialization' => $technician->specialization,
                'location' => $technician->location,
                'availability' => $technician->availability,
                'match_score' => $matchScore,
                'average_rating' => $performanceEngine['average_rating'],
                'total_jobs' => $jobMetrics['total_jobs'],
                'completion_rate' => $jobMetrics['completion_rate'],
                'ongoing_jobs' => $jobMetrics['ongoing'],
                'is_top_rated' => $matchScore > 80,
            ];
        });

        return $recommendations
            ->sortByDesc('match_score')
            ->take($limit)
            ->values()
            ->toArray();
    }

    /**
     * Get all technicians with their performance scores
     */
    public function getAllTechniciansWithPerformance(): array
    {
        $technicians = Technician::with(['user', 'serviceRequests', 'reviews'])->get();

        return $technicians->map(function ($technician) {
            $matchScore = $this->calculateMatchScore($technician);
            $jobMetrics = $this->getJobMetrics($technician);
            $performanceEngine = $this->getPerformanceEngine($technician);
            $financial = $this->getFinancialTracking($technician);

            return [
                'id' => $technician->id,
                'name' => $technician->user->name ?? 'N/A',
                'technician_id' => $technician->technician_id,
                'specialization' => $technician->specialization,
                'location' => $technician->location,
                'match_score' => $matchScore,
                'average_rating' => $performanceEngine['average_rating'],
                'total_jobs' => $jobMetrics['total_jobs'],
                'completion_rate' => $jobMetrics['completion_rate'],
                'ongoing_jobs' => $jobMetrics['ongoing'],
                'outstanding_balance' => $financial['outstanding_balance'],
                'is_top_rated' => $matchScore > 80,
            ];
        })->sortByDesc('match_score')->values()->toArray();
    }
}
