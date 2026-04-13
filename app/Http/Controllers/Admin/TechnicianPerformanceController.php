<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TechnicianPerformanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TechnicianPerformanceController extends Controller
{
    protected $performanceService;

    public function __construct(TechnicianPerformanceService $performanceService)
    {
        $this->performanceService = $performanceService;
    }

    /**
     * Display technician performance dashboard
     */
    public function index()
    {
        $technicians = $this->performanceService->getAllTechniciansWithPerformance();

        return Inertia::render('Admin/TechnicianPerformance/Index', [
            'technicians' => $technicians,
        ]);
    }

    /**
     * Get specific technician performance details
     */
    public function show($id)
    {
        try {
            $performance = $this->performanceService->getTechnicianPerformance($id);

            return Inertia::render('Admin/TechnicianPerformance/Show', [
                'performance' => $performance,
            ]);
        } catch (\Exception $e) {
            \Log::error('Technician Performance Error: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());

            return redirect()->route('admin.technician-performance.index')
                ->with('error', 'Failed to load technician performance data: ' . $e->getMessage());
        }
    }

    /**
     * API: Get technician performance data
     */
    public function getPerformance($id)
    {
        $performance = $this->performanceService->getTechnicianPerformance($id);

        return response()->json([
            'success' => true,
            'data' => $performance,
        ]);
    }

    /**
     * API: Get all technicians with performance metrics
     */
    public function getAllPerformance()
    {
        $technicians = $this->performanceService->getAllTechniciansWithPerformance();

        return response()->json([
            'success' => true,
            'data' => $technicians,
        ]);
    }

    /**
     * API: Get top recommended technicians
     */
    public function getRecommendations(Request $request)
    {
        $limit = $request->input('limit', 3);
        $specialization = $request->input('specialization', null);

        $recommendations = $this->performanceService->getTopRecommendedTechnicians($limit, $specialization);

        return response()->json([
            'success' => true,
            'data' => $recommendations,
        ]);
    }

    /**
     * API: Calculate match score for a technician
     */
    public function getMatchScore($id)
    {
        $technician = \App\Models\Technician::findOrFail($id);
        $matchScore = $this->performanceService->calculateMatchScore($technician);

        return response()->json([
            'success' => true,
            'data' => [
                'technician_id' => $id,
                'match_score' => $matchScore,
            ],
        ]);
    }
}
