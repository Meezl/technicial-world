<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\ServiceSubTask;
use App\Models\ServiceRequest;
use App\Models\Tool;

class TechnicianController extends Controller
{
    /**
     * Display the technician dashboard.
     */
    public function dashboard(): Response
    {
        $user = auth()->user();
        $technician = $user->technician;

        $incomingJobs = collect();
        $activeJobs = collect();

        if ($technician) {
            // Incoming: assigned but not yet started
            $incomingJobs = ServiceRequest::where('technician_id', $technician->id)
                ->where('status', 'assigned')
                ->with(['user', 'serviceCategory'])
                ->latest()
                ->get();

            // Active: in progress
            $activeJobs = ServiceRequest::where('technician_id', $technician->id)
                ->where('status', 'in_progress')
                ->with(['user', 'serviceCategory'])
                ->latest()
                ->get();

            // Also include sub-tasks assigned to this technician
            $subTaskJobIds = ServiceSubTask::where('technician_id', $technician->id)
                ->whereIn('status', ['assigned', 'in_progress'])
                ->pluck('service_request_id')
                ->unique();

            if ($subTaskJobIds->isNotEmpty()) {
                $subTaskIncoming = ServiceRequest::whereIn('id', $subTaskJobIds)
                    ->where('status', 'assigned')
                    ->where('technician_id', '!=', $technician->id)
                    ->with(['user', 'serviceCategory'])
                    ->get();

                $subTaskActive = ServiceRequest::whereIn('id', $subTaskJobIds)
                    ->where('status', 'in_progress')
                    ->where('technician_id', '!=', $technician->id)
                    ->with(['user', 'serviceCategory'])
                    ->get();

                $incomingJobs = $incomingJobs->merge($subTaskIncoming)->unique('id');
                $activeJobs = $activeJobs->merge($subTaskActive)->unique('id');
            }
        }

        return Inertia::render('Technician/Dashboard', [
            'technician' => $technician,
            'incomingJobs' => $incomingJobs->values(),
            'activeJobs' => $activeJobs->values(),
            'completedJobsCount' => $this->calculateCompletedJobs($technician),
        ]);
    }

    private function calculateCompletedJobs($technician)
    {
        if (!$technician)
            return 0;

        // Direct completed jobs
        $directCompletedCount = ServiceRequest::where('technician_id', $technician->id)
            ->where('status', 'completed')
            ->count();

        // Sub-task related completed jobs
        $subTaskJobIds = ServiceSubTask::where('technician_id', $technician->id)
            ->pluck('service_request_id')
            ->unique();

        $subTaskCompletedCount = 0;
        if ($subTaskJobIds->isNotEmpty()) {
            $subTaskCompletedCount = ServiceRequest::whereIn('id', $subTaskJobIds)
                ->where('technician_id', '!=', $technician->id) // Avoid double counting if tech is both lead and sub (unlikely but safe)
                ->where('status', 'completed')
                ->count();
        }

        return $directCompletedCount + $subTaskCompletedCount;
    }

    /**
     * Display the technician's jobs list.
     */
    public function jobs(): Response
    {
        $user = auth()->user();
        $technician = $user->technician;

        $jobs = collect();

        if ($technician) {
            // Direct assignments
            $directJobs = ServiceRequest::where('technician_id', $technician->id)
                ->with(['user', 'serviceCategory', 'subTasks'])
                ->get();

            // Sub-task assignments (jobs where technician has assigned sub-tasks but isn't the main technician)
            $subTaskJobIds = ServiceSubTask::where('technician_id', $technician->id)
                ->pluck('service_request_id')
                ->unique();

            $subTaskJobs = ServiceRequest::whereIn('id', $subTaskJobIds)
                ->where('technician_id', '!=', $technician->id)
                ->with(['user', 'serviceCategory', 'subTasks'])
                ->get();

            $jobs = $directJobs->merge($subTaskJobs)->unique('id')
                ->sortBy(function ($job) {
                    $statusOrder = [
                        'in_progress' => 0,
                        'assigned' => 1,
                        'pending' => 2,
                        'completed' => 3,
                        'cancelled' => 4,
                    ];
                    return $statusOrder[$job->status] ?? 5;
                })
                ->values();
        }

        return Inertia::render('Technician/Jobs', [
            'technician' => $technician,
            'jobs' => $jobs,
        ]);
    }

