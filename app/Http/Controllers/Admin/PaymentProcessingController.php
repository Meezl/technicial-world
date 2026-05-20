<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobAssignment;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\TechnicianPaymentEntry;
use App\Models\TechnicianPaymentSheet;
use App\Services\TechnicianPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PaymentProcessingController extends Controller
{
    public function __construct(private TechnicianPaymentService $paymentService) {}

    public function index()
    {
        $sheets = TechnicianPaymentSheet::with(['creator', 'entries.technician.user', 'entries.serviceRequest'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $serviceRequests = ServiceRequest::select('id', 'request_id', 'job_reference', 'description')
            ->whereNotIn('status', ['ARCHIVED', 'CLOSED'])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Admin/PaymentProcessing', [
            'sheets' => $sheets,
            'serviceRequests' => $serviceRequests,
        ]);
    }

    /**
     * AJAX: return technicians assigned to a specific job.
     */
    public function techniciansForJob(Request $request)
    {
        $request->validate(['service_request_id' => 'required|exists:service_requests,id']);

        $sr = ServiceRequest::with([
            'technician.user',
            'leadTechnician.user',
            'subTasks.technician.user',
        ])->find($request->service_request_id);

        $seen = [];
        $technicians = [];

        $push = function ($tech) use (&$seen, &$technicians) {
            if ($tech && !in_array($tech->id, $seen)) {
                $seen[] = $tech->id;
                $technicians[] = [
                    'id' => $tech->id,
                    'technician_id' => $tech->technician_id,
                    'name' => $tech->user->name ?? 'Unknown',
                ];
            }
        };

        $push($sr->technician);
        $push($sr->leadTechnician);
        foreach ($sr->subTasks ?? [] as $task) {
            $push($task->technician);
        }

        // Also pull from job assignments
        JobAssignment::where('service_request_id', $sr->id)
            ->whereIn('status', ['pending', 'accepted', 'completed'])
            ->with('technician.user')
            ->get()
            ->each(fn($a) => $push($a->technician));

        return response()->json($technicians);
    }

    /**
     * AJAX: compute auto-populated amounts for a technician/job combo.
     *
     * approved_amount resolution order:
     *   1. JobAssignment.agreed_compensation (most specific — what was agreed for this tech on this job)
     *   2. ServiceSubTask.agreed_compensation where the technician is assigned under this SR
     *   3. ServiceRequest.technician_payout (admin-set payout amount for the job)
     *   4. ServiceRequest.quote_labor_cost (labor portion of the approved client quote)
     *
     * cumulative_amount_due = approved_amount × (validated_progress_pct / 100)
     */
    public function computeAmounts(Request $request)
    {
        $request->validate([
            'service_request_id' => 'required|exists:service_requests,id',
            'technician_id' => 'required|exists:technicians,id',
        ]);

        $srId = (int) $request->service_request_id;
        $techId = (int) $request->technician_id;

        $serviceRequest = ServiceRequest::with('subTasks')->find($srId);

        $approvedAmount = $this->paymentService->resolveApprovedAmount($serviceRequest, $techId);
        $progress = $this->paymentService->getValidatedProgressForTechnician($serviceRequest, $techId);
        $cumulativeDue = $this->paymentService->calculateCumulativeAmountDue(
            $serviceRequest,
            $techId,
            $approvedAmount,
            $progress
        );
        $releasedViaMilestones = $this->paymentService->getUnlockedMilestoneAllocationTotal($serviceRequest, $techId);

        // Previous cumulative paid = cumulative_amount_due on the most recent finalized sheet
        $prevEntry = TechnicianPaymentEntry::where('technician_id', $techId)
            ->where('service_request_id', $srId)
            ->whereHas('paymentSheet', fn($q) => $q->where('status', TechnicianPaymentSheet::STATUS_FINALIZED))
            ->orderByDesc('id')
            ->first();

        $previousPaid = $prevEntry ? (float) $prevEntry->cumulative_amount_due : 0.0;
        $currentPayable = max(0, round($cumulativeDue - $previousPaid, 2));

        return response()->json([
            'approved_amount' => $approvedAmount,
            'cumulative_progress_pct' => $progress,
            'cumulative_amount_due' => $cumulativeDue,
            'released_via_milestones' => $releasedViaMilestones,
            'previous_cumulative_paid' => round($previousPaid, 2),
            'current_period_payable' => $currentPayable,
        ]);
    }

    /**
     * Create and finalize a payment processing sheet.
     */
    public function store(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'notes' => 'nullable|string|max:1000',
            'entries' => 'required|array|min:1',
            'entries.*.service_request_id' => 'required|exists:service_requests,id',
            'entries.*.technician_id' => 'required|exists:technicians,id',
            'entries.*.approved_amount' => 'required|numeric|min:0',
            'entries.*.cumulative_progress_pct' => 'required|integer|min:0|max:100',
            'entries.*.cumulative_amount_due' => 'required|numeric|min:0',
            'entries.*.previous_cumulative_paid' => 'required|numeric|min:0',
            'entries.*.current_period_payable' => 'required|numeric|min:0',
            'finalize' => 'boolean',
        ]);

        DB::transaction(function () use ($request) {
            $sheet = TechnicianPaymentSheet::create([
                'sheet_reference' => TechnicianPaymentSheet::generateReference(),
                'period_start' => $request->period_start,
                'period_end' => $request->period_end,
                'created_by' => auth()->id(),
                'notes' => $request->notes,
            ]);

            foreach ($request->entries as $row) {
                TechnicianPaymentEntry::create([
                    'payment_sheet_id' => $sheet->id,
                    'technician_id' => $row['technician_id'],
                    'service_request_id' => $row['service_request_id'],
                    'agreed_compensation' => $row['approved_amount'],
                    'cumulative_progress_pct' => $row['cumulative_progress_pct'],
                    'cumulative_amount_due' => $row['cumulative_amount_due'],
                    'previous_cumulative_paid' => $row['previous_cumulative_paid'],
                    'current_period_payable' => $row['current_period_payable'],
                ]);
            }

            $sheet->recalculateTotal();

            if ($request->boolean('finalize', true)) {
                $this->paymentService->finalize($sheet);
            }
        });

        return redirect()->route('admin.payment-processing.index')
            ->with('success', 'Payment sheet created and finalized successfully.');
    }

    /**
     * View a specific historical sheet.
     */
    public function show(TechnicianPaymentSheet $sheet)
    {
        $sheet->load(['creator', 'entries.technician.user', 'entries.serviceRequest']);

        $serviceRequests = ServiceRequest::select('id', 'request_id', 'job_reference', 'description')
            ->whereNotIn('status', ['ARCHIVED', 'CLOSED'])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Admin/PaymentProcessing', [
            'sheets' => TechnicianPaymentSheet::with(['creator', 'entries.technician.user', 'entries.serviceRequest'])
                ->orderBy('created_at', 'desc')
                ->paginate(20),
            'serviceRequests' => $serviceRequests,
            'viewSheet' => $sheet,
        ]);
    }

    /**
     * Download a payment sheet as PDF.
     */
    public function download(TechnicianPaymentSheet $sheet)
    {
        $sheet->load(['entries.technician.user', 'entries.serviceRequest', 'creator']);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.payment-sheet', ['sheet' => $sheet]);

        return $pdf->download("payment-sheet-{$sheet->sheet_reference}.pdf");
    }
}
