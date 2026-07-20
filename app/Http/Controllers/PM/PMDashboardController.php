<?php

namespace App\Http\Controllers\PM;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\Quotation;
use App\Models\QuotationLineItem;
use App\Models\Technician;
use App\Models\TechnicianPaymentSheet;
use App\Models\TechnicianPaymentEntry;
use App\Models\JobAssignment;
use App\Models\CompensationAmendment;
use App\Models\ProgressReport;
use App\Services\QuotationService;
use App\Services\JobService;
use App\Services\ProgressService;
use App\Services\TechnicianPaymentService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class PMDashboardController extends Controller
{
    public function __construct(
        private QuotationService $quotationService,
        private JobService $jobService,
        private ProgressService $progressService,
        private TechnicianPaymentService $paymentService,
        private NotificationService $notificationService,
    ) {}

    /**
     * PM Dashboard.
     */
    public function index()
    {
        $pmId = auth()->id();

        $stats = [
            'assignedRfqs' => ServiceRequest::forPm($pmId)->active()->count(),
            'pendingQuotes' => ServiceRequest::forPm($pmId)
                ->whereIn('status', ['awaiting_quote_generation', 'awaiting_tech_availability'])->count(),
            'activeJobs' => ServiceRequest::forPm($pmId)
                ->whereIn('status', ['assigned', 'in_progress', 'queued'])->count(),
            'pendingValidation' => ProgressReport::whereHas('serviceRequest', function ($q) use ($pmId) {
                $q->where('assigned_pm_id', $pmId);
            })->where('is_validated', false)->count(),
            'completionRate' => $this->calculateCompletionRate($pmId),
        ];

        $recentJobs = ServiceRequest::forPm($pmId)
            ->with(['user', 'technician.user', 'serviceCategory'])
            ->active()
            ->latest()
            ->limit(10)
            ->get();

        return Inertia::render('PM/Dashboard', [
            'stats' => $stats,
            'recentJobs' => $recentJobs,
        ]);
    }

    /**
     * PM's assigned RFQs.
     */
    public function rfqs(Request $request)
    {
        $pmId = auth()->id();

        $summaryQuery = ServiceRequest::forPm($pmId);
        $statusSummary = [
            'total' => (clone $summaryQuery)->count(),
            'awaiting_tech_availability' => (clone $summaryQuery)->where('status', 'awaiting_tech_availability')->count(),
            'awaiting_quote_generation' => (clone $summaryQuery)->where('status', 'awaiting_quote_generation')->count(),
            'awaiting_quote_approval' => (clone $summaryQuery)->where('status', 'awaiting_quote_approval')->count(),
            'awaiting_payment' => (clone $summaryQuery)->where('status', 'awaiting_payment')->count(),
            'ready_for_assignment' => (clone $summaryQuery)->where('status', 'ready_for_assignment')->count(),
        ];

        $rfqs = ServiceRequest::forPm($pmId)
            ->with(['user', 'serviceCategory', 'technician.user', 'latestQuotation.lineItems'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('request_id', 'like', "%{$search}%")
                        ->orWhere('job_reference', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(12)
            ->withQueryString();

        // Show technicians that aren't outright rejected so PMs can also
        // pick up newly-onboarded technicians whose vetting flag was left
        // pending by a previous create flow (#16).
        $technicians = Technician::with('user')
            ->where('is_active', true)
            ->whereIn('vetting_status', [
                Technician::VETTING_APPROVED,
                Technician::VETTING_PENDING,
                Technician::VETTING_UNDER_REVIEW,
            ])
            ->get();

        return Inertia::render('PM/RFQs', [
            'rfqs' => $rfqs,
            'technicians' => $technicians,
            'statusSummary' => $statusSummary,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    /**
     * Create and send a quotation.
     */
    public function createQuotation(Request $request, ServiceRequest $serviceRequest)
    {
        $this->authorizeForPm($serviceRequest);

        $request->validate([
            'line_items' => 'required|array|min:1',
            'line_items.*.category' => 'required|in:material,labor,transport,other',
            'line_items.*.description' => 'required|string',
            'line_items.*.quantity' => 'required|numeric|min:0.01',
            'line_items.*.unit' => 'required|string',
            'line_items.*.unit_price' => 'required|numeric|min:0',
            'payment_terms' => 'nullable|array',
            'delivery_timeline' => 'nullable|string',
            'valid_until' => 'nullable|date|after:today',
            'notes' => 'nullable|string',
            'materials_file' => 'nullable|file|mimes:pdf,docx,xlsx,xls|max:10240',
        ]);

        $filePath = null;
        if ($request->hasFile('materials_file')) {
            $filePath = $request->file('materials_file')
                ->store('quotes', 'public');
        }

        $data = $request->only(['line_items', 'payment_terms', 'delivery_timeline', 'valid_until', 'notes']);
        $data['materials_file_path'] = $filePath;

        $quotation = $this->quotationService->create($serviceRequest, $data, auth()->id());

        // Auto-send
        if ($request->boolean('send_immediately', true)) {
            $this->quotationService->send($quotation);
            $this->notificationService->notifyQuotationSent($serviceRequest);
        }

        return redirect()->back()->with('success', 'Quotation created and sent!');
    }

    /**
     * Revise an existing quotation.
     */
    public function reviseQuotation(Request $request, Quotation $quotation)
    {
        $this->authorizeForPm($quotation->serviceRequest);

        if ($request->has('line_items')) {
            $request->validate([
                'line_items' => 'required|array|min:1',
                'line_items.*.category' => 'required|in:material,labor,transport,other',
                'line_items.*.description' => 'required|string',
                'line_items.*.quantity' => 'required|numeric|min:0.01',
                'line_items.*.unit' => 'required|string',
                'line_items.*.unit_price' => 'required|numeric|min:0',
                'payment_terms' => 'nullable|array',
                'delivery_timeline' => 'nullable|string',
                'valid_until' => 'nullable|date|after:today',
                'notes' => 'nullable|string',
            ]);

            $newQuotation = $this->quotationService->revise($quotation);
            $newQuotation->lineItems()->delete();
            $newQuotation->update($request->only(['payment_terms', 'delivery_timeline', 'valid_until', 'notes']));

            foreach ($request->line_items as $index => $item) {
                QuotationLineItem::create([
                    'quotation_id' => $newQuotation->id,
                    'category' => $item['category'] ?? 'material',
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit' => $item['unit'] ?? 'pcs',
                    'unit_price' => $item['unit_price'],
                    'sort_order' => $index,
                ]);
            }

            $newQuotation->recalculateTotals();

            if ($request->boolean('send_immediately', true)) {
                $this->quotationService->send($newQuotation);
                $this->notificationService->notifyQuotationSent($newQuotation->serviceRequest);
            }

            return redirect()->back()->with('success', 'Revised quotation sent!');
        }

        $newQuotation = $this->quotationService->revise($quotation);

        return redirect()->back()->with('success', 'Revision created. Update and send when ready.');
    }

    /**
     * PM's job management.
     */
    public function jobs(Request $request)
    {
        $pmId = auth()->id();
        $summaryQuery = ServiceRequest::forPm($pmId);

        $statusSummary = [
            'total' => (clone $summaryQuery)->count(),
            'ready_for_assignment' => (clone $summaryQuery)->where('status', ServiceRequest::STATUS_READY_FOR_ASSIGNMENT)->count(),
            'assigned' => (clone $summaryQuery)->where('status', ServiceRequest::STATUS_ASSIGNED)->count(),
            'in_progress' => (clone $summaryQuery)->where('status', ServiceRequest::STATUS_IN_PROGRESS)->count(),
            'delayed' => (clone $summaryQuery)->where('status', ServiceRequest::STATUS_DELAYED)->count(),
            'suspended' => (clone $summaryQuery)->where('status', ServiceRequest::STATUS_SUSPENDED)->count(),
        ];

        $jobs = ServiceRequest::forPm($pmId)
            ->with(['user', 'serviceCategory', 'technician.user', 'leadTechnician.user', 'subTasks.technician.user'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('request_id', 'like', "%{$search}%")
                      ->orWhere('job_reference', 'like', "%{$search}%")
                      ->orWhere('location', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(12)
            ->withQueryString();

        // Show technicians that aren't outright rejected so PMs can also
        // pick up newly-onboarded technicians whose vetting flag was left
        // pending by a previous create flow (#16).
        $technicians = Technician::with('user')
            ->where('is_active', true)
            ->whereIn('vetting_status', [
                Technician::VETTING_APPROVED,
                Technician::VETTING_PENDING,
                Technician::VETTING_UNDER_REVIEW,
            ])
            ->get();

        return Inertia::render('PM/Jobs', [
            'jobs' => $jobs,
            'technicians' => $technicians,
            'statusSummary' => $statusSummary,
            'statuses' => ServiceRequest::allStatuses(),
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    /**
     * Assign technician to job.
     */
    public function assignTechnician(Request $request, ServiceRequest $serviceRequest)
    {
        $this->authorizeForPm($serviceRequest);

        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'agreed_compensation' => 'required|numeric|min:0',
            'compensation_notes' => 'nullable|string',
            'expected_start' => 'required|date',
            'expected_end' => 'required|date|after:expected_start',
        ]);

        $technician = Technician::findOrFail($request->technician_id);

        // Create job assignment record
        JobAssignment::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technician->id,
            'assigned_by' => auth()->id(),
            'agreed_compensation' => $request->agreed_compensation,
            'compensation_notes' => $request->compensation_notes,
            'expected_start' => $request->expected_start,
            'expected_end' => $request->expected_end,
        ]);

        $serviceRequest->update([
            'technician_id' => $technician->id,
            'assigned_at' => now(),
        ]);

        $this->jobService->transitionState($serviceRequest, ServiceRequest::STATUS_ASSIGNED);
        $this->notificationService->notifyJobAssignment($serviceRequest);

        return redirect()->back()->with('success', 'Technician assigned successfully!');
    }

    /**
     * Suspend a job.
     */
    public function suspendJob(Request $request, ServiceRequest $serviceRequest)
    {
        $this->authorizeForPm($serviceRequest);

        $request->validate(['reason' => 'required|string|min:10']);

        $this->jobService->suspend($serviceRequest, $request->reason);

        return redirect()->back()->with('success', 'Job suspended.');
    }

    /**
     * Resume a suspended job.
     */
    public function resumeJob(Request $request, ServiceRequest $serviceRequest)
    {
        $this->authorizeForPm($serviceRequest);

        $this->jobService->resume($serviceRequest, $request->notes);

        return redirect()->back()->with('success', 'Job resumed.');
    }

    /**
     * Reassign a job.
     */
    public function reassignJob(Request $request, ServiceRequest $serviceRequest)
    {
        $this->authorizeForPm($serviceRequest);

        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'reason' => 'required|string|min:10',
        ]);

        $this->jobService->reassign($serviceRequest, $request->technician_id, $request->reason);
        $this->notificationService->notifyJobAssignment($serviceRequest->fresh());

        return redirect()->back()->with('success', 'Job reassigned.');
    }

    /**
     * Progress validation list.
     */
    public function progressReports(Request $request)
    {
        $pmId = auth()->id();
        $summaryQuery = ProgressReport::whereHas('serviceRequest', function ($q) use ($pmId) {
            $q->where('assigned_pm_id', $pmId);
        });

        $summary = [
            'total' => (clone $summaryQuery)->count(),
            'pending' => (clone $summaryQuery)->where('is_validated', false)->count(),
            'validated' => (clone $summaryQuery)->where('is_validated', true)->count(),
            'pm_authored' => (clone $summaryQuery)->where('is_pm_authored', true)->count(),
            'with_photos' => (clone $summaryQuery)->whereHas('photos')->count(),
        ];

        $reports = ProgressReport::whereHas('serviceRequest', function ($q) use ($pmId) {
                $q->where('assigned_pm_id', $pmId);
            })
            ->with(['serviceRequest:id,request_id,job_reference', 'technician.user', 'submitter', 'photos'])
            ->when($request->boolean('pending_only', true), fn($q) => $q->where('is_validated', false))
            ->orderBy('report_date', 'desc')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('PM/ProgressReports', [
            'reports' => $reports,
            'summary' => $summary,
            'filters' => $request->only(['pending_only']),
        ]);
    }

    /**
     * Validate a progress report.
     */
    public function validateProgress(Request $request, ProgressReport $progressReport)
    {
        $request->validate([
            'validated_percent' => 'required|integer|min:0|max:100',
            'validation_notes' => 'nullable|string',
            'remove_photo_ids' => 'nullable|array',
        ]);

        $this->progressService->validate($progressReport, auth()->id(), $request->only([
            'validated_percent', 'validation_notes', 'remove_photo_ids',
        ]));

        return redirect()->back()->with('success', 'Progress validated.');
    }

    /**
     * Create progress report on behalf of technician.
     */
    public function createProgressOnBehalf(Request $request, ServiceRequest $serviceRequest)
    {
        $this->authorizeForPm($serviceRequest);

        $request->validate([
            'percent_complete' => 'required|integer|min:0|max:100',
            'notes' => 'nullable|string',
            'photos.*' => 'nullable|image|max:5120',
        ]);

        $photos = $request->file('photos', []);

        $this->progressService->createOnBehalf(
            $serviceRequest,
            auth()->id(),
            $request->only(['percent_complete', 'notes', 'technician_id', 'service_sub_task_id']),
            $photos
        );

        return redirect()->back()->with('success', 'Progress report created.');
    }

    /**
     * Technician payment sheets.
     */
    public function paymentSheets(Request $request)
    {
        $sheets = TechnicianPaymentSheet::with([
            'creator',
            'entries.technician.user',
            'entries.serviceRequest',
            // Needed so the UI can show 'Paid by X on Y' next to each
            // reconciled entry (Item 3c).
            'entries.paidBy:id,name',
        ])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return Inertia::render('PM/PaymentSheets', [
            'sheets' => $sheets,
        ]);
    }

    /**
     * Create and compute a new payment sheet.
     */
    public function createPaymentSheet(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'notes' => 'nullable|string',
        ]);

        try {
            $sheet = $this->paymentService->createSheet(
                Carbon::parse($request->period_start),
                Carbon::parse($request->period_end),
                auth()->id(),
                $request->notes
            );
        } catch (\RuntimeException $e) {
            // Overlap-with-existing-draft guard (Item 3b) — surface as a
            // validation error rather than a 500 so ops see the friendly
            // message right on the form.
            return redirect()->back()
                ->withErrors(['period_start' => $e->getMessage()])
                ->withInput();
        }

        $this->paymentService->computeEntries($sheet);

        return redirect()->back()->with('success', 'Payment sheet created with computed entries.');
    }

    /**
     * Item 3c — Mark a specific entry on a finalized sheet as actually paid,
     * capturing the amount, date, method, and reference. This feeds the
     * reconciliation pool so future auto-computes don't schedule the same
     * amount twice.
     */
    public function markEntryPaid(Request $request, TechnicianPaymentEntry $entry)
    {
        $request->validate([
            'paid_amount' => 'required|numeric|min:0',
            'paid_at' => 'nullable|date',
            'paid_method' => 'nullable|string|max:40',
            'paid_reference' => 'nullable|string|max:100',
            'paid_notes' => 'nullable|string|max:1000',
        ]);

        if ($entry->paymentSheet->status !== TechnicianPaymentSheet::STATUS_FINALIZED) {
            return redirect()->back()->withErrors([
                'paid_amount' => 'Finalize the sheet before marking entries paid.',
            ]);
        }

        $this->paymentService->markEntryPaid($entry, $request->only([
            'paid_amount', 'paid_at', 'paid_method', 'paid_reference', 'paid_notes',
        ]), auth()->id());

        return redirect()->back()->with('success', 'Entry marked as paid.');
    }

    /**
     * Item 3c — Revert a mark-paid. Ops occasionally mis-click; the audit
     * log captures who reversed and why so we keep a clean trail.
     */
    public function unmarkEntryPaid(Request $request, TechnicianPaymentEntry $entry)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $this->paymentService->unmarkEntryPaid($entry, auth()->id(), $request->input('reason'));

        return redirect()->back()->with('success', 'Payment mark reversed.');
    }

    /**
     * Finalize a payment sheet.
     */
    public function finalizePaymentSheet(TechnicianPaymentSheet $sheet)
    {
        $this->paymentService->finalize($sheet);

        return redirect()->back()->with('success', 'Payment sheet finalized.');
    }

    /**
     * Request compensation amendment (requires admin approval).
     */
    public function requestCompensationAmendment(Request $request, ServiceRequest $serviceRequest)
    {
        $this->authorizeForPm($serviceRequest);

        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'original_amount' => 'required|numeric|min:0',
            'proposed_amount' => 'required|numeric|min:0',
            'justification' => 'required|string|min:20',
        ]);

        CompensationAmendment::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $request->technician_id,
            'requested_by' => auth()->id(),
            'original_amount' => $request->original_amount,
            'proposed_amount' => $request->proposed_amount,
            'justification' => $request->justification,
        ]);

        return redirect()->back()->with('success', 'Compensation amendment submitted for admin approval.');
    }

    // ==================== TECHNICIAN MANAGEMENT ====================

    /**
     * Technician directory for PM.
     */
    public function technicians(Request $request)
    {
        $technicians = Technician::with(['user', 'documents'])
            ->when($request->trade, fn($q, $t) => $q->where('trade', $t))
            ->when($request->status, fn($q, $s) => $q->where('vetting_status', $s))
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('PM/Technicians', [
            'technicians' => $technicians,
            'trades' => Technician::trades(),
            'documentTypes' => \App\Models\TechnicianDocument::documentTypes(),
            'filters' => $request->only(['trade', 'status']),
        ]);
    }

    // ==================== MESSAGING ====================

    public function messages()
    {
        $conversations = auth()->user()->conversations()
            ->with(['latestMessage.sender', 'serviceRequest:id,request_id,job_reference'])
            ->withCount('messages')
            ->orderByPivot('updated_at', 'desc')
            ->paginate(20);

        return Inertia::render('PM/Messages', [
            'conversations' => $conversations,
        ]);
    }

    public function downloadPaymentSheet(TechnicianPaymentSheet $sheet)
    {
        $sheet->load(['entries.technician.user', 'entries.serviceRequest', 'creator']);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.payment-sheet', [
            'sheet' => $sheet,
        ]);

        return $pdf->download("payment-sheet-{$sheet->sheet_reference}.pdf");
    }

    public function reports(Request $request)
    {
        $reportingService = app(\App\Services\ReportingService::class);
        $pmId = auth()->id();

        $from = $request->from ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : now();

        $revenueReport = $reportingService->getRevenueReport($from, $to, $pmId);

        return Inertia::render('PM/Reports', [
            'report' => $revenueReport,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function rfqRevenueReport(Request $request)
    {
        $reportingService = app(\App\Services\ReportingService::class);
        $pmId = auth()->id();

        $from = $request->from ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : now();
        $clientId = $request->client_id ? (int) $request->client_id : null;

        return Inertia::render('PM/ReportRfqRevenue', [
            'report' => $reportingService->getRfqRevenueReport($from, $to, $pmId, $clientId),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'client_id' => $clientId],
        ]);
    }

    public function clientRevenueReport(Request $request)
    {
        $reportingService = app(\App\Services\ReportingService::class);
        $pmId = auth()->id();

        $from = $request->from ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : now();

        return Inertia::render('PM/ReportClientRevenue', [
            'report' => $reportingService->getClientRevenueReport($from, $to, $pmId),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function exportRfqRevenueReport(Request $request, string $format)
    {
        [$from, $to] = $this->resolveReportRange($request);
        $pmId = auth()->id();
        $reportingService = app(\App\Services\ReportingService::class);
        $clientId = $request->client_id ? (int) $request->client_id : null;

        return $this->exportRevenueBreakdown(
            report: $reportingService->getRfqRevenueReport($from, $to, $pmId, $clientId),
            variant: 'rfq',
            format: $format,
            scopeLabel: 'Project Manager',
        );
    }

    public function exportClientRevenueReport(Request $request, string $format)
    {
        [$from, $to] = $this->resolveReportRange($request);
        $pmId = auth()->id();
        $reportingService = app(\App\Services\ReportingService::class);

        return $this->exportRevenueBreakdown(
            report: $reportingService->getClientRevenueReport($from, $to, $pmId),
            variant: 'client',
            format: $format,
            scopeLabel: 'Project Manager',
        );
    }

    // ==================== HELPERS ====================

    private function resolveReportRange(Request $request): array
    {
        $from = $request->from ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : now();

        return [$from, $to];
    }

    private function exportRevenueBreakdown(array $report, string $variant, string $format, string $scopeLabel)
    {
        $format = strtolower($format);

        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $viewData = [
            'report' => $report,
            'variant' => $variant,
            'scopeLabel' => $scopeLabel,
            'generatedAt' => now(),
        ];

        $filename = $this->buildRevenueExportFilename($variant, $format, $report['period'] ?? []);

        if ($format === 'pdf') {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.revenue-breakdown', $viewData)
                ->setPaper('a4', 'landscape');

            return $pdf->download($filename);
        }

        $html = view('exports.revenue-breakdown-excel', $viewData)->render();

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private function buildRevenueExportFilename(string $variant, string $format, array $period): string
    {
        $from = $period['from'] ?? now()->toDateString();
        $to = $period['to'] ?? now()->toDateString();
        $extension = $format === 'pdf' ? 'pdf' : 'xls';
        $label = $variant === 'rfq' ? 'rfq-revenue-report' : 'client-revenue-report';

        return "{$label}-{$from}-to-{$to}.{$extension}";
    }

    /**
     * Authorize a PM to act on this service request.
     *
     * PMs can act on RFQs that are either:
     *  - explicitly assigned to them, OR
     *  - currently unassigned (in which case taking the first action
     *    auto-claims the RFQ for that PM, mirroring the broadened
     *    visibility from Batch C — #16).
     *
     * RFQs assigned to a different PM remain locked.
     */
    private function authorizeForPm(ServiceRequest $serviceRequest): void
    {
        $pmId = auth()->id();

        if ($serviceRequest->assigned_pm_id === $pmId) {
            return;
        }

        if ($serviceRequest->assigned_pm_id === null) {
            // Auto-claim so subsequent actions and metrics roll up correctly.
            $serviceRequest->update(['assigned_pm_id' => $pmId]);
            $serviceRequest->refresh();
            return;
        }

        abort(403, 'This RFQ is assigned to another project manager.');
    }

    private function calculateCompletionRate(int $pmId): string
    {
        $total = ServiceRequest::forPm($pmId)->count();
        $completed = ServiceRequest::forPm($pmId)
            ->whereIn('status', ['closed', 'archived'])->count();

        return $total > 0 ? round(($completed / $total) * 100, 1) . '%' : '0%';
    }
}
