<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\ServiceSubTask;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\TechnicianDocument;
use App\Models\Tool;
use App\Models\ToolRequest;
use App\Services\ProgressService;
use App\Services\ReportingService;
use Carbon\Carbon;

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
        $earnings = null;

        if ($technician) {
            $reportingService = app(ReportingService::class);
            $earnings = $reportingService->getTechnicianEarnings($technician->id);
            $jobEarnings = collect($earnings['by_job'] ?? [])->keyBy('service_request_id');

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

            $incomingJobs = $incomingJobs->map(function ($job) use ($jobEarnings) {
                $job->setAttribute('compensation_summary', $jobEarnings->get($job->id));
                return $job;
            })->values();

            $activeJobs = $activeJobs->map(function ($job) use ($jobEarnings) {
                $job->setAttribute('compensation_summary', $jobEarnings->get($job->id));
                return $job;
            })->values();
        }

        return Inertia::render('Technician/Dashboard', [
            'technician' => $technician,
            'incomingJobs' => $incomingJobs->values(),
            'activeJobs' => $activeJobs->values(),
            'completedJobsCount' => $this->calculateCompletedJobs($technician),
            'earningsSummary' => $earnings ? [
                'total_paid' => (float) ($earnings['total_paid'] ?? 0),
                'total_outstanding' => (float) ($earnings['total_outstanding'] ?? 0),
                'job_count' => (int) ($earnings['job_count'] ?? 0),
            ] : null,
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
        $earnings = null;

        if ($technician) {
            $reportingService = app(ReportingService::class);
            $earnings = $reportingService->getTechnicianEarnings($technician->id);
            $jobEarnings = collect($earnings['by_job'] ?? [])->keyBy('service_request_id');

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
                ->map(function ($job) use ($jobEarnings) {
                    $job->setAttribute('compensation_summary', $jobEarnings->get($job->id));
                    return $job;
                })
                ->values();
        }

        return Inertia::render('Technician/Jobs', [
            'technician' => $technician,
            'jobs' => $jobs,
            'earningsSummary' => $earnings ? [
                'total_paid' => (float) ($earnings['total_paid'] ?? 0),
                'total_outstanding' => (float) ($earnings['total_outstanding'] ?? 0),
                'job_count' => (int) ($earnings['job_count'] ?? 0),
            ] : null,
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

        $serviceRequest->load([
            'user',
            'serviceCategory',
            'subTasks.technician.user',
            'tools',
            'progressReports.technician.user',
            'progressReports.submitter',
            'progressReports.validator',
            'progressReports.subTask',
            'progressReports.photos',
        ]);

        $reportingService = app(ReportingService::class);
        $earnings = $reportingService->getTechnicianEarnings($technician->id);
        $compensationSummary = collect($earnings['by_job'] ?? [])
            ->firstWhere('service_request_id', $serviceRequest->id);

        return Inertia::render('Technician/JobDetails', [
            'technician' => $technician,
            'job' => $serviceRequest,
            'compensationSummary' => $compensationSummary,
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
        $availableTools = collect();
        $pendingRequests = collect();
        $recentDecisions = collect();
        $activeJobs = collect();

        if ($technician) {
            $issuedTools = Tool::where('technician_id', $technician->id)
                ->where('status', Tool::STATUS_ISSUED)
                ->with('serviceRequest:id,request_id,job_reference')
                ->get();

            $returnHistory = Tool::where('technician_id', $technician->id)
                ->where('status', '!=', Tool::STATUS_ISSUED)
                ->limit(20)
                ->get();

            // Inventory of tools currently available to request
            $availableTools = Tool::where('status', Tool::STATUS_AVAILABLE)
                ->whereNotIn('condition', ['damaged', 'needs_repair'])
                ->orderBy('name')
                ->get(['id', 'name', 'serial_number', 'category', 'condition', 'location']);

            // Active jobs the technician can attach a request to
            $activeJobs = ServiceRequest::where('technician_id', $technician->id)
                ->whereIn('status', [
                    ServiceRequest::STATUS_ASSIGNED,
                    ServiceRequest::STATUS_IN_PROGRESS,
                    ServiceRequest::STATUS_QUEUED,
                    'pending',
                ])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'request_id', 'job_reference']);

            $pendingRequests = \App\Models\ToolRequestItem::where('status', ToolRequest::STATUS_PENDING)
                ->whereHas('toolRequest', function ($q) use ($technician) {
                    $q->where('technician_id', $technician->id);
                })
                ->with(['tool:id,name,serial_number', 'toolRequest.serviceRequest:id,request_id,job_reference'])
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'tool_id' => $item->tool_id,
                        'tool_name_requested' => $item->tool_name_requested,
                        'quantity' => $item->quantity,
                        'status' => $item->status,
                        'created_at' => $item->created_at,
                        'tool' => $item->tool,
                        'service_request' => $item->toolRequest->serviceRequest ?? null,
                    ];
                });

            $recentDecisions = \App\Models\ToolRequestItem::whereIn('status', [
                    ToolRequest::STATUS_APPROVED,
                    ToolRequest::STATUS_REJECTED,
                ])
                ->whereHas('toolRequest', function ($q) use ($technician) {
                    $q->where('technician_id', $technician->id);
                })
                ->with([
                    'tool:id,name,serial_number',
                    'toolRequest.serviceRequest:id,request_id,job_reference',
                    'decidedBy:id,name',
                ])
                ->orderByDesc('decided_at')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'tool_id' => $item->tool_id,
                        'tool_name_requested' => $item->tool_name_requested,
                        'quantity' => $item->quantity,
                        'status' => $item->status,
                        'created_at' => $item->created_at,
                        'decided_at' => $item->decided_at,
                        'decision_notes' => $item->decision_notes,
                        'tool' => $item->tool,
                        'service_request' => $item->toolRequest->serviceRequest ?? null,
                        'decidedBy' => $item->decidedBy,
                    ];
                });
        }

        return Inertia::render('Technician/Tools', [
            'technician' => $technician,
            'issuedTools' => $issuedTools,
            'returnHistory' => $returnHistory,
            'availableTools' => $availableTools,
            'activeJobs' => $activeJobs,
            'pendingRequests' => $pendingRequests,
            'recentDecisions' => $recentDecisions,
        ]);
    }

    /**
     * Submit a tool request from the technician portal. Either a specific
     * tool from inventory OR a freeform name when the item isn't tracked
     * yet. Goes into the admin queue for approval.
     */
    public function storeToolRequest(Request $request)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            return back()->withErrors(['toolRequest' => 'Only technicians can request tools.']);
        }

        $data = $request->validate([
            'service_request_id' => 'nullable|integer|exists:service_requests,id',
            'urgency' => 'nullable|in:low,normal,high',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.tool_id' => 'nullable|integer|exists:tools,id',
            'items.*.tool_name_requested' => 'nullable|string|max:150',
            'items.*.quantity' => 'nullable|integer|min:1|max:50',
        ]);

        // Validate items
        foreach ($data['items'] as $index => $item) {
            if (empty($item['tool_id']) && empty($item['tool_name_requested'])) {
                return back()->withErrors([
                    "items.{$index}.tool_id" => 'Each item must have a tool selected or a name provided.',
                ]);
            }

            if (!empty($item['tool_id'])) {
                $tool = Tool::find($item['tool_id']);
                if (!$tool || $tool->status !== Tool::STATUS_AVAILABLE) {
                    return back()->withErrors([
                        "items.{$index}.tool_id" => 'One of the requested tools is no longer available.',
                    ]);
                }
            }
        }

        $toolRequest = ToolRequest::create([
            'technician_id' => $technician->id,
            'service_request_id' => $data['service_request_id'] ?? null,
            'urgency' => $data['urgency'] ?? ToolRequest::URGENCY_NORMAL,
            'notes' => $data['notes'] ?? null,
            'status' => ToolRequest::STATUS_PENDING,
        ]);

        foreach ($data['items'] as $item) {
            \App\Models\ToolRequestItem::create([
                'tool_request_id' => $toolRequest->id,
                'tool_id' => $item['tool_id'] ?? null,
                'tool_name_requested' => $item['tool_name_requested'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'status' => ToolRequest::STATUS_PENDING,
            ]);
        }

        return back()->with('success', 'Tool request submitted. An admin will review it shortly.');
    }

    /**
     * Technician cancels their own pending request before an admin acts.
     */
    public function cancelToolRequest(ToolRequest $toolRequest)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician || $toolRequest->technician_id !== $technician->id) {
            abort(403);
        }

        if (!$toolRequest->isPending()) {
            return back()->withErrors([
                'toolRequest' => 'Only pending requests can be cancelled.',
            ]);
        }

        // tool_requests table only has `status` — decision metadata
        // (decided_at / decision_notes) lives on tool_request_items.
        $toolRequest->update(['status' => ToolRequest::STATUS_CANCELLED]);
        $toolRequest->items()->whereNull('decided_at')->update([
            'status'         => ToolRequest::STATUS_CANCELLED,
            'decided_by'     => $user->id,
            'decided_at'     => now(),
            'decision_notes' => 'Cancelled by technician.',
        ]);

        return back()->with('success', 'Tool request cancelled.');
    }

    /**
     * Display the technician's profile.
     */
    public function profile(): Response
    {
        $user = auth()->user();
        $technician = $user->technician;

        $jobStats = [];
        $documents = [];
        $recentJobs = [];

        if ($technician) {
            $technician->load(['user', 'documents']);

            // Job statistics
            $assignedJobs = \App\Models\ServiceRequest::where('technician_id', $technician->id);
            $jobStats = [
                'total' => (clone $assignedJobs)->count(),
                'completed' => (clone $assignedJobs)->whereIn('status', ['closed', 'archived'])->count(),
                'in_progress' => (clone $assignedJobs)->where('status', 'in_progress')->count(),
                'assigned' => (clone $assignedJobs)->whereIn('status', ['assigned', 'queued'])->count(),
                'suspended' => (clone $assignedJobs)->where('status', 'suspended')->count(),
            ];

            // Recent jobs (last 5)
            $recentJobs = \App\Models\ServiceRequest::where('technician_id', $technician->id)
                ->with(['serviceCategory:id,name'])
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get(['id', 'request_id', 'status', 'service_category_id', 'location', 'updated_at']);

            $documents = $technician->documents ?? [];
        }

        return Inertia::render('Technician/Profile', [
            'technician' => $technician,
            'jobStats' => $jobStats,
            'documents' => $documents,
            'recentJobs' => $recentJobs,
            'documentTypes' => TechnicianDocument::documentTypes(),
            'requiredDocumentTypes' => [
                TechnicianDocument::TYPE_NCA_LICENSE,
                TechnicianDocument::TYPE_TERTIARY_CERT,
                TechnicianDocument::TYPE_ID_CARD,
                TechnicianDocument::TYPE_PASSPORT_PHOTO,
                TechnicianDocument::TYPE_PIN_CERT,
            ],
            'trades' => Technician::trades(),
        ]);
    }

    /**
     * Display the technician's earnings.
     */
    public function earnings(Request $request): Response
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            abort(403, 'Technician profile not found');
        }

        $reportingService = app(ReportingService::class);

        $from = $request->from ? Carbon::parse($request->from) : null;
        $to = $request->to ? Carbon::parse($request->to) : null;

        $earnings = $reportingService->getTechnicianEarnings($technician->id, $from, $to);

        return Inertia::render('Technician/Earnings', [
            'technician' => $technician,
            'earnings' => $earnings,
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ]);
    }

    /**
     * Update technician profile details.
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            return back()->with('error', 'Technician profile not found');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:255',
            'trade' => 'nullable|in:' . implode(',', array_keys(Technician::trades())),
            'specialization' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'experience_narrative' => 'nullable|string|max:2000',
            'skills' => 'nullable|array',
            'profile_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:3072',
        ]);

        // Update user details
        $user->update([
            'name' => $request->name,
            'phone' => $request->phone,
        ]);

        // Update technician details
        $techData = [
            'location' => $request->location,
            'trade' => $request->trade,
            'specialization' => $request->specialization,
            'bio' => $request->bio,
            'experience_narrative' => $request->experience_narrative,
            'skills' => $request->skills,
        ];

        // Handle profile photo upload
        if ($request->hasFile('profile_photo')) {
            if ($technician->profile_photo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($technician->profile_photo_path);
            }
            $techData['profile_photo_path'] = $request->file('profile_photo')->store('technician-photos/' . $technician->id, 'public');
        }

        $technician->update($techData);

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Upload a document from the technician portal.
     */
    public function uploadDocument(Request $request)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            return back()->with('error', 'Technician profile not found');
        }

        $request->validate([
            'document_type' => 'required|in:nca_license,tertiary_cert,id_card,passport_photo,pin_cert,technical_cert,vetting_form,other',
        ]);

        $documentRule = $request->input('document_type') === 'passport_photo'
            ? 'required|file|mimes:jpg,jpeg,png|max:3072'
            : 'required|file|mimes:pdf,jpg,jpeg,png|max:5120';

        $request->validate([
            'document' => $documentRule,
        ]);

        $file = $request->file('document');
        $path = $file->store('technician-documents/' . $technician->id, 'public');

        // Replace existing document of the same type
        $existing = $technician->documents()->where('document_type', $request->document_type)->first();
        if ($existing) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($existing->file_path);
            $existing->update([
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'verified' => false,
                'verified_by' => null,
                'verified_at' => null,
            ]);
        } else {
            \App\Models\TechnicianDocument::create([
                'technician_id' => $technician->id,
                'document_type' => $request->document_type,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
            ]);
        }

        return back()->with('success', 'Document uploaded successfully.');
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
     * Submit a progress report with optional site photos.
     */
    public function submitProgressReport(Request $request, ServiceRequest $serviceRequest)
    {
        // Mobile uploads on 4G are slow — extend the PHP execution window
        // so a 5-photo submission doesn't trip the 30s timeout.
        @set_time_limit(120);
        @ini_set('upload_max_filesize', '20M');
        @ini_set('post_max_size', '60M');
        @ini_set('memory_limit', '256M');

        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            return back()->with('error', 'Technician profile not found');
        }

        $isAssigned = $serviceRequest->technician_id === $technician->id;
        $isSubTaskAssignee = $serviceRequest->subTasks()->where('technician_id', $technician->id)->exists();

        if (!$isAssigned && !$isSubTaskAssignee) {
            return back()->with('error', 'Unauthorized');
        }

        if ($serviceRequest->status === ServiceRequest::STATUS_COMPLETED) {
            return back()->with('error', 'This job is already completed. No further progress reports can be submitted.');
        }

        if ($serviceRequest->status === 'assigned') {
            return back()->with('error', 'You must start the job before submitting progress reports.');
        }

        $request->validate([
            'percent_complete' => 'required|integer|min:0|max:100',
            'notes' => 'nullable|string|max:2000',
            'report_date' => 'nullable|date',
            'service_sub_task_id' => 'nullable|exists:service_sub_tasks,id',
            'photos' => 'nullable|array|max:6',
            // Drop the Laravel `image` rule because it uses getimagesize()
            // which does not support HEIC/HEIF (#27). Rely on `mimes` alone.
            'photos.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
        ]);

        if ($request->filled('service_sub_task_id')) {
            $subTask = $serviceRequest->subTasks()->where('id', $request->service_sub_task_id)->first();

            if (!$subTask) {
                return back()->withErrors([
                    'service_sub_task_id' => 'Selected sub-task does not belong to this job.',
                ]);
            }

            $canReportSubTask = $subTask->technician_id === $technician->id || $serviceRequest->lead_technician_id === $technician->id;

            if (!$canReportSubTask) {
                return back()->with('error', 'Unauthorized sub-task selection.');
            }
        }

        app(ProgressService::class)->submitReport(
            $serviceRequest,
            $technician->id,
            $user->id,
            $request->only(['percent_complete', 'notes', 'report_date', 'service_sub_task_id']),
            $request->file('photos', [])
        );

        return back()->with('success', 'Progress report submitted successfully.');
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
