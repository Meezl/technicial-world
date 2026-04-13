<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PMPerformanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PMPerformanceController extends Controller
{
    protected $performanceService;

    public function __construct(PMPerformanceService $performanceService)
    {
        $this->performanceService = $performanceService;
    }

    /**
     * Display PM performance dashboard (list all PMs)
     */
    public function index()
    {
        $projectManagers = $this->performanceService->getAllPMsWithPerformance();

        return Inertia::render('Admin/PMPerformance/Index', [
            'projectManagers' => $projectManagers,
        ]);
    }

    /**
     * Display specific PM performance details
     */
    public function show(Request $request, $id)
    {
        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $performance = $this->performanceService->getPMPerformance($id, $startDate, $endDate);

            return Inertia::render('Admin/PMPerformance/Show', [
                'performance' => $performance,
            ]);
        } catch (\Exception $e) {
            \Log::error('PM Performance Error: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());

            return redirect()->route('admin.pm-performance.index')
                ->with('error', 'Failed to load PM performance data: ' . $e->getMessage());
        }
    }

    /**
     * API: Get PM performance data
     */
    public function getPerformance(Request $request, $id)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $performance = $this->performanceService->getPMPerformance($id, $startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => $performance,
        ]);
    }

    /**
     * API: Get all PMs with performance metrics
     */
    public function getAllPerformance()
    {
        $projectManagers = $this->performanceService->getAllPMsWithPerformance();

        return response()->json([
            'success' => true,
            'data' => $projectManagers,
        ]);
    }

    /**
     * API: Get top performing PMs
     */
    public function getTopPerformers(Request $request)
    {
        $limit = $request->input('limit', 5);

        $topPerformers = $this->performanceService->getTopPerformers($limit);

        return response()->json([
            'success' => true,
            'data' => $topPerformers,
        ]);
    }
}
