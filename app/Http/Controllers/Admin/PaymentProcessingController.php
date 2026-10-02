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
    /**
     * Auto-roll-down — given period_start + period_end, return one entry
     * per (technician, service_request) with validated-progress and amounts
     * already filled in. Admin can then edit current_period_payable per row
     * before submitting the sheet. Mirrors what the PM "Compute" button does.
     */
    /**
     * Fill a draft sheet with the technicians owed money for work settled in
     * the chosen period.
     *
     * The period used to be validated and then ignored. It parsed period_end
     * into a variable nothing read, and then walked every JobAssignment in the
     * system — so picking "the last five days" returned every payable job on
     * the books, from any date, and an admin processing a week's payments was
     * handed the whole history to prune by hand.
     *
     * It now asks the payment service the same two questions the sheet itself
     * asks: which technician–job pairs had progress validated inside the
     * window, and what was each owed at the close of it. One source for both,
     * so the preview an admin approves is what the sheet computes.
     */
    public function autoComputeEntries(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $periodStart = \Carbon\Carbon::parse($request->period_start)->startOfDay();
        $periodEnd   = \Carbon\Carbon::parse($request->period_end)->endOfDay();

        $pairs = $this->paymentService->eligiblePairsInPeriod($periodStart, $periodEnd);

        // Diagnostics so the admin sees why a technician they expected is not
        // listed, rather than being left to guess at an empty table.
        $skipped = [
            'no_assignment_data'    => 0,
            'no_agreed_amount'      => 0,
            'no_validated_progress' => 0,
            'already_fully_paid'    => 0,
        ];

        $rows = [];
        foreach ($pairs as $pair) {
            $sr   = ServiceRequest::find($pair->service_request_id);
            $tech = Technician::with('user')->find($pair->technician_id);

            if (!$sr || !$tech) {
                $skipped['no_assignment_data']++;
                continue;
            }

            // The shared resolver, so a crew member paid through their lead
            // resolves to nothing and a fee held on a sub-task is still found.
            $agreed = $this->paymentService->resolveApprovedAmount($sr, $tech->id);
            if ($agreed <= 0) {
                $skipped['no_agreed_amount']++;
                continue;
            }

            // As at the close of the period, not as at today — otherwise a
            // sheet for last week pays for this week's progress.
            $progress = $this->paymentService->getValidatedProgressAsOf($sr, $tech, $periodEnd);
            if ($progress <= 0) {
                $skipped['no_validated_progress']++;
                continue;
            }

            $cumulativeDue  = round($agreed * ($progress / 100), 2);
            $alreadyPaid    = $this->paymentService->getTotalLabourPaid($sr->id, $tech->id);
            $currentPayable = max(0, round($cumulativeDue - $alreadyPaid, 2));

            if ($currentPayable <= 0) {
                $skipped['already_fully_paid']++;
                continue;
            }

            $rows[] = [
                'service_request_id'        => $sr->id,
                'service_request_label'     => $sr->request_id . ' — ' . \Illuminate\Support\Str::limit($sr->description ?? '', 50),
                'technician_id'             => $tech->id,
                'technician_label'          => $tech->user->name ?? ('Tech ' . $tech->id),
                'approved_amount'           => $agreed,
                'cumulative_progress_pct'   => $progress,
                'cumulative_amount_due'     => $cumulativeDue,
                'previous_cumulative_paid'  => $alreadyPaid,
                'current_period_payable'    => $currentPayable,
            ];
        }

        return response()->json([
            'skipped' => $skipped,
            'count'   => count($rows),
            'total'   => array_sum(array_column($rows, 'current_period_payable')),
            'entries' => $rows,
            // Echoed back so the screen can say which window it answered for.
            'period'  => [
                'start' => $periodStart->toDateString(),
                'end'   => $periodEnd->toDateString(),
            ],
        ]);
    }

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

        // Cumulative due = agreed × validated%. No milestone cap here —
        // consistent with payApprovedProgressReport and the manual
        // storeTechnicianPayment path (client's mental model: pay techs
        // their agreed dues as they earn them; milestone allocations
        // are for external client invoicing, not for gating tech pay).
        // The overpayment guard in store() below still refuses anything
        // that would exceed the full agreed compensation.
        $cumulativeDue = round($approvedAmount * ($progress / 100), 2);

        // Milestone info is still returned as a display hint so ops can
        // see how much of the cumulative amount is "backed by" reached
        // milestones — advisory only, no longer a cap.
        $releasedViaMilestones = $this->paymentService->getUnlockedMilestoneAllocationTotal($serviceRequest, $techId);

        // Previous paid — delegate to the canonical getTotalLabourPaid so
        // this compute matches what the Labour card and per-tech report
        // show. Was previously reading cumulative_amount_due off the last
        // sheet entry, which drifted from actual paid_amount once Mark
        // Paid started varying from the scheduled amount (Layer 3 work).
        $previousPaid = $this->paymentService->getTotalLabourPaid($srId, $techId);
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

        // Overpayment guard: per (technician, service_request), the total already paid
        // plus this row's current_period_payable must not exceed the AGREED compensation.
        // We deliberately do NOT cap by validated-progress earnings — admin is free to
        // pay out any cash-flow amount they choose within the agreed total.
        $errors = [];
        foreach ($request->entries as $index => $row) {
            $srId = (int) $row['service_request_id'];
            $techId = (int) $row['technician_id'];
            $agreed = (float) $row['approved_amount'];
            $payable = (float) $row['current_period_payable'];

            $serviceRequest = ServiceRequest::find($srId);
            $resolvedAgreed = $this->paymentService->resolveApprovedAmount($serviceRequest, $techId);
            $cap = $resolvedAgreed > 0 ? $resolvedAgreed : $agreed;

            if ($cap <= 0) {
                $errors["entries.$index.current_period_payable"] = 'No agreed compensation set for this technician on this job. Set the assignment fee first.';
                continue;
            }

            $alreadyPaid = $this->paymentService->getTotalLabourPaid($srId, $techId);
            $newTotal = round($alreadyPaid + $payable, 2);

            if ($newTotal > $cap + 0.001) {
                $errors["entries.$index.current_period_payable"] = sprintf(
                    'Overpayment blocked: KES %s + KES %s = KES %s exceeds agreed compensation KES %s.',
                    number_format($alreadyPaid, 2),
                    number_format($payable, 2),
                    number_format($newTotal, 2),
                    number_format($cap, 2)
                );
            }
        }

        if (!empty($errors)) {
            return back()->withErrors($errors)->withInput();
        }

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