    /**
     * Display the job details.
     */
    public function show(ServiceRequest $serviceRequest): Response
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            abort(403, 'Unauthorized action.');
        }

        // Check if technician is assigned or is a sub-task assignee
        $isAssigned = $serviceRequest->technician_id === $technician->id;
        $isSubTaskAssignee = $serviceRequest->subTasks()->where('technician_id', $technician->id)->exists();

        if (!$isAssigned && !$isSubTaskAssignee) {
            abort(403, 'Unauthorized action.');
        }

        $serviceRequest->load(['user', 'serviceCategory', 'subTasks.technician.user', 'tools']);

        return Inertia::render('Technician/JobDetails', [
            'technician' => $technician,
            'job' => $serviceRequest,
        ]);
    }

    /**
     * Display the technician's tools.
     */
    public function tools(): Response
    {
        $user = auth()->user();
        $technician = $user->technician;

        $issuedTools = collect();
        $returnHistory = collect();

        if ($technician) {
            $issuedTools = Tool::where('technician_id', $technician->id)
                ->where('status', 'issued') // Using string 'issued' as verified in previous context
                ->with('serviceRequest')
                ->get();

            // Recently returned tools (tools that were returned and have this technician in their history)
            // Note: This assumes we track history or simply showing tools returned by this tech.
            // For now, let's just query tools returned by this technician if we had a returned_by column,
            // but since we might not, we'll list tools that are available but were last assigned to this tech if we had history.
            // A simpler approach for now based on typical schema:
            $returnHistory = Tool::where('technician_id', $technician->id)
                ->where('status', '!=', 'issued')
                ->limit(20)
                ->get();
        }

        return Inertia::render('Technician/Tools', [
            'technician' => $technician,
            'issuedTools' => $issuedTools,
            'returnHistory' => $returnHistory,
        ]);
    }

    /**
     * Display the technician's profile.
     */
    public function profile(): Response
    {
        $user = auth()->user();
        $technician = $user->technician;

        return Inertia::render('Technician/Profile', [
            'technician' => $technician,
        ]);
    }

    /**
     * Toggle technician availability.
     */
    public function updateAvailability(Request $request)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            return back()->with('error', 'Technician profile not found');
        }

        $request->validate([
            'availability' => 'required|in:available,busy',
        ]);

        $technician->update([
            'availability' => $request->availability,
        ]);

        return back()->with('success', 'Availability updated');
    }

    /**
     * Update job status (en_route, on_site, completed).
     */
    public function updateJobStatus(Request $request, ServiceRequest $serviceRequest)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician || $serviceRequest->technician_id !== $technician->id) {
            // Allow if subtask assignee? For now restrict to main tech for job status.
            return back()->with('error', 'Unauthorized');
        }

        $request->validate([
            'action' => 'required|in:en_route,on_site,completed',
        ]);

        $action = $request->action;

        if ($action === 'en_route') {
            $serviceRequest->update([
                'status' => 'in_progress',
                'started_at' => $serviceRequest->started_at ?? now(),
                // 'progress_percentage' => 25, // Optional: managed by subtasks usually
            ]);

            // Automatically set technician status to busy
            $technician->update(['availability' => 'busy']);
        } elseif ($action === 'on_site') {
            $serviceRequest->update([
                'technician_arrived' => true,
                // 'progress_percentage' => 50,
            ]);
        } elseif ($action === 'completed') {
            $serviceRequest->update([
                'progress_percentage' => 100,
                'status' => 'completed',
                'completed_date' => now(),
            ]);

            // Update technician stats
            $technician->increment('total_jobs');
            $technician->update(['availability' => 'available']);
        }

        // Check for milestones
        $this->checkMilestones($serviceRequest);

        return back()->with('success', 'Job status updated');
    }

    private function checkMilestones(ServiceRequest $serviceRequest)
    {
        $progress = $serviceRequest->progress_percentage;

        $milestones = $serviceRequest->milestones()
            ->where('status', 'pending')
            ->where('progress_step', '<=', $progress)
            ->get();

        foreach ($milestones as $milestone) {
            $milestone->update(['status' => 'reached']);
        }
    }

    /**
     * Return a tool.
     */
    public function returnTool(Request $request, Tool $tool)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician || $tool->technician_id !== $technician->id) {
            return back()->with('error', 'Unauthorized');
        }

        $request->validate([
            'condition' => 'required|in:good,fair,needs_repair,damaged',
        ]);

        // Assuming a method exists or manually updating
        $tool->update([
            'status' => 'available',
            'technician_id' => null,
            'condition' => $request->condition,
            // 'returned_at' => now(), // If column exists
        ]);

        return back()->with('success', 'Tool returned successfully');
    }

    /**
     * Update sub-task progress (existing method).
     */
    public function updateSubTaskProgress(Request $request, ServiceSubTask $serviceSubTask)
    {
        $request->validate([
            'progress_percentage' => 'required|integer|min:0|max:100',
            'status' => 'nullable|in:in_progress,completed',
        ]);

        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            abort(403, 'Technician profile not found');
        }

        $serviceRequest = $serviceSubTask->serviceRequest;

        // Must be the sub-task's assigned technician or the lead technician
        $isAssigned = $serviceSubTask->technician_id === $technician->id;
        $isLead = $serviceRequest->lead_technician_id === $technician->id;

        if (!$isAssigned && !$isLead) {
            abort(403, 'Unauthorized');
        }

        $updateData = [
            'progress_percentage' => $request->progress_percentage,
        ];

        if ($request->status) {
            $updateData['status'] = $request->status;
        }

        // Auto-set status based on progress
        if ($request->progress_percentage === 100) {
            $updateData['status'] = ServiceSubTask::STATUS_COMPLETED;
            $updateData['completed_at'] = now();
        } elseif ($request->progress_percentage > 0 && $serviceSubTask->status === ServiceSubTask::STATUS_ASSIGNED) {
            $updateData['status'] = ServiceSubTask::STATUS_IN_PROGRESS;
        }

        $serviceSubTask->update($updateData);

        \Illuminate\Support\Facades\Log::info('Recalculating progress for ServiceRequest: ' . $serviceRequest->id);
        // Recalculate parent service request progress using fresh instance
        $serviceRequest->fresh()->recalculateProgress();

        \Illuminate\Support\Facades\Log::info('ServiceRequest ' . $serviceRequest->id . ' new progress: ' . $serviceRequest->fresh()->progress_percentage);

        // If service request was assigned, move to in_progress
        if ($serviceRequest->status === 'assigned' && $request->progress_percentage > 0) {
            $serviceRequest->update([
                'status' => 'in_progress',
                'started_at' => $serviceRequest->started_at ?? now(),
            ]);
        }

        // Update main job progress based on average of subtasks? 
        // For now, let's assume subtasks don't auto-update main job progress percentage unless logic added.
        // But if they DO, we should check milestones.

        // Example: if we wanted to update main job progress:
        // $totalSubTasks = $serviceSubTask->serviceRequest->subTasks()->count();
        // $completedSubTasks = $serviceSubTask->serviceRequest->subTasks()->where('status', 'completed')->count();
        // $newProgress = ($completedSubTasks / $totalSubTasks) * 100;
        // $serviceSubTask->serviceRequest->update(['progress_percentage' => $newProgress]);
        // $this->checkMilestones($serviceSubTask->serviceRequest);

        return back()->with('success', 'Sub-task progress updated');
    }
}
