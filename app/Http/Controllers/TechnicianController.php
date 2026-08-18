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

use App\Support\UploadRuntime;

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
        $postableCounts = collect();
        $earnings = null;

        if ($technician) {
            $reportingService = app(ReportingService::class);
            $earnings = $reportingService->getTechnicianEarnings($technician->id);
            $jobEarnings = collect($earnings['by_job'] ?? [])->keyBy('service_request_id');

            // One membership rule for every way a technician lands on a job
            // (direct, lead, sub-task, job assignment) — see
            // ServiceRequest::scopeForTechnician.
            $incomingJobs = ServiceRequest::forTechnician($technician->id)
                ->where('status', 'assigned')
                ->with(['user', 'serviceCategory', 'subTasks'])
                ->latest()
                ->get();

            $activeJobs = ServiceRequest::forTechnician($technician->id)
                ->where('status', 'in_progress')
                ->with(['user', 'serviceCategory', 'subTasks'])
                ->latest()
                ->get();

            // Reviewed reports this lead has ready to post to the office, per
            // job. The office sees nothing on a lead-run job until the lead
            // posts, so the dashboard reminds them before they open the job.
            $postableCounts = $this->postableReportCounts($technician, $user);

            // Same commercial redaction as the job page — these cards carry
            // the whole model too.
            $decorate = function ($job) use ($jobEarnings, $technician, $postableCounts) {
                $job->setAttribute('compensation_summary', $jobEarnings->get($job->id));
                $job->setAttribute('postable_report_count', (int) ($postableCounts[$job->id] ?? 0));
                return $this->technicianSafeJob($job, $technician->id);
            };

            $incomingJobs = $incomingJobs->map($decorate)->values();
            $activeJobs = $activeJobs->map($decorate)->values();
        }

        return Inertia::render('Technician/Dashboard', [
            'technician' => $technician,
            'incomingJobs' => $incomingJobs->values(),
            'activeJobs' => $activeJobs->values(),
            'completedJobsCount' => $this->calculateCompletedJobs($technician),
            // Total reviewed reports waiting to be posted, across all the lead's
            // jobs — drives the dashboard-level reminder.
            'pendingReportPosts' => (int) $postableCounts->sum(),
            'earningsSummary' => $earnings ? [
                'total_paid' => (float) ($earnings['total_paid'] ?? 0),
                'total_outstanding' => (float) ($earnings['total_outstanding'] ?? 0),
                'job_count' => (int) ($earnings['job_count'] ?? 0),
            ] : null,
        ]);
    }

    /**
     * Reviewed reports this lead has ready to post, keyed by job.
     *
     * Mirrors the rule on the job page: on jobs the technician leads, reports
     * not yet sent to the office and not sent back — the crew work they have
     * ratified, plus their own. Un-reviewed crew claims are excluded; the lead
     * approves those first, then posts the batch.
     *
     * @return \Illuminate\Support\Collection<int,int> job id => count
     */
    private function postableReportCounts(Technician $technician, \App\Models\User $user): \Illuminate\Support\Collection
    {
        $leadJobIds = ServiceRequest::where('lead_technician_id', $technician->id)
            ->where('status', 'in_progress')
            ->pluck('id');

        if ($leadJobIds->isEmpty()) {
            return collect();
        }

        return \App\Models\ProgressReport::whereIn('service_request_id', $leadJobIds)
            ->whereNull('submitted_to_office_at')
            ->whereNull('rejected_at')
            ->where(function ($q) use ($technician, $user) {
                $q->whereNotNull('approved_by_lead_at')
                  ->orWhere('submitted_by', $user->id)
                  ->orWhere('technician_id', $technician->id);
            })
            ->selectRaw('service_request_id, COUNT(*) as aggregate')
            ->groupBy('service_request_id')
            ->pluck('aggregate', 'service_request_id');
    }

    private function calculateCompletedJobs($technician)
    {
        if (!$technician)
            return 0;

        // Counted off the single membership rule, so a job can't be tallied
        // twice when the technician is both lead and sub-task holder.
        return ServiceRequest::forTechnician($technician->id)
            ->where('status', 'completed')
            ->count();
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

            $jobs = ServiceRequest::forTechnician($technician->id)
                ->with(['user', 'serviceCategory', 'subTasks.technician.user'])
                ->get()
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
                ->map(function ($job) use ($jobEarnings, $technician) {
                    $job->setAttribute('compensation_summary', $jobEarnings->get($job->id));
                    return $this->technicianSafeJob($job, $technician->id);
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

        if (!$serviceRequest->hasTechnician($technician->id)) {
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
            'progressReports.rejector:id,name',
            'progressReports.subTask',
            'progressReports.photos',
            // Job-level photos — the client's evidence of a snag, so the
            // technician sees it before going back to site.
            'photos.uploader:id,name',
            // The client's own briefs and the specs ops draw for the job, but
            // never the office's commercial paperwork for the client —
            // quotations, quote support, signed approvals, sample reports,
            // case analyses — even once shared with the client. See
            // ServiceRequestDocument::scopeTechnicianVisible.
            'documents' => fn ($q) => $q->technicianVisible(),
        ]);

        $reportingService = app(ReportingService::class);
        $earnings = $reportingService->getTechnicianEarnings($technician->id);
        $compensationSummary = collect($earnings['by_job'] ?? [])
            ->firstWhere('service_request_id', $serviceRequest->id);

        return Inertia::render('Technician/JobDetails', [
            'technician' => $technician,
            'job' => $this->technicianSafeJob($serviceRequest, $technician->id),
            'compensationSummary' => $compensationSummary,
            // Job-wide actions (closing the job) belong to whoever carries
            // the job; a sub-task holder gets the sub-task controls instead.
            'isLeadTechnician' => $serviceRequest->isLeadTechnician($technician->id),
            'scope' => $this->jobScopeForTechnician($serviceRequest),
            'assignmentFiles' => $this->assignmentFilesFor($serviceRequest, $technician->id),
        ]);
    }

    /**
     * Commercial fields a technician must never receive.
     *
     * The whole model used to be serialised into the page props, so the
     * client's price, the job's margin and the billing schedule were being
     * shipped to every technician's browser — invisible on screen, one
     * devtools tab away in practice. A technician is owed their own agreed
     * fee, which travels separately in compensationSummary.
     */
    private const COMMERCIAL_FIELDS = [
        'quote_amount',
        'quote_materials',
        'quote_labor_cost',
        'quote_transport_cost',
        'quote_down_payment',
        'quoted_amount',
        'final_amount',
        'revenue_generated',
        'technician_payout',
        'quote_materials_file_path',
        'quote_materials_file_paths',
        'billing_milestones',
        'billingSchedule',
    ];

    private function technicianSafeJob(ServiceRequest $serviceRequest, int $technicianId): ServiceRequest
    {
        $serviceRequest->makeHidden(self::COMMERCIAL_FIELDS);

        // A crew member's fee is between them and the office — hide the
        // figure on sub-tasks that are not this technician's own.
        $serviceRequest->subTasks->each(function ($subTask) use ($technicianId) {
            if ((int) $subTask->technician_id !== $technicianId) {
                $subTask->makeHidden(['agreed_compensation', 'compensation_notes']);
            }
        });

        return $serviceRequest;
    }

    /**
     * What the technician needs to actually do the job: the scope as quoted,
     * what to install, and the dates they are being held to. Materials carry
     * name and quantity but never unit_price — that is the client's costing,
     * not a packing list.
     */
    private function jobScopeForTechnician(ServiceRequest $serviceRequest): array
    {
        $materials = collect($serviceRequest->quote_materials ?? [])
            ->map(fn ($material) => [
                'name' => $material['name'] ?? 'Unnamed item',
                'quantity' => $material['quantity'] ?? null,
            ])
            ->filter(fn ($material) => $material['name'] !== 'Unnamed item' || $material['quantity'])
            ->values()
            ->all();

        return [
            'notes' => $serviceRequest->quote_notes,
            'materials' => $materials,
            'expected_duration_days' => $serviceRequest->expected_duration_days,
            'commencement_at' => $serviceRequest->commencement_at,
            'target_completion_at' => $serviceRequest->target_completion_at,
            'contact_time_minutes' => $serviceRequest->contact_time_minutes,
        ];
    }

    /**
     * Drawings and briefs ops attached when handing this technician the work.
     * Scoped to their own live assignments — another technician's brief is
     * not theirs to open.
     */
    private function assignmentFilesFor(ServiceRequest $serviceRequest, int $technicianId): array
    {
        return $serviceRequest->jobAssignments()
            ->where('technician_id', $technicianId)
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->get()
            ->flatMap(fn ($assignment) => collect($assignment->attachments ?? [])
                ->map(fn ($file) => [
                    'name' => $file['name'] ?? basename($file['path'] ?? ''),
                    'path' => $file['path'] ?? null,
                ])
                ->filter(fn ($file) => !empty($file['path'])))
            ->values()
            ->all();
    }

    /**
     * Display the technician's tools.
     */
    public function tools(): Response
    {
        $user = auth()->user();
        $technician = $user->technician;

        $issuedTools = collect();
        $issuedStock = collect();
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

            // PPE this technician currently holds — stock issues with a
            // quantity still out, so they can see what is against their name.
            $issuedStock = \App\Models\ToolIssuance::where('technician_id', $technician->id)
                ->outstanding()
                ->with(['tool:id,name,category', 'serviceRequest:id,request_id,job_reference'])
                ->latest('issued_at')
                ->get();

            $returnHistory = Tool::where('technician_id', $technician->id)
                ->where('status', '!=', Tool::STATUS_ISSUED)
                ->limit(20)
                ->get();

            // Inventory a technician can request: serialized tools on the shelf
            // and stock items (PPE) with something left in them. Carries the
            // tracking type and live quantity so the request form can show the
            // available stock on PPE.
            $availableTools = Tool::issuable()
                ->whereNotIn('condition', ['damaged', 'needs_repair'])
                ->orderBy('name')
                ->get(['id', 'name', 'serial_number', 'category', 'condition', 'location', 'tracking_type', 'quantity_available']);

            // Active jobs the technician can attach a request to
            $activeJobs = ServiceRequest::forTechnician($technician->id)
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
            'issuedStock' => $issuedStock,
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
            // When the technician picks a tool from inventory, the frontend
            // only sends tool_id — but the table requires tool_name_requested
            // NOT NULL. Backfill it from the actual Tool record so admin
            // queues and emails always show a meaningful name.
            $requestedName = $item['tool_name_requested'] ?? null;
            if (empty($requestedName) && !empty($item['tool_id'])) {
                $tool = Tool::find($item['tool_id']);
                $requestedName = $tool?->name ?? 'Inventory tool';
            }

            \App\Models\ToolRequestItem::create([
                'tool_request_id'     => $toolRequest->id,
                'tool_id'             => $item['tool_id'] ?? null,
                'tool_name_requested' => $requestedName ?: 'Unspecified tool',
                'quantity'            => $item['quantity'] ?? 1,
                'status'              => ToolRequest::STATUS_PENDING,
            ]);
        }

        // Let the office know there is a request waiting.
        app(\App\Services\NotificationService::class)->notifyToolRequestSubmitted($toolRequest);

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
            $assignedJobs = \App\Models\ServiceRequest::forTechnician($technician->id);
            $jobStats = [
                'total' => (clone $assignedJobs)->count(),
                'completed' => (clone $assignedJobs)->whereIn('status', ['closed', 'archived'])->count(),
                'in_progress' => (clone $assignedJobs)->where('status', 'in_progress')->count(),
                'assigned' => (clone $assignedJobs)->whereIn('status', ['assigned', 'queued'])->count(),
                'suspended' => (clone $assignedJobs)->where('status', 'suspended')->count(),
            ];

            // Recent jobs (last 5)
            $recentJobs = \App\Models\ServiceRequest::forTechnician($technician->id)
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
            'phone'          => 'nullable|string|max:20',
            'profile_photo'  => 'nullable|file|mimes:jpg,jpeg,png|max:3072',
            'skills'         => 'nullable|array',
            'skills.*'       => 'string|max:80',
            'bio'            => 'nullable|string|max:1000',
        ]);

        // === Auto-applied fields (low risk) ============================
        if ($request->filled('phone')) {
            $user->update(['phone' => $request->phone]);
        }

        if ($request->hasFile('profile_photo')) {
            if ($technician->profile_photo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($technician->profile_photo_path);
            }
            $technician->update([
                'profile_photo_path' => $request->file('profile_photo')->store('technician-photos/' . $technician->id, 'public'),
            ]);
        }

        // === Approval-gated fields (skills + bio) ======================
        // Only queue a pending change if the submitted value actually
        // differs from what's already on the technician — avoids creating
        // a "pending" entry when the technician just hits Save without
        // editing those fields.
        $pending = (array) ($technician->pending_profile_changes ?? []);
        $changed = false;

        $currentSkills = array_values(array_filter((array) ($technician->skills ?? [])));
        $newSkills = $request->has('skills')
            ? array_values(array_filter(array_map('trim', (array) $request->input('skills', []))))
            : null;
        if ($newSkills !== null && $newSkills !== $currentSkills) {
            $pending['skills'] = $newSkills;
            $changed = true;
        }

        if ($request->has('bio')) {
            $newBio = trim((string) $request->input('bio'));
            if ($newBio !== trim((string) $technician->bio)) {
                $pending['bio'] = $newBio;
                $changed = true;
            }
        }

        if ($changed) {
            $pending['submitted_at'] = now()->toIso8601String();
            $technician->update(['pending_profile_changes' => $pending]);
            return back()->with('success', 'Profile updated. Skills and bio changes are now awaiting admin approval.');
        }

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Withdraw a pending profile change submission (technician changed mind).
     */
    public function withdrawPendingProfileChanges()
    {
        $user = auth()->user();
        $technician = $user->technician;
        if (!$technician) {
            return back()->with('error', 'Technician profile not found');
        }

        $technician->update(['pending_profile_changes' => null]);
        return back()->with('success', 'Pending profile changes withdrawn.');
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
        UploadRuntime::prepare('technician.progress-report');

        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician) {
            return back()->with('error', 'Technician profile not found');
        }

        if (!$serviceRequest->hasTechnician($technician->id)) {
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

        $isLead = $serviceRequest->isLeadTechnician($technician->id);
        $subTask = null;

        if ($request->filled('service_sub_task_id')) {
            $subTask = $serviceRequest->subTasks()->where('id', $request->service_sub_task_id)->first();

            if (!$subTask) {
                return back()->withErrors([
                    'service_sub_task_id' => 'Selected sub-task does not belong to this job.',
                ]);
            }

            // Their own sub-task, or any of them if they lead the job — a
            // lead reporting for the crew is normal on site.
            if ((int) $subTask->technician_id !== (int) $technician->id && !$isLead) {
                return back()->withErrors([
                    'service_sub_task_id' => 'You can only report on a sub-task assigned to you.',
                ]);
            }
        } elseif ($serviceRequest->isSplitIntoSubTasks() && !$isLead) {
            // The job as a whole is the lead's to speak for. Everyone else
            // reports against their own sub-task, and those roll up into the
            // overall figure — so a crew member cannot set the job's headline
            // percentage from the part of it they can see.
            return back()->withErrors([
                'service_sub_task_id' => 'Pick the sub-task you are reporting on. '
                    . 'Only the lead technician reports on the job as a whole.',
            ]);
        }

        // A lead may file for a crew member who never got their report in.
        // The report is about that member's work, so it carries their
        // technician_id, while authored_as records that the lead wrote it —
        // otherwise it would read as the member's own account of themselves.
        [$subjectTechnicianId, $authoredAs] = $this->attributeReport($technician, $subTask, $isLead);

        app(ProgressService::class)->submitReport(
            $serviceRequest,
            $subjectTechnicianId,
            $user->id,
            $request->only(['percent_complete', 'notes', 'report_date', 'service_sub_task_id'])
                + ['authored_as' => $authoredAs],
            $request->file('photos', [])
        );

        return back()->with('success', $authoredAs === \App\Models\ProgressReport::AS_LEAD
            ? 'Report filed on the technician\'s behalf. It goes to the project team to ratify.'
            : 'Progress report submitted successfully.');
    }

    /**
     * Whose work a report is about, and in what capacity it was written.
     *
     * They are the same person in the ordinary case. They come apart when a
     * lead covers for a crew member: the work is still the crew member's, so
     * their sub-task and their bar are what move, but the account of it is the
     * lead's and is labelled as such.
     */
    private function attributeReport(Technician $author, ?ServiceSubTask $subTask, bool $isLead): array
    {
        $subjectId = $subTask?->technician_id ?? $author->id;
        $onBehalf = $isLead && (int) $subjectId !== (int) $author->id;

        return [
            (int) $subjectId,
            $onBehalf ? \App\Models\ProgressReport::AS_LEAD : \App\Models\ProgressReport::AS_TECHNICIAN,
        ];
    }

    /**
     * Update job status (en_route, on_site, completed).
     */
    public function updateJobStatus(Request $request, ServiceRequest $serviceRequest)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician || !$serviceRequest->hasTechnician($technician->id)) {
            return back()->with('error', 'Unauthorized');
        }

        $request->validate([
            'action' => 'required|in:en_route,on_site,completed',
        ]);

        $action = $request->action;

        // Starting and arriving are per-person facts — whoever gets to site
        // first records them. Previously only `service_requests.technician_id`
        // could do this, so on a project with sub-tasks a technician who was
        // not the lead could never move the job off 'assigned' — and progress
        // reports are blocked while a job sits in 'assigned'. That deadlock is
        // why sub-task technicians could not submit reports at all.
        //
        // Closing the whole job stays with the lead: one sub-task finishing
        // is not the project finishing.
        if ($action === 'completed' && !$serviceRequest->isLeadTechnician($technician->id)) {
            return back()->with('error',
                'Only the lead technician can mark the whole job complete. ' .
                'Update your sub-task to 100% and the lead will close the job.'
            );
        }

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
            // Block "Mark Complete" unless the technician has a validated
            // 100% progress report. This prevents the schema disconnect
            // where service_requests.progress_percentage jumps to 100 but
            // the latest validated progress_report still sits at the
            // previous %, which leaves the payment system unable to bill
            // the remaining balance.
            //
            // On a project with sub-tasks the lead may never file a report
            // in their own name — the crew files them per sub-task. Scoping
            // the check to the lead's own reports would leave such a job
            // impossible to close, so read the job-wide validated progress
            // there and keep the per-technician check for solo jobs.
            $validatedQuery = $serviceRequest->progressReports()
                ->where('is_validated', true)
                ->orderBy('report_date', 'desc');

            if (!$serviceRequest->has_sub_tasks) {
                $validatedQuery->where('technician_id', $technician->id);
            }

            $latestValidatedPct = (int) ($validatedQuery->value('validated_percent') ?? 0);

            if ($latestValidatedPct < 100) {
                return back()->with('error',
                    'Submit a 100% progress report first (and wait for admin validation) before marking the job complete. ' .
                    'The latest validated progress on this job is ' . $latestValidatedPct . '%.'
                );
            }

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
     * Technician asks to hand PPE (a stock item) back. This does not restock —
     * it records a pending return the office confirms once the items are in
     * hand. They can only return what is against their own name.
     */
    public function returnToolIssuance(Request $request, \App\Models\ToolIssuance $toolIssuance)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician || $toolIssuance->technician_id !== $technician->id) {
            abort(403, 'That PPE is not issued to you.');
        }

        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($toolIssuance->quantity_returnable < 1) {
            return back()->with('error', 'You have no more of that PPE waiting to return.');
        }

        if ($data['quantity'] > $toolIssuance->quantity_returnable) {
            return back()->with('error', "You can only return {$toolIssuance->quantity_returnable} of those.");
        }

        if ($request->filled('notes')) {
            $toolIssuance->notes = trim($toolIssuance->notes . "\n" . $data['notes']);
        }

        $toolIssuance->requestReturn((int) $data['quantity']);

        // Let the office know there is a return to confirm.
        app(\App\Services\NotificationService::class)->notifyToolReturn(
            $toolIssuance,
            \App\Notifications\ToolReturnNotification::ACTION_REQUESTED,
            (int) $data['quantity'],
        );

        return back()->with('success',
            "Return requested for {$data['quantity']} × {$toolIssuance->tool->name}. The office will confirm it."
        );
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

        // Must be the sub-task's assigned technician, or carry the job as a
        // whole (lead / sole assignee).
        $isAssigned = (int) $serviceSubTask->technician_id === (int) $technician->id;

        if (!$isAssigned && !$serviceRequest->isLeadTechnician($technician->id)) {
            abort(403, 'Unauthorized');
        }

        if ($serviceRequest->status === ServiceRequest::STATUS_COMPLETED) {
            return back()->with('error', 'This job is already completed.');
        }

        // The slider used to write straight to the sub-task, which meant a
        // technician could move the job's headline percentage — and, through
        // the billing milestones that key off it — with nobody approving the
        // claim. It now files the same kind of progress report the form does,
        // and only counts once approved.
        [$subjectTechnicianId, $authoredAs] = $this->attributeReport(
            $technician,
            $serviceSubTask,
            $serviceRequest->isLeadTechnician($technician->id)
        );

        app(ProgressService::class)->submitReport(
            $serviceRequest,
            $subjectTechnicianId,
            $user->id,
            [
                'percent_complete' => $request->progress_percentage,
                'service_sub_task_id' => $serviceSubTask->id,
                'notes' => $request->input('notes'),
                'authored_as' => $authoredAs,
            ]
        );

        return back()->with('success', $authoredAs === \App\Models\ProgressReport::AS_LEAD
            ? 'Filed on the technician\'s behalf. It goes to the project team to ratify.'
            : 'Progress submitted for approval.');
    }

    /**
     * The lead signs off a crew member's sub-task claim.
     *
     * Sub-task progress is the crew's own account of their work; the lead is
     * the person on site who can say whether it is true. Approving it moves
     * the sub-task and, through it, the job's overall figure.
     */
    public function approveSubTaskReport(\App\Models\ProgressReport $progressReport)
    {
        $technician = $this->authorizeLeadSignOff($progressReport);

        if ($progressReport->is_validated) {
            return back()->with('error', 'That report has already been approved.');
        }

        // releaseBilling: false — the lead's word moves the work, not the
        // money. Any milestone the new percentage passes is raised by a PM or
        // admin, who still sees this report in their queue.
        app(ProgressService::class)->validate(
            $progressReport,
            auth()->id(),
            ['validated_percent' => $progressReport->percent_complete],
            [],
            releaseBilling: false,
            validatedAs: \App\Models\ProgressReport::AS_LEAD
        );

        $progressReport->forceFill(['approved_by_lead_at' => now()])->save();

        return back()->with('success',
            'Sub-task progress approved. The office will confirm any payment that follows.');
    }

    /**
     * The lead sends a claim back with a reason, so the technician knows what
     * to put right rather than guessing why their bar never moved.
     */
    public function rejectSubTaskReport(Request $request, \App\Models\ProgressReport $progressReport)
    {
        $this->authorizeLeadSignOff($progressReport);

        $data = $request->validate([
            'rejection_reason' => 'required|string|min:5|max:1000',
        ], [
            'rejection_reason.required' => 'Tell the technician what needs putting right.',
            'rejection_reason.min' => 'Give the technician something to act on — a few words at least.',
        ]);

        if ($progressReport->approved_by_lead_at === null && $progressReport->is_validated) {
            return back()->with('error',
                'That report has been validated by the project team — ask them to reopen it.');
        }

        app(ProgressService::class)->reject(
            $progressReport,
            auth()->id(),
            $data['rejection_reason'],
            \App\Models\ProgressReport::AS_LEAD
        );

        return back()->with('success', 'Sent back to the technician with your reason.');
    }

    /**
     * The lead answers a report the office sent back.
     *
     * They can correct the percentage, rewrite the notes, or just add a
     * comment saying why it stands — a returned report is often a question,
     * not a verdict. Either way it goes back to the office to settle, never
     * onto the lead's own authority.
     */
    public function reviseReturnedReport(Request $request, \App\Models\ProgressReport $progressReport)
    {
        $technician = auth()->user()->technician;
        $serviceRequest = $progressReport->serviceRequest;

        if (!$technician || !$serviceRequest || !$serviceRequest->isLeadTechnician($technician->id)) {
            abort(403, 'Only the lead technician can answer a report sent back on this job.');
        }

        if (!$progressReport->isReturnedToLead()) {
            return back()->with('error', 'That report has not been sent back to you.');
        }

        $data = $request->validate([
            'percent_complete' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string|max:2000',
            'comment' => 'nullable|string|max:1000',
        ]);

        // Sending it back up unchanged tells the office nothing.
        if (!$request->filled('comment') && !$request->filled('notes') && !$request->filled('percent_complete')) {
            return back()->withErrors([
                'comment' => 'Change the figure, edit the notes, or add a comment before sending it back.',
            ]);
        }

        app(ProgressService::class)->reviseByLead($progressReport, auth()->id(), $data);

        return back()->with('success', 'Sent back to the project team.');
    }

    /**
     * The lead pushes their crew's reviewed reports up to the office as one
     * batch. Nothing on a lead-run job reaches the office until this — which
     * is what spares the client a separate notification from each technician.
     */
    public function postReportsToOffice(Request $request, ServiceRequest $serviceRequest)
    {
        $user = auth()->user();
        $technician = $user->technician;

        if (!$technician || !$serviceRequest->isLeadTechnician($technician->id)) {
            abort(403, 'Only the lead technician can post this job\'s reports to the office.');
        }

        $posted = app(ProgressService::class)->postBatchToOffice($serviceRequest, $user);

        if ($posted === 0) {
            return back()->with('error',
                'Nothing to post yet. Approve your crew\'s reports first, then post them together.');
        }

        return back()->with('success',
            "Posted {$posted} " . ($posted === 1 ? 'report' : 'reports') .
            ' to the office. The client hears once, when the office releases the batch.');
    }

    /**
     * Shared gate for the lead's on-site sign-off: they lead this job, the
     * report is against a sub-task, and it is not their own work.
     */
    private function authorizeLeadSignOff(\App\Models\ProgressReport $progressReport): Technician
    {
        $technician = auth()->user()->technician;
        $serviceRequest = $progressReport->serviceRequest;

        if (!$technician || !$serviceRequest || !$serviceRequest->isLeadTechnician($technician->id)) {
            abort(403, 'Only the lead technician can sign off sub-task progress on this job.');
        }

        if (!$progressReport->service_sub_task_id) {
            abort(403, 'A whole-job report is settled by the project team, not on site.');
        }

        // Nobody rules on their own account. That covers both a lead who
        // holds a sub-task themselves, and a lead ratifying a report they
        // wrote on someone else's behalf — the second is the whole reason
        // on-behalf reports carry an author separate from their subject.
        if ((int) $progressReport->technician_id === (int) $technician->id) {
            abort(403, 'Your own sub-task progress is settled by the project team, not by you.');
        }

        if ((int) $progressReport->submitted_by === (int) auth()->id()) {
            abort(403, 'You wrote this report, so the project team ratifies it rather than you.');
        }

        // Once the office has sent a report back and the lead has answered it,
        // settling it is the office's — otherwise the lead could correct the
        // figure and then ratify their own correction on site.
        if ($progressReport->revised_by_lead_at !== null) {
            abort(403, 'You answered this one, so the project team settles it rather than you.');
        }

        return $technician;
    }
}
