<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\AuditLog;
use App\Models\ServiceRequest;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use App\Models\Tool;
use App\Mail\QuotationSent;
use App\Mail\QuotationRejected;
use App\Models\PaymentRequest;
use App\Notifications\PaymentRequestNotification;
use Illuminate\Support\Facades\Mail;
use App\Mail\JobAssigned;
use App\Mail\TechnicianAssigned;
use App\Models\JobAssignment;
use App\Models\ServiceSubTask;
use App\Models\Payment;
use App\Models\PaymentMilestone;
use App\Models\PaymentMilestoneAllocation;
use App\Models\ProgressReport;
use App\Models\TechnicianPayment;
use App\Models\TechnicianPaymentEntry;
use App\Models\Expenditure;
use App\Services\ProgressService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminDashboardController extends Controller
{
    public function index()
    {
        // Real KPI stats
        $totalJobs = ServiceRequest::count();
        $completedJobs = ServiceRequest::where('status', 'completed')->count();
        $completionRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0;
        $pendingRfqs = ServiceRequest::where('rfq_status', 'pending')->count();
        $avgRating = (float) ServiceRequest::whereNotNull('rating')->avg('rating');

        $stats = [
            'totalJobs'      => $totalJobs,
            'completionRate' => $completionRate . '%',
            'pendingRfqs'    => $pendingRfqs,
            'averageRating'  => $avgRating > 0 ? number_format($avgRating, 1) : 'N/A',
        ];

        // Real status breakdown
        $statusData = ServiceRequest::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        // Real category breakdown (top 7)
        $categoryData = ServiceRequest::query()
            ->leftJoin('service_categories', 'service_requests.service_category_id', '=', 'service_categories.id')
            ->selectRaw('COALESCE(service_categories.name, "Uncategorized") as name, COUNT(*) as cnt')
            ->groupBy('name')
            ->orderByDesc('cnt')
            ->limit(7)
            ->pluck('cnt', 'name');

        // Real monthly trend: completed jobs by month for current + prior year
        $currentYear = (int) date('Y');
        $priorYear   = $currentYear - 1;
        $trendData = [];
        foreach ([$priorYear, $currentYear] as $year) {
            $monthly = array_fill(1, 12, 0);
            $rows = ServiceRequest::query()
                ->where('status', 'completed')
                ->whereYear('updated_at', $year)
                ->selectRaw('MONTH(updated_at) as m, COUNT(*) as cnt')
                ->groupBy('m')
                ->pluck('cnt', 'm');
            foreach ($rows as $m => $cnt) {
                $monthly[$m] = $cnt;
            }
            $trendData[$year] = array_values($monthly);
        }

        $chartData = [
            'statusData'   => $statusData,
            'categoryData' => $categoryData,
            'trendData'    => $trendData,
        ];

        // Real recent activity — latest 8 events from service_requests, payments, technicians
        $activity = collect();

        ServiceRequest::with('user:id,name')->orderByDesc('updated_at')->limit(4)->get()
            ->each(function ($sr) use ($activity) {
                $statusLabel = ucfirst(str_replace('_', ' ', (string) $sr->status));
                $activity->push([
                    'id'      => 'sr-' . $sr->id,
                    'type'    => 'job',
                    'icon'    => 'fas fa-clipboard-list',
                    'message' => $sr->request_id . ' — ' . $statusLabel . ' (' . ($sr->user->name ?? 'Unknown') . ')',
                    'ts'      => $sr->updated_at,
                ]);
            });

        Payment::where('status', 'completed')->with('user:id,name')->orderByDesc('paid_at')->limit(3)->get()
            ->each(function ($p) use ($activity) {
                $activity->push([
                    'id'      => 'pay-' . $p->id,
                    'type'    => 'payment',
                    'icon'    => 'fas fa-credit-card',
                    'message' => 'KES ' . number_format($p->amount, 0) . ' received — ' . ($p->user->name ?? 'Client'),
                    'ts'      => $p->paid_at,
                ]);
            });

        Technician::with('user:id,name')->orderByDesc('created_at')->limit(2)->get()
            ->each(function ($t) use ($activity) {
                $activity->push([
                    'id'      => 'tech-' . $t->id,
                    'type'    => 'user',
                    'icon'    => 'fas fa-user-plus',
                    'message' => 'New technician onboarded: ' . ($t->user->name ?? 'Unnamed'),
                    'ts'      => $t->created_at,
                ]);
            });

        $recentActivity = $activity->sortByDesc('ts')->take(8)->values();

        // Payment data-integrity health check. Surfaces duplicate payment
        // rows so admin spots the issue before a client does.
        $healthAlerts = $this->paymentHealthAlerts();

        return Inertia::render('Admin/Dashboard', [
            'stats'          => $stats,
            'chartData'      => $chartData,
            'recentActivity' => $recentActivity,
            'healthAlerts'   => $healthAlerts,
        ]);
    }

    /**
     * Detect data-integrity issues admin needs to act on.
     */
    private function paymentHealthAlerts(): array
    {
        $alerts = [];

        // Duplicate completed payments — same payment_request_id appears more than once.
        $dupesByPr = \Illuminate\Support\Facades\DB::table('payments')
            ->select('payment_request_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as cnt'))
            ->where('status', 'completed')
            ->whereNotNull('payment_request_id')
            ->groupBy('payment_request_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($dupesByPr->count() > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title'    => 'Duplicate payment records detected',
                'message'  => $dupesByPr->count() . ' payment request' . ($dupesByPr->count() === 1 ? ' has' : 's have') .
                              ' more than one completed Payment row, which inflates client "Paid" totals. ' .
                              'Run the dedup tool on the affected jobs.',
                'action'   => [
                    'label' => 'Run system-wide dedupe',
                    'href'  => null,  // No safe one-click — admin must run via per-job button or shell
                ],
            ];
        }

        // Paid PaymentRequests with no Payment row at all (the inverse problem)
        $orphaned = \App\Models\PaymentRequest::where('status', 'paid')
            ->whereDoesntHave('serviceRequest.payments', fn ($q) => $q->where('status', 'completed'))
            ->count();

        if ($orphaned > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title'    => 'Paid requests with no payment record',
                'message'  => $orphaned . ' payment request' . ($orphaned === 1 ? '' : 's') .
                              ' marked paid have no matching Payment row. The client\'s portal may show wrong balances.',
                'action'   => null,
            ];
        }

        // Technician profile changes awaiting admin approval
        $pendingProfileCount = Technician::whereNotNull('pending_profile_changes')->count();
        if ($pendingProfileCount > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title'    => 'Technician profile changes awaiting approval',
                'message'  => $pendingProfileCount . ' technician' . ($pendingProfileCount === 1 ? ' has' : 's have') .
                              ' submitted profile updates (skills or bio) that need your review before they go live.',
                'action'   => ['label' => 'Review on Technicians page', 'href' => '/admin/technicians'],
            ];
        }

        return $alerts;
    }

    public function technicians()
    {
        $activeStatuses = [
            ServiceRequest::STATUS_ASSIGNED,
            ServiceRequest::STATUS_IN_PROGRESS,
            ServiceRequest::STATUS_QUEUED,
            ServiceRequest::STATUS_AWAITING_TECH_AVAILABILITY,
        ];

        $technicians = Technician::with(['user', 'documents'])->orderBy('created_at', 'desc')->get();

        $technicians->each(function ($tech) use ($activeStatuses) {
            $tech->active_job_refs = $tech->serviceRequests()
                ->whereIn('status', $activeStatuses)
                ->pluck('request_id')
                ->values();
        });

        return Inertia::render('Admin/Technicians', [
            'technicians' => $technicians,
            'documentTypes' => \App\Models\TechnicianDocument::documentTypes(),
            // Pass the real service categories so the Specialization dropdown
            // on the create-technician form stays in sync as admin adds/removes
            // categories from the Service Categories page.
            'serviceCategories' => \App\Models\ServiceCategory::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function jobs(Request $request)
    {
        $query = ServiceRequest::with(['user', 'serviceCategory', 'technician.user', 'leadTechnician.user', 'subTasks.technician.user'])
            ->orderBy('created_at', 'desc');

        // Search Filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('request_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Status Filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $jobs = $query->paginate(10)->withQueryString();

        $technicians = Technician::with('user')
            ->orderBy('rating', 'desc')
            ->get();

        return Inertia::render('Admin/Jobs', [
            'jobs' => $jobs,
            'technicians' => $technicians,
            'filters' => $request->only(['search', 'status'])
        ]);
    }

    public function showJob(ServiceRequest $serviceRequest)
    {
        $job = $serviceRequest->load([
            'user',
            'serviceCategory',
            'technician.user',
            'leadTechnician.user',
            'subTasks.technician.user',
            'jobAssignments.technician.user',
            'budget',
            'technicianPayments.technician.user',
            // Payment sheet entries — needed so the frontend can compute
            // per-tech Paid/Outstanding correctly. Without these the
            // breakdown table shows 0 paid on any job that pays via
            // sheets rather than direct technicianPayments.
            'paymentEntries:id,service_request_id,technician_id,status,current_period_payable,paid_amount',
            'expenditures',
            'payments',
            'paymentRequests',
            'milestones',
            'milestones.allocations.technician.user',
            'progressReports.technician.user',
            'progressReports.submitter',
            'progressReports.validator',
            'progressReports.subTask',
            'progressReports.photos',
            // Ops-only edit history for each report's notes fields.
            'progressReports.noteVersions.editor:id,name',
            // Removed reports, so the office can see what was taken out and
            // put it back. Soft deletes hide them from the relation above.
            'removedProgressReports.technician.user:id,name',
            'removedProgressReports.deletedBy:id,name',
            // Job-level photos: client evidence and anything ops attached
            // outside a formal progress report.
            'photos.uploader:id,name',
            // Billable activity raised under this job — samples, site visits,
            // call-backs — with whatever each one billed.
            'tickets.paymentRequests:id,ticket_id,payment_request_id,amount,status,paid_at',
            'tickets.feeAuthoriser:id,name',
            'tickets.creator:id,name',
            // Case analyses, sample reports, signed approvals.
            'documents.uploader:id,name',
            // Every variation including internal ones — admin sees the lot.
            'variationOrders.items',
            'variationOrders.creator:id,name',
            'variationOrders.approver:id,name',
            'variationOrders.billingSchedule',
            // Both halves of the click-through: from a variation to the fee
            // changes it authorised, and from a fee change back to it.
            'variationOrders.compensationAmendments.technician.user:id,name',
            // Money owed back on this job.
            'refunds.requester:id,name',
            'refunds.approver:id,name',
            'compensationAmendments.variationOrder:id,vo_number,net_amount',
            'compensationAmendments.technician.user:id,name',
        ]);

        $technicians = Technician::with('user')
            ->orderBy('rating', 'desc')
            ->get();

        // Compute budget vs actual for this SR
        $budgetSummary = $this->buildBudgetSummary($job);

        return Inertia::render('Admin/JobDetails', [
            'job' => $job,
            'technicians' => $technicians,
            'budgetSummary' => $budgetSummary,
            // Contract money and attendance money, reported side by side.
            // They are summed only in the total_* lines — the contract cap
            // must never see an attendance fee.
            'billingSummary' => app(\App\Services\BillingService::class)->summary($serviceRequest),
            // The running total behind the quotation form: original quote,
            // every variation, and the value after each one.
            'variationLedger' => app(\App\Services\VariationOrderService::class)->ledger($serviceRequest),
        ]);
    }

    public function validateProgress(Request $request, ProgressReport $progressReport)
    {
        // Validating with photos attached is an upload like any other, and
        // this is the path the admin lost a report on.
        \App\Support\UploadRuntime::prepare('admin.progress.validate');

        $request->validate([
            'validated_percent' => 'required|integer|min:0|max:100',
            'validation_notes' => 'nullable|string|max:2000',
            'client_visible_notes' => 'nullable|string|max:2000',
            'remove_photo_ids' => 'nullable|array',
            'admin_photos' => 'nullable|array|max:6',
            'admin_photos.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
        ]);

        $adminPhotos = $request->hasFile('admin_photos') ? $request->file('admin_photos') : [];

        // Recorded as an admin ratification so the report can say who settled
        // it, and releases billing — the office decision it has always been.
        app(ProgressService::class)->validate($progressReport, auth()->id(), $request->only([
            'validated_percent',
            'validation_notes',
            'client_visible_notes',
            'remove_photo_ids',
        ]), $adminPhotos, validatedAs: \App\Models\ProgressReport::AS_ADMIN);

        return back()->with('success', 'Progress validated.');
    }

    /**
     * Send a report back to the lead technician with a reason.
     *
     * The office's counterpart of a lead returning work to their crew. Often
     * a question rather than a verdict — the lead can correct the figure,
     * rewrite the notes, or answer with a comment and put it up again.
     */
    public function returnProgressReport(Request $request, ProgressReport $progressReport)
    {
        $data = $request->validate([
            'rejection_reason' => 'required|string|min:5|max:1000',
        ], [
            'rejection_reason.required' => 'Tell the lead what you need looking at.',
            'rejection_reason.min' => 'Give the lead something to act on — a few words at least.',
        ]);

        app(ProgressService::class)->reject(
            $progressReport,
            auth()->id(),
            $data['rejection_reason'],
            \App\Models\ProgressReport::AS_ADMIN
        );

        return back()->with('success', 'Sent back to the lead technician.');
    }

    /**
     * Admin files a progress report on a technician's behalf.
     *
     * The counterpart of the PM's route and of a lead covering for their crew:
     * when nobody on site got the report in, the office can put the record
     * straight. The report is about the named technician's work and says on
     * its face that an admin wrote it.
     */
    public function createProgressOnBehalf(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'percent_complete'    => 'required|integer|min:0|max:100',
            'notes'               => 'nullable|string|max:2000',
            'report_date'         => 'nullable|date',
            'technician_id'       => 'nullable|integer|exists:technicians,id',
            'service_sub_task_id' => 'nullable|integer|exists:service_sub_tasks,id',
            'photos'              => 'nullable|array|max:6',
            'photos.*'            => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
        ]);

        if (!empty($data['service_sub_task_id'])) {
            $subTask = $serviceRequest->subTasks()->find($data['service_sub_task_id']);
            if (!$subTask) {
                return back()->withErrors([
                    'service_sub_task_id' => 'That sub-task does not belong to this job.',
                ]);
            }
            // Attribute to whoever holds the sub-task unless told otherwise.
            $data['technician_id'] = $data['technician_id'] ?? $subTask->technician_id;
        }

        app(ProgressService::class)->createOnBehalf(
            $serviceRequest,
            auth()->id(),
            $data,
            $request->file('photos', []),
            authoredAs: \App\Models\ProgressReport::AS_ADMIN
        );

        return back()->with('success', 'Progress report filed on the technician\'s behalf.');
    }

    /**
     * Backfill a final 100% progress report for jobs where the technician
     * tapped "Mark Complete" (which set status=completed + progress=100 on
     * the service request) without first submitting/getting a 100% progress
     * report validated. The payment system reads from the report, not the SR,
     * so this brings them back in sync and unlocks the final payment.
     */
    /**
     * Approve pending profile changes (skills + bio) submitted by a
     * technician via the self-service profile page. Admin can approve all,
     * approve specific fields, or reject (which just clears the pending).
     */
    public function approveTechnicianProfileChanges(Request $request, Technician $technician)
    {
        $request->validate([
            'action' => 'required|in:approve_all,approve_field,reject',
            'field'  => 'nullable|in:skills,bio',
        ]);

        $pending = (array) ($technician->pending_profile_changes ?? []);
        if (empty($pending)) {
            return back()->with('error', 'No pending profile changes to act on.');
        }

        if ($request->action === 'reject') {
            $technician->update(['pending_profile_changes' => null]);
            return back()->with('success', 'Pending profile changes rejected.');
        }

        // Apply requested fields
        $applyAll = $request->action === 'approve_all';
        $applyField = $request->action === 'approve_field' ? $request->field : null;

        $updates = [];
        if ($applyAll || $applyField === 'skills') {
            if (array_key_exists('skills', $pending)) {
                $updates['skills'] = $pending['skills'];
                unset($pending['skills']);
            }
        }
        if ($applyAll || $applyField === 'bio') {
            if (array_key_exists('bio', $pending)) {
                $updates['bio'] = $pending['bio'];
                unset($pending['bio']);
            }
        }

        if (empty($updates)) {
            return back()->with('error', 'No matching pending field to approve.');
        }

        // Strip the meta key if nothing else remains
        $remaining = array_diff_key($pending, ['submitted_at' => null]);
        $updates['pending_profile_changes'] = empty($remaining) ? null : $pending;

        $technician->update($updates);

        return back()->with('success', 'Profile changes approved.');
    }

    public function backfillFinalProgress(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        if (!$serviceRequest->technician_id) {
            return back()->with('error', 'No technician assigned to this job — cannot backfill a progress report.');
        }

        // If a 100% report is already validated, nothing to do
        $latestPct = (int) ($serviceRequest->progressReports()
            ->where('is_validated', true)
            ->where('technician_id', $serviceRequest->technician_id)
            ->orderBy('report_date', 'desc')
            ->value('validated_percent') ?? 0);

        if ($latestPct >= 100) {
            return back()->with('error', 'This job already has a validated 100% progress report. No backfill needed.');
        }

        $report = \App\Models\ProgressReport::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id'      => $serviceRequest->technician_id,
            'submitted_by'       => auth()->id(),
            'report_date'        => now()->toDateString(),
            'percent_complete'   => 100,
            'notes'              => $request->input('notes') ?: 'Final report backfilled by admin to close out job completion.',
            'is_validated'       => true,
            'validated_by'       => auth()->id(),
            'validated_at'       => now(),
            'validated_percent'  => 100,
            'validation_notes'   => 'Auto-validated as part of backfill — admin confirmed completion separately.',
            'client_visible_notes' => $request->input('notes') ?: 'Job completed.',
            'is_pm_authored'     => true,
        ]);

        // Sync service request — this triggers billing milestones too
        app(ProgressService::class)->retriggerMilestonesForApprovedRevision($serviceRequest->fresh());

        $serviceRequest->update([
            'progress_percentage' => 100,
            'status' => ServiceRequest::STATUS_COMPLETED,
            'completed_date' => $serviceRequest->completed_date ?? now(),
        ]);

        return back()->with('success', '100% final progress report backfilled. Payment processing can now bill the remaining balance.');
    }

    public function storeTechnician(Request $request)
    {
        // Password is no longer entered by the admin — the system generates a
        // strong temporary password and emails it to the technician (#17).
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'specialization' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'kra_pin' => 'nullable|string|max:32',
            'availability' => 'required|in:available,busy,on_leave',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array',
            // Mandatory documents
            'doc_nca_license' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'doc_tertiary_cert' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'doc_id_card' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'doc_passport_photo' => 'required|file|mimes:jpg,jpeg,png|max:3072',
            'doc_kra_pin' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'doc_nca_license.required' => 'NCA License is required.',
            'doc_tertiary_cert.required' => 'Tertiary education certificate is required.',
            'doc_id_card.required' => 'ID Card is required.',
            'doc_passport_photo.required' => 'Passport size photo is required.',
            'doc_kra_pin.required' => 'KRA PIN Certificate is required.',
        ]);

        // Auto-generate a 12-char temporary password (mixed case + digits) so
        // there's no manual password field to mistype or forget. The technician
        // changes it on first sign-in from their profile screen.
        $temporaryPassword = $this->generateTemporaryPassword();

        // Document uploads make this a slow request on a phone; give it room
        // rather than letting the default 30s kill a half-finished onboarding.
        @set_time_limit(180);

        // One transaction for the account and the profile. Previously the
        // user was created first and committed on its own, so when the
        // technician insert failed the account survived — and the admin's
        // retry then failed validation on the now-taken email address,
        // bouncing them back to an empty form with no way through.
        [$user, $technician] = DB::transaction(function () use ($request, $temporaryPassword) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($temporaryPassword),
                'phone' => $request->phone,
                'role' => 'technician',
            ]);

            $attributes = [
                'user_id' => $user->id,
                'specialization' => $request->specialization,
                'location' => $request->location,
                'kra_pin' => $request->kra_pin ? strtoupper(trim($request->kra_pin)) : null,
                'availability' => $request->availability,
                'bio' => $request->bio,
                'skills' => $request->skills,
                'rating' => 0,
                'total_jobs' => 0,
                // Admin is the vetting authority — they've uploaded the
                // mandatory documents at creation, so mark approved. Without
                // this the PM Assign Technician dropdown filters them out
                // (#16).
                'vetting_status' => Technician::VETTING_APPROVED,
                'vetted_by' => auth()->id(),
                'vetted_at' => now(),
            ];

            // An admin-supplied reference is used as given; otherwise take the
            // next free one, retrying past anyone who grabbed it first.
            $technician = $request->technician_id
                ? Technician::create($attributes + ['technician_id' => $request->technician_id])
                : Technician::createWithReference($attributes);

            return [$user, $technician];
        });

        // Store mandatory documents
        $documentMap = [
            'doc_nca_license' => 'nca_license',
            'doc_tertiary_cert' => 'tertiary_cert',
            'doc_id_card' => 'id_card',
            'doc_passport_photo' => 'passport_photo',
            'doc_kra_pin' => 'pin_cert',
        ];

        foreach ($documentMap as $inputName => $docType) {
            if ($request->hasFile($inputName)) {
                $file = $request->file($inputName);
                $path = $file->store('technician-documents/' . $technician->id, 'public');
                \App\Models\TechnicianDocument::create([
                    'technician_id' => $technician->id,
                    'document_type' => $docType,
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Email the credentials after the response has gone. Sending inline
        // left the admin watching a "Saving…" button through an SMTP
        // round-trip on top of five document uploads — long enough on a phone
        // to look like a hang and provoke a second submission.
        $userId = $user->id;
        $technicianId = $technician->id;
        app()->terminating(function () use ($userId, $technicianId, $temporaryPassword) {
            try {
                $u = User::find($userId);
                $t = Technician::find($technicianId);
                if ($u && $t) {
                    Mail::to($u->email)->send(new \App\Mail\TechnicianAccountCreated($u, $t, $temporaryPassword));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('TechnicianAccountCreated email failed', [
                    'technician_id' => $technicianId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return redirect()->route('admin.technicians')->with('success',
            "Technician {$technician->technician_id} created. Login credentials are on their way to {$user->email}."
        );
    }

    /**
     * Generates a strong, human-typeable temporary password (12 chars,
     * mixed case + digits, avoids ambiguous chars like 0/O, l/1).
     */
    private function generateTemporaryPassword(): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $len = strlen($alphabet);
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, $len - 1)];
        }
        return $password;
    }

    public function updateTechnician(Request $request, Technician $technician)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $technician->user_id,
            'phone' => 'nullable|string|max:20',
            'specialization' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'kra_pin' => 'nullable|string|max:32',
            'availability' => 'required|in:available,busy,on_leave',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array',
        ]);

        // Update user
        $technician->user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ]);

        // Update technician
        $technician->update([
            'specialization' => $request->specialization,
            'location' => $request->location,
            'kra_pin' => $request->kra_pin ? strtoupper(trim($request->kra_pin)) : null,
            'availability' => $request->availability,
            'bio' => $request->bio,
            'skills' => $request->skills,
        ]);

        return redirect()->route('admin.technicians')->with('success', 'Technician updated successfully!');
    }

    public function destroyTechnician(Technician $technician)
    {
        $admin = auth()->user();
        if (!$admin || !in_array($admin->role, ['admin', 'project_manager'], true)) {
            abort(403);
        }

        // Surface a clear, actionable error before attempting the delete (#41).
        // Active jobs must be reassigned first — admins can't simply orphan
        // an in-flight job by removing the technician.
        $activeJobs = $technician->serviceRequests()
            ->whereIn('status', [
                ServiceRequest::STATUS_ASSIGNED,
                ServiceRequest::STATUS_IN_PROGRESS,
                ServiceRequest::STATUS_QUEUED,
                ServiceRequest::STATUS_AWAITING_TECH_AVAILABILITY,
            ])
            ->pluck('request_id')
            ->take(5);

        if ($activeJobs->isNotEmpty()) {
            $jobList = $activeJobs->implode(', ');
            return redirect()->route('admin.technicians')->with(
                'error',
                "Cannot delete technician — they still have active jobs ({$jobList}). Reassign these jobs first, then retry the delete."
            );
        }

        try {
            \DB::transaction(function () use ($technician) {
                $user = $technician->user;
                // Clean up child records that don't cascade automatically.
                $technician->documents()->delete();
                $technician->delete();
                if ($user) {
                    $user->delete();
                }
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('destroyTechnician failed', [
                'technician_id' => $technician->id,
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('admin.technicians')->with(
                'error',
                'Could not delete the technician: ' . $e->getMessage()
            );
        }

        return redirect()->route('admin.technicians')->with('success', 'Technician deleted successfully.');
    }

    /**
     * Stream a technician document through Laravel so the response is
     * properly auth-gated. Avoids the public storage symlink edge cases
     * (#40 — clients see "Forbidden" when the symlink, file permissions,
     * or web server config blocks direct /storage/ access).
     */
    public function showTechnicianDocument(\App\Models\TechnicianDocument $document)
    {
        $user = auth()->user();
        if (!$user) {
            abort(403);
        }

        // Admins and PMs can view any document. Technicians can view their
        // OWN documents — fixes the 403 that admin and technicians were
        // hitting on Railway when the symlinked /storage/ URL failed.
        $isStaff = in_array($user->role, ['admin', 'project_manager'], true);
        $isOwner = $user->role === 'technician'
            && $user->technician
            && (int) $document->technician_id === (int) $user->technician->id;

        if (!$isStaff && !$isOwner) {
            abort(403);
        }

        if (!$document->file_path) {
            abort(404, 'Document record has no file path.');
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (!$disk->exists($document->file_path)) {
            abort(404, 'Document file not found. It may have been deleted or not yet uploaded.');
        }

        // Stream the file directly via PHP using an absolute path so we
        // never depend on the public/storage symlink (which 403s on Railway).
        $absolutePath = $disk->path($document->file_path);
        $displayName  = $document->file_name ?? basename($document->file_path);
        $mime         = $disk->mimeType($document->file_path) ?: 'application/octet-stream';

        return response()->file($absolutePath, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $displayName) . '"',
            'Cache-Control'       => 'private, max-age=3600',
        ]);
    }

    /**
     * Upload a document for a technician.
     */
    public function uploadTechnicianDocument(Request $request, Technician $technician)
    {
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

        // Replace existing document of the same type if it exists
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
     * Verify/approve a technician document.
     */
    public function verifyTechnicianDocument(Request $request, \App\Models\TechnicianDocument $document)
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
        ]);

        if ($request->action === 'approve') {
            $document->update([
                'verified' => true,
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);
        } else {
            $document->update([
                'verified' => false,
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);
        }

        return back()->with('success', 'Document ' . $request->action . 'd successfully.');
    }

    public function assignTechnician(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'agreed_compensation' => 'required|numeric|min:0',
            'compensation_notes' => 'nullable|string|max:1000',
            // #23 / #26 — reason captured at reassignment time, used in
            // the audit log and the client notification email.
            'reassignment_reason' => 'nullable|string|max:500',
            // #12 — commencement_at allows admin to set when the technician
            // is expected to start. Soft warning surfaces if it exceeds
            // the priority window (NOT a hard block per client preference).
            'commencement_at' => 'nullable|date',
            // Files admin attaches when assigning — drawings, BOQ, photos.
            // These are stored on the JobAssignment and attached to the
            // technician's email.
            'assignment_files'   => 'nullable|array|max:10',
            'assignment_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,heic,heif,webp|max:20480',
            // On-site duration estimate shown to the client so they know how
            // long to plan around the technician's visit. Capped at 8 hours
            // (480 min) — anything longer is really a multi-day job and
            // belongs in expected_duration_days instead.
            'contact_time_minutes' => 'nullable|integer|min:5|max:480',
        ]);

        // Block direct assignment when sub-tasks exist
        if ($serviceRequest->has_sub_tasks) {
            return redirect()->route('admin.jobs.show', $serviceRequest)->with('error', 'This service request has sub-tasks. Please assign technicians to individual sub-tasks.');
        }

        // Check if RFQ has been approved (if RFQ workflow is enabled)
        if ($serviceRequest->rfq_status && $serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return redirect()->route('admin.jobs.show', $serviceRequest)->with('error', 'Cannot assign technician until RFQ is approved by client.');
        }

        // #24 — block re-picking the same technician on reassignment.
        if ($serviceRequest->technician_id && (int) $request->technician_id === (int) $serviceRequest->technician_id) {
            return redirect()->route('admin.jobs.show', $serviceRequest)->with('error', 'That technician is already assigned to this job.');
        }

        $technician = Technician::findOrFail($request->technician_id);
        $currentTechnicianId = $serviceRequest->technician_id;
        $previousTechnician = $currentTechnicianId
            ? Technician::with('user')->find($currentTechnicianId)
            : null;
        $existingAssignment = $this->findActivePrimaryAssignment($serviceRequest, $currentTechnicianId);
        $isReassignment = $previousTechnician !== null;

        $this->ensureLaborBudgetCapacity(
            $serviceRequest,
            (float) $request->agreed_compensation,
            excludeAssignmentId: $existingAssignment?->id
        );
        $this->ensureTechnicianMilestoneCoverage(
            $serviceRequest,
            (int) $technician->id,
            (float) $request->agreed_compensation
        );

        // #12 — Build commencement + target_completion. If admin didn't pick
        // a commencement date, default to now. target_completion uses the
        // quoted expected_duration_days if available.
        $commencementAt = $request->filled('commencement_at')
            ? \Carbon\Carbon::parse($request->commencement_at)
            : now();
        $targetCompletionAt = $serviceRequest->expected_duration_days
            ? (clone $commencementAt)->addDays($serviceRequest->expected_duration_days)
            : null;

        // Update service request
        $serviceRequest->update([
            'technician_id' => $technician->id,
            'status' => 'assigned',
            'assigned_at' => now(),
            'commencement_at' => $commencementAt,
            'target_completion_at' => $targetCompletionAt,
            'contact_time_minutes' => $request->filled('contact_time_minutes')
                ? (int) $request->contact_time_minutes
                : $serviceRequest->contact_time_minutes,
        ]);

        // #12 — Soft warning if the commencement date exceeds the priority window.
        $priorityWindow = $serviceRequest->fresh()->priority_window_ends_at;
        $warning = null;
        if ($priorityWindow && $commencementAt->gt($priorityWindow)) {
            $warning = sprintf(
                'Heads up: technician start (%s) is past the %s-urgency window that ended %s. The client has been notified.',
                $commencementAt->format('d M Y H:i'),
                $serviceRequest->urgency ?? 'medium',
                $priorityWindow->diffForHumans()
            );
        }

        $reassignmentReason = $request->input('reassignment_reason');
        $attachments = $this->storeAssignmentAttachments($request, $serviceRequest);
        $this->syncPrimaryAssignment(
            $serviceRequest,
            currentTechnicianId: $currentTechnicianId,
            technician: $technician,
            agreedCompensation: (float) $request->agreed_compensation,
            compensationNotes: $request->compensation_notes,
            reassignmentReason: $reassignmentReason ?: 'Admin reassigned technician from job details.',
            attachments: $attachments,
        );

        // Update technician availability if they become busy
        if ($technician->availability === 'available') {
            $technician->update(['availability' => 'busy']);
        }

        // Send email notification to technician
        if ($technician->user && $technician->user->email) {
            Mail::to($technician->user->email)->send(new JobAssigned($serviceRequest));
        }

        // Send email notification to client. Use the dedicated reassignment
        // template when the job already had a technician (#23).
        if ($serviceRequest->user && $serviceRequest->user->email) {
            try {
                if ($isReassignment) {
                    Mail::to($serviceRequest->user->email)->send(new \App\Mail\JobReassigned(
                        $serviceRequest,
                        $previousTechnician,
                        $technician,
                        $reassignmentReason
                    ));
                } else {
                    Mail::to($serviceRequest->user->email)->send(new TechnicianAssigned($serviceRequest, $technician));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Client reassignment email failed', [
                    'service_request_id' => $serviceRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $redirect = redirect()->route('admin.jobs.show', $serviceRequest)->with('success',
            $isReassignment
                ? 'Technician reassigned. Client has been notified.'
                : 'Technician assigned successfully!'
        );
        if ($warning) {
            $redirect = $redirect->with('warning', $warning);
        }
        return $redirect;
    }

    public function assignLeadTechnician(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'agreed_compensation' => 'required|numeric|min:0',
            'compensation_notes' => 'nullable|string|max:1000',
        ]);

        $technician = Technician::findOrFail($request->technician_id);
        $currentLeadTechnicianId = $serviceRequest->lead_technician_id;
        $existingAssignment = $this->findActivePrimaryAssignment($serviceRequest, $currentLeadTechnicianId);

        $this->ensureLaborBudgetCapacity(
            $serviceRequest,
            (float) $request->agreed_compensation,
            excludeAssignmentId: $existingAssignment?->id
        );
        $this->ensureTechnicianMilestoneCoverage(
            $serviceRequest,
            (int) $technician->id,
            (float) $request->agreed_compensation
        );

        // technician_id is the job's primary assignee and is read all over
        // the app — the client's "Assigned Technician", job completion,
        // ratings. Writing only lead_technician_id here left it NULL for the
        // whole life of a project staffed lead-first, because
        // assignSubTaskTechnician only backfills it when there is no lead
        // yet. assignSubTaskTechnician already sets both together; this
        // keeps the two staffing routes consistent.
        $serviceRequest->update([
            'lead_technician_id' => $technician->id,
            'technician_id' => $technician->id,
        ]);

        // If the job isn't assigned yet, also set it as assigned
        if (in_array($serviceRequest->status, ['pending', 'ready_for_assignment'])) {
            $serviceRequest->update([
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);
        }

        if ($technician->availability === 'available') {
            $technician->update(['availability' => 'busy']);
        }

        $this->syncPrimaryAssignment(
            $serviceRequest,
            currentTechnicianId: $currentLeadTechnicianId,
            technician: $technician,
            agreedCompensation: (float) $request->agreed_compensation,
            compensationNotes: $request->compensation_notes,
            reassignmentReason: 'Admin changed the lead technician from job details.'
        );

        if ($technician->user && $technician->user->email) {
            Mail::to($technician->user->email)->send(new JobAssigned($serviceRequest));
        }

        return redirect()->route('admin.jobs.show', $serviceRequest)->with('success', 'Lead technician assigned successfully!');
    }

    /**
     * Update a technician's agreed compensation on an existing job
     * assignment without going through the full reassignment flow (#22).
     * Useful when a fee needs adjustment mid-job (e.g. scope change or
     * negotiation) but the same technician stays on the work.
     */
    public function updateAssignmentCompensation(Request $request, ServiceRequest $serviceRequest)
    {
        $admin = auth()->user();
        if (!$admin || !in_array($admin->role, ['admin', 'project_manager'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'agreed_compensation' => 'required|numeric|min:0',
            'compensation_notes' => 'nullable|string|max:1000',
        ]);

        $assignment = JobAssignment::where('service_request_id', $serviceRequest->id)
            ->where('technician_id', $data['technician_id'])
            ->whereNotIn('status', [JobAssignment::STATUS_DECLINED])
            ->orderByDesc('id')
            ->first();

        if (!$assignment) {
            return redirect()->back()->with('error', 'No active assignment found for that technician on this job.');
        }

        // Re-run the labor budget guard so a fee bump doesn't blow the
        // approved labor envelope without admin awareness.
        try {
            $this->ensureLaborBudgetCapacity(
                $serviceRequest,
                (float) $data['agreed_compensation'],
                excludeAssignmentId: $assignment->id
            );
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $oldAmount = (float) ($assignment->agreed_compensation ?? 0);
        $newAmount = (float) $data['agreed_compensation'];

        $assignment->update([
            'agreed_compensation' => $newAmount,
            'compensation_notes' => $data['compensation_notes'] ?? $assignment->compensation_notes,
        ]);

        \App\Models\AuditLog::log(
            \App\Models\AuditLog::ACTION_UPDATED,
            $assignment,
            ['agreed_compensation' => $oldAmount],
            [
                'agreed_compensation' => $newAmount,
                'reason' => $data['compensation_notes'] ?? null,
                'updated_by' => $admin->name,
            ]
        );

        return redirect()->back()->with('success',
            sprintf('Technician fee updated from KES %s to KES %s.',
                number_format($oldAmount, 2),
                number_format($newAmount, 2)
            )
        );
    }

    /**
     * Find the active (non-reassigned, non-declined) direct/lead assignment
     * for this SR's primary slot — regardless of which technician it points
     * at. Mirrors the correct slot-scoped semantics of
     * findActiveSubTaskAssignment. Was previously tech-scoped and had an
     * early return when technicianId was null, which meant a fresh assign
     * against a slot with an existing active row silently created a second
     * one → the ghost-row cascade that overspent labour budgets.
     *
     * The technicianId parameter is retained for backward compatibility
     * with existing callers but is deliberately ignored so we ALWAYS find
     * any active row on the slot, not just one for a specific tech. If
     * more than one active row exists (should never happen post-cleanup,
     * DB constraint coming in Layer 3), we log a warning and use the
     * latest — better than silently ignoring the older ones.
     */
    /**
     * Canonical budget summary for a service request. Used by BOTH
     * JobDetails (Finance section) and the Payments page (Budget Snapshot)
     * so the two surfaces can NEVER show different numbers for the same
     * job — a bug we hit twice already (REQ-W78RAR silent-cap + this
     * Payments page 0-spent mismatch, both caused by duplicated inline
     * calculations that drifted from the source of truth).
     *
     * Returns null if the SR has no budget row yet.
     *
     * The labour block computes five figures:
     *   - budgeted   : the labor_budget on service_request_budgets
     *   - committed  : sum of agreed fees on active JobAssignments +
     *                  sub-tasks (via getLaborAllocationSummary — same
     *                  helper the assignment cap uses)
     *   - actual     : direct technician_payments (labor+completed) +
     *                  payment-sheet entries (APPROVED uses
     *                  current_period_payable; PAID uses paid_amount)
     *   - outstanding: max(0, committed − actual) — what's still owed
     *                  to techs. This is the number ops actually looks
     *                  up when deciding "how much more can I pay this
     *                  tech"; missing it caused the REQ-W78RAR ticket.
     *   - remaining  : budgeted − committed — headroom for a NEW
     *                  commitment. Distinct from outstanding.
     *
     * Materials + other are simpler: no committed/outstanding split,
     * just budgeted − actual = remaining.
     */
    protected function buildBudgetSummary(?ServiceRequest $sr): ?array
    {
        if (!$sr || !$sr->budget) return null;

        // Labour actual — direct payments plus sheet entries. Same rule
        // as TechnicianPaymentService::getTotalLabourPaid so cap checks,
        // card display, and per-tech breakdown all reconcile.
        $laborSpentDirect = (float) ($sr->technicianPayments ?? collect())
            ->where('category', 'labor')
            ->where('status', 'completed')
            ->sum('amount');

        $sheetEntries = TechnicianPaymentEntry::where('service_request_id', $sr->id)
            ->whereIn('status', [
                TechnicianPaymentEntry::STATUS_APPROVED,
                TechnicianPaymentEntry::STATUS_PAID,
            ])
            ->get(['status', 'current_period_payable', 'paid_amount']);
        $laborSpentSheets = (float) $sheetEntries->sum(function ($e) {
            if ($e->status === TechnicianPaymentEntry::STATUS_PAID) {
                return (float) ($e->paid_amount ?? $e->current_period_payable);
            }
            return (float) $e->current_period_payable;
        });
        $laborSpent = $laborSpentDirect + $laborSpentSheets;

        $materialsSpentPayments = (float) ($sr->technicianPayments ?? collect())
            ->where('category', 'materials')->where('status', 'completed')->sum('amount');
        $materialsSpentExpenditures = (float) ($sr->expenditures ?? collect())
            ->where('category', 'materials')->sum('amount');
        $otherSpentPayments = (float) ($sr->technicianPayments ?? collect())
            ->where('category', 'other')->where('status', 'completed')->sum('amount');
        $otherSpentExpenditures = (float) ($sr->expenditures ?? collect())
            ->where('category', 'other')->sum('amount');

        $laborAllocation = $this->getLaborAllocationSummary($sr);
        $laborCommitted = (float) $laborAllocation['allocated'];
        $laborOutstanding = max(0, $laborCommitted - $laborSpent);

        $materialsSpent = $materialsSpentPayments + $materialsSpentExpenditures;
        $otherSpent = $otherSpentPayments + $otherSpentExpenditures;
        $totalSpent = $laborSpent + $materialsSpent + $otherSpent;

        return [
            'labor' => [
                'budgeted'    => (float) $sr->budget->labor_budget,
                'committed'   => $laborCommitted,
                'actual'      => $laborSpent,
                'outstanding' => $laborOutstanding,
                'remaining'   => (float) $sr->budget->labor_budget - $laborCommitted,
            ],
            'materials' => [
                'budgeted'  => (float) $sr->budget->materials_budget,
                'actual'    => $materialsSpent,
                'remaining' => (float) $sr->budget->materials_budget - $materialsSpent,
            ],
            'other' => [
                'budgeted'  => (float) $sr->budget->other_budget,
                'actual'    => $otherSpent,
                'remaining' => (float) $sr->budget->other_budget - $otherSpent,
            ],
            'total' => [
                'budgeted'  => (float) $sr->budget->total_budget,
                'actual'    => $totalSpent,
                'remaining' => (float) $sr->budget->total_budget - $totalSpent,
            ],
        ];
    }

    protected function findActivePrimaryAssignment(ServiceRequest $serviceRequest, ?int $technicianId = null): ?JobAssignment
    {
        $query = $serviceRequest->jobAssignments()
            ->whereNull('service_sub_task_id')
            ->whereIn('status', [
                JobAssignment::STATUS_PENDING,
                JobAssignment::STATUS_ACCEPTED,
                JobAssignment::STATUS_COMPLETED,
            ]);

        $activeAssignments = $query->orderByDesc('id')->get();

        // Belt-and-braces diagnostic: if the slot has multiple active rows,
        // something snuck in that our sync methods should have caught. Log
        // it so we can spot recurrences before Layer 3 (DB uniqueness) is
        // in place, then return the latest so downstream logic still works.
        if ($activeAssignments->count() > 1) {
            \Illuminate\Support\Facades\Log::warning('Multiple active primary JobAssignments detected on slot', [
                'service_request_id' => $serviceRequest->id,
                'request_id'         => $serviceRequest->request_id,
                'assignment_ids'     => $activeAssignments->pluck('id')->all(),
            ]);
        }

        return $activeAssignments->first();
    }

    protected function findActiveSubTaskAssignment(ServiceSubTask $serviceSubTask): ?JobAssignment
    {
        return JobAssignment::where('service_sub_task_id', $serviceSubTask->id)
            ->whereIn('status', [
                JobAssignment::STATUS_PENDING,
                JobAssignment::STATUS_ACCEPTED,
                JobAssignment::STATUS_COMPLETED,
            ])
            ->latest('id')
            ->first();
    }

    protected function getLaborAllocationSummary(
        ServiceRequest $serviceRequest,
        ?int $excludeAssignmentId = null,
        ?int $excludeSubTaskId = null
    ): array {
        $budgeted = (float) ($serviceRequest->budget?->labor_budget ?? 0);

        $directAllocation = (float) $serviceRequest->jobAssignments()
            ->whereNull('service_sub_task_id')
            ->whereIn('status', [
                JobAssignment::STATUS_PENDING,
                JobAssignment::STATUS_ACCEPTED,
                JobAssignment::STATUS_COMPLETED,
            ])
            ->when($excludeAssignmentId, fn ($query) => $query->where('id', '!=', $excludeAssignmentId))
            ->sum('agreed_compensation');

        $subTaskAllocation = (float) $serviceRequest->subTasks()
            ->whereNotNull('technician_id')
            ->when($excludeSubTaskId, fn ($query) => $query->where('id', '!=', $excludeSubTaskId))
            ->sum('agreed_compensation');

        $allocated = $directAllocation + $subTaskAllocation;

        return [
            'budgeted' => $budgeted,
            'allocated' => $allocated,
            'remaining' => $budgeted - $allocated,
        ];
    }

    protected function ensureLaborBudgetCapacity(
        ServiceRequest $serviceRequest,
        float $agreedCompensation,
        ?int $excludeAssignmentId = null,
        ?int $excludeSubTaskId = null
    ): void {
        if (!$serviceRequest->budget) {
            throw ValidationException::withMessages([
                'agreed_compensation' => 'Set the labor budget before assigning technician dues.',
            ]);
        }

        $allocation = $this->getLaborAllocationSummary($serviceRequest, $excludeAssignmentId, $excludeSubTaskId);

        if ($allocation['budgeted'] <= 0) {
            throw ValidationException::withMessages([
                'agreed_compensation' => 'The labor budget must be greater than zero before assigning technician dues.',
            ]);
        }

        if ($agreedCompensation > ($allocation['remaining'] + 0.0001)) {
            // Item 3b — verbose breakdown so ops sees WHY they're capped
            // and where the ceiling came from (was previously just "up to
            // KSH X" with no context). Names each existing commitment so
            // stale/orphan assignments are visible instead of mysterious.
            throw ValidationException::withMessages([
                'agreed_compensation' => sprintf(
                    'This assignment (KSH %s) exceeds the remaining labor budget. Labor budget is KSH %s; already committed to other technicians on this job: KSH %s; remaining for a new commitment: KSH %s. Either lower this fee, raise the labor budget, or free up capacity by removing/reducing an existing assignment.',
                    number_format($agreedCompensation, 2),
                    number_format($allocation['budgeted'], 2),
                    number_format($allocation['allocated'], 2),
                    number_format(max($allocation['remaining'], 0), 2),
                ),
            ]);
        }
    }

    protected function ensureTechnicianMilestoneCoverage(
        ServiceRequest $serviceRequest,
        int $technicianId,
        float $agreedCompensation,
        ?int $excludeMilestoneId = null
    ): void {
        $allocatedAcrossMilestones = (float) PaymentMilestoneAllocation::query()
            ->where('technician_id', $technicianId)
            ->whereHas('milestone', function ($query) use ($serviceRequest, $excludeMilestoneId) {
                $query->where('service_request_id', $serviceRequest->id)
                    ->when($excludeMilestoneId, fn ($inner) => $inner->where('id', '!=', $excludeMilestoneId));
            })
            ->sum('allocated_amount');

        if ($allocatedAcrossMilestones > ($agreedCompensation + 0.0001)) {
            throw ValidationException::withMessages([
                'agreed_compensation' => 'This technician already has KSH '
                    . number_format($allocatedAcrossMilestones, 2)
                    . ' reserved across milestones on this job. Increase the agreed dues or reduce milestone allocations first.',
            ]);
        }
    }

    protected function getMilestoneLaborReleaseSummary(ServiceRequest $serviceRequest, ?int $excludeMilestoneId = null): array
    {
        $budgeted = (float) ($serviceRequest->budget?->labor_budget ?? 0);
        $released = (float) $serviceRequest->milestones()
            ->when($excludeMilestoneId, fn ($query) => $query->where('id', '!=', $excludeMilestoneId))
            ->sum('labor_release_amount');

        return [
            'budgeted' => $budgeted,
            'released' => $released,
            'remaining' => $budgeted - $released,
        ];
    }

    protected function getTechnicianAgreedCompensation(ServiceRequest $serviceRequest, int $technicianId): float
    {
        $agreedFromAssignments = (float) $serviceRequest->jobAssignments()
            ->where('technician_id', $technicianId)
            ->whereIn('status', [
                JobAssignment::STATUS_PENDING,
                JobAssignment::STATUS_ACCEPTED,
                JobAssignment::STATUS_COMPLETED,
            ])
            ->sum('agreed_compensation');

        if ($agreedFromAssignments > 0) {
            return $agreedFromAssignments;
        }

        return (float) $serviceRequest->subTasks()
            ->where('technician_id', $technicianId)
            ->sum('agreed_compensation');
    }

    protected function getAssignedTechnicianIds(ServiceRequest $serviceRequest): array
    {
        return collect()
            ->merge($serviceRequest->jobAssignments()
                ->whereIn('status', [
                    JobAssignment::STATUS_PENDING,
                    JobAssignment::STATUS_ACCEPTED,
                    JobAssignment::STATUS_COMPLETED,
                ])
                ->pluck('technician_id'))
            ->merge($serviceRequest->subTasks()->whereNotNull('technician_id')->pluck('technician_id'))
            ->merge([$serviceRequest->technician_id, $serviceRequest->lead_technician_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function validateMilestoneConfiguration(
        ServiceRequest $serviceRequest,
        float $laborReleaseAmount,
        array $allocations,
        ?int $excludeMilestoneId = null
    ): void {
        if (! $serviceRequest->budget) {
            throw ValidationException::withMessages([
                'labor_release_amount' => 'Set the labor budget before planning milestone technician releases.',
            ]);
        }

        $releaseSummary = $this->getMilestoneLaborReleaseSummary($serviceRequest, $excludeMilestoneId);
        if ($releaseSummary['budgeted'] <= 0) {
            throw ValidationException::withMessages([
                'labor_release_amount' => 'The labor budget must be greater than zero before creating milestone labor releases.',
            ]);
        }

        if ($laborReleaseAmount > ($releaseSummary['remaining'] + 0.0001)) {
            throw ValidationException::withMessages([
                'labor_release_amount' => 'This milestone exceeds the remaining labor budget release capacity. Up to KSH '
                    . number_format(max($releaseSummary['remaining'], 0), 2)
                    . ' is still available across milestones on this job.',
            ]);
        }

        $assignedTechnicianIds = $this->getAssignedTechnicianIds($serviceRequest);
        $seenTechnicians = [];
        $allocatedTotal = 0.0;

        foreach ($allocations as $index => $allocation) {
            $technicianId = (int) ($allocation['technician_id'] ?? 0);
            $allocatedAmount = (float) ($allocation['allocated_amount'] ?? 0);

            if (! in_array($technicianId, $assignedTechnicianIds, true)) {
                throw ValidationException::withMessages([
                    "allocations.$index.technician_id" => 'Only technicians assigned to this job can be allocated on a milestone.',
                ]);
            }

            if (in_array($technicianId, $seenTechnicians, true)) {
                throw ValidationException::withMessages([
                    "allocations.$index.technician_id" => 'Each technician can only appear once per milestone.',
                ]);
            }

            $seenTechnicians[] = $technicianId;
            $allocatedTotal += $allocatedAmount;

            $agreedCompensation = $this->getTechnicianAgreedCompensation($serviceRequest, $technicianId);
            $existingOtherMilestoneAllocations = (float) PaymentMilestoneAllocation::query()
                ->where('technician_id', $technicianId)
                ->whereHas('milestone', function ($query) use ($serviceRequest, $excludeMilestoneId) {
                    $query->where('service_request_id', $serviceRequest->id)
                        ->when($excludeMilestoneId, fn ($inner) => $inner->where('id', '!=', $excludeMilestoneId));
                })
                ->sum('allocated_amount');

            if ($agreedCompensation <= 0) {
                throw ValidationException::withMessages([
                    "allocations.$index.allocated_amount" => 'Set agreed technician dues before assigning milestone releases.',
                ]);
            }

            if (($existingOtherMilestoneAllocations + $allocatedAmount) > ($agreedCompensation + 0.0001)) {
                throw ValidationException::withMessages([
                    "allocations.$index.allocated_amount" => 'This technician only has KSH '
                        . number_format(max($agreedCompensation - $existingOtherMilestoneAllocations, 0), 2)
                        . ' of unallocated agreed dues left for milestone planning on this job.',
                ]);
            }
        }

        if ($allocatedTotal > ($laborReleaseAmount + 0.0001)) {
            throw ValidationException::withMessages([
                'allocations' => 'Milestone technician allocations cannot exceed the labor release amount for this milestone.',
            ]);
        }
    }

    protected function syncMilestoneAllocations(PaymentMilestone $milestone, array $allocations): void
    {
        $milestone->allocations()->delete();

        foreach ($allocations as $allocation) {
            $milestone->allocations()->create([
                'technician_id' => (int) $allocation['technician_id'],
                'allocated_amount' => (float) $allocation['allocated_amount'],
                'notes' => $allocation['notes'] ?? null,
            ]);
        }
    }

    protected function syncPrimaryAssignment(
        ServiceRequest $serviceRequest,
        ?int $currentTechnicianId,
        Technician $technician,
        float $agreedCompensation,
        ?string $compensationNotes,
        string $reassignmentReason,
        array $attachments = []
    ): JobAssignment {
        $existingAssignment = $this->findActivePrimaryAssignment($serviceRequest, $currentTechnicianId);

        if ($existingAssignment && (int) $existingAssignment->technician_id === (int) $technician->id) {
            $update = [
                'agreed_compensation' => $agreedCompensation,
                'compensation_notes' => $compensationNotes,
                'assigned_by' => auth()->id(),
                'status' => JobAssignment::STATUS_PENDING,
            ];
            if (!empty($attachments)) {
                // Merge with any existing attachments rather than overwrite
                $update['attachments'] = array_merge((array) ($existingAssignment->attachments ?? []), $attachments);
            }
            $existingAssignment->update($update);

            return $existingAssignment->fresh();
        }

        if ($existingAssignment) {
            $existingAssignment->update([
                'status' => JobAssignment::STATUS_REASSIGNED,
                'reassignment_reason' => $reassignmentReason,
                'actual_end' => now(),
            ]);
        }

        return JobAssignment::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technician->id,
            'assigned_by' => auth()->id(),
            'agreed_compensation' => $agreedCompensation,
            'compensation_notes' => $compensationNotes,
            'attachments' => !empty($attachments) ? $attachments : null,
            'status' => JobAssignment::STATUS_PENDING,
            'reassigned_from' => $existingAssignment?->technician_id,
        ]);
    }

    /**
     * Persist uploaded assignment files to the public disk.
     */
    protected function storeAssignmentAttachments(Request $request, ServiceRequest $serviceRequest): array
    {
        if (!$request->hasFile('assignment_files')) return [];

        $stored = [];
        foreach ($request->file('assignment_files') as $file) {
            $path = $file->store('job-assignments/' . $serviceRequest->request_id, 'public');
            $stored[] = [
                'path'      => $path,
                'name'      => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
            ];
        }
        return $stored;
    }

    protected function syncSubTaskAssignment(
        ServiceSubTask $serviceSubTask,
        Technician $technician,
        float $agreedCompensation,
        ?string $compensationNotes
    ): JobAssignment {
        $existingAssignment = $this->findActiveSubTaskAssignment($serviceSubTask);

        if ($existingAssignment && (int) $existingAssignment->technician_id === (int) $technician->id) {
            $existingAssignment->update([
                'agreed_compensation' => $agreedCompensation,
                'compensation_notes' => $compensationNotes,
                'assigned_by' => auth()->id(),
                'status' => JobAssignment::STATUS_PENDING,
            ]);

            return $existingAssignment->fresh();
        }

        if ($existingAssignment) {
            $existingAssignment->update([
                'status' => JobAssignment::STATUS_REASSIGNED,
                'reassignment_reason' => 'Admin reassigned the technician for this sub-task.',
                'actual_end' => now(),
            ]);
        }

        return JobAssignment::create([
            'service_request_id' => $serviceSubTask->service_request_id,
            'service_sub_task_id' => $serviceSubTask->id,
            'technician_id' => $technician->id,
            'assigned_by' => auth()->id(),
            'agreed_compensation' => $agreedCompensation,
            'compensation_notes' => $compensationNotes,
            'status' => JobAssignment::STATUS_PENDING,
            'reassigned_from' => $existingAssignment?->technician_id,
        ]);
    }

    public function storeMilestone(Request $request, ServiceRequest $serviceRequest)
    {
        $validated = $request->validate([
            'progress_step'          => 'required|integer|min:1|max:100',
            'labor_release_amount'   => 'required|numeric|min:0',
            'notes'                  => 'nullable|string|max:500',
            'allocations'            => 'nullable|array',
            'allocations.*.technician_id'    => 'required|exists:technicians,id',
            'allocations.*.allocated_amount' => 'required|numeric|min:0',
            'allocations.*.notes'            => 'nullable|string|max:500',
        ]);

        $allocations = collect($validated['allocations'] ?? [])
            ->filter(fn ($a) => (float) ($a['allocated_amount'] ?? 0) > 0)
            ->values()
            ->all();

        $this->validateMilestoneConfiguration(
            $serviceRequest,
            (float) $validated['labor_release_amount'],
            $allocations
        );

        DB::transaction(function () use ($serviceRequest, $validated, $allocations) {
            $milestone = $serviceRequest->milestones()->create([
                'progress_step'        => $validated['progress_step'],
                'labor_release_amount' => $validated['labor_release_amount'],
                'notes'                => $validated['notes'] ?? null,
                'status'               => 'pending',
            ]);

            $this->syncMilestoneAllocations($milestone, $allocations);
        });

        return back()->with('success', 'Milestone added successfully.');
    }

    public function updateMilestone(Request $request, PaymentMilestone $milestone)
    {
        $validated = $request->validate([
            'progress_step'          => 'required|integer|min:1|max:100',
            'labor_release_amount'   => 'required|numeric|min:0',
            'notes'                  => 'nullable|string|max:500',
            'status'                 => 'nullable|in:pending,reached,paid',
            'allocations'            => 'nullable|array',
            'allocations.*.technician_id'    => 'required|exists:technicians,id',
            'allocations.*.allocated_amount' => 'required|numeric|min:0',
            'allocations.*.notes'            => 'nullable|string|max:500',
        ]);

        $serviceRequest = $milestone->serviceRequest()->with(['budget', 'jobAssignments', 'subTasks'])->firstOrFail();
        $allocations = collect($validated['allocations'] ?? [])
            ->filter(fn ($a) => (float) ($a['allocated_amount'] ?? 0) > 0)
            ->values()
            ->all();

        $this->validateMilestoneConfiguration(
            $serviceRequest,
            (float) $validated['labor_release_amount'],
            $allocations,
            excludeMilestoneId: $milestone->id
        );

        DB::transaction(function () use ($milestone, $validated, $allocations) {
            $milestone->update([
                'progress_step'        => $validated['progress_step'],
                'labor_release_amount' => $validated['labor_release_amount'],
                'notes'                => $validated['notes'] ?? null,
                'status'               => $validated['status'] ?? $milestone->status,
            ]);

            $this->syncMilestoneAllocations($milestone, $allocations);
        });

        return back()->with('success', 'Milestone updated.');
    }

    public function destroyMilestone(PaymentMilestone $milestone)
    {
        $milestone->delete();

        return back()->with('success', 'Milestone deleted.');
    }

    public function tools()
    {
        $tools = Tool::with(['technician.user', 'serviceRequest'])
            ->orderBy('created_at', 'desc')
            ->get();

        $technicians = Technician::with('user')
            ->where('availability', 'available')
            ->orderBy('user_id', 'asc')
            ->get();

        $activeJobs = ServiceRequest::with('user')
            ->whereIn('status', ['assigned', 'in_progress'])
            ->orderBy('created_at', 'desc')
            ->get();

        $toolRequests = \App\Models\ToolRequestItem::pending()
            ->with([
                'toolRequest.technician.user:id,name,email',
                'toolRequest.serviceRequest:id,request_id,job_reference',
                'tool:id,name,serial_number,status,condition',
            ])
            ->latest()
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'tool_request_id' => $item->tool_request_id,
                    'tool_id' => $item->tool_id,
                    'tool_name_requested' => $item->tool_name_requested,
                    'quantity' => $item->quantity,
                    'status' => $item->status,
                    'created_at' => $item->created_at,
                    'tool' => $item->tool,
                    'technician' => $item->toolRequest->technician ?? null,
                    'service_request' => $item->toolRequest->serviceRequest ?? null,
                    'urgency' => $item->toolRequest->urgency ?? 'normal',
                    'notes' => $item->toolRequest->notes ?? null,
                ];
            });

        return Inertia::render('Admin/Tools', [
            'tools' => $tools,
            'technicians' => $technicians,
            'activeJobs' => $activeJobs,
            'toolRequests' => $toolRequests,
        ]);
    }

    /**
     * Approve a pending tool request. If a specific tool was requested,
     * issue it to the technician immediately. If it was a freeform
     * request the admin still has to issue an actual tool manually after
     * approving — the request is just acknowledged so the technician
     * knows it's being acted on.
     */
    public function approveToolRequestItem(Request $request, \App\Models\ToolRequestItem $toolRequestItem)
    {
        if (!$toolRequestItem->isPending()) {
            return back()->withErrors(['toolRequest' => 'Only pending items can be approved.']);
        }

        $request->validate([
            'tool_id' => 'nullable|integer|exists:tools,id',
            'expected_return_date' => 'nullable|date|after:today',
            'decision_notes' => 'nullable|string|max:500',
        ]);

        $toolToIssue = null;
        if ($request->filled('tool_id')) {
            $toolToIssue = Tool::find($request->tool_id);
        } elseif ($toolRequestItem->tool_id) {
            $toolToIssue = Tool::find($toolRequestItem->tool_id);
        }

        if ($toolToIssue) {
            if ($toolToIssue->status !== Tool::STATUS_AVAILABLE) {
                return back()->withErrors([
                    'tool_id' => 'That tool is no longer available. Pick another or reject this request.',
                ]);
            }
            // #17 — also block tools whose condition is damaged or needs repair.
            if (in_array($toolToIssue->condition, ['damaged', 'needs_repair'], true)) {
                return back()->withErrors([
                    'tool_id' => "That tool is currently marked '{$toolToIssue->condition}'. Restore its condition before issuing.",
                ]);
            }
            $serviceRequest = $toolRequestItem->toolRequest->service_request_id
                ? ServiceRequest::find($toolRequestItem->toolRequest->service_request_id)
                : null;
            $toolToIssue->assignTo(
                $toolRequestItem->toolRequest->technician,
                $serviceRequest,
                $request->expected_return_date,
                $toolRequestItem->toolRequest->notes
            );
        }

        $toolRequestItem->update([
            'status' => \App\Models\ToolRequest::STATUS_APPROVED,
            'tool_id' => $toolToIssue?->id ?? $toolRequestItem->tool_id,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
            'decision_notes' => $request->decision_notes,
        ]);
        
        // Optionally update the parent ToolRequest status based on its items
        $parentRequest = $toolRequestItem->toolRequest;
        if ($parentRequest && !$parentRequest->items()->where('status', \App\Models\ToolRequest::STATUS_PENDING)->exists()) {
            // All items processed, set parent to approved/rejected
            $hasApproved = $parentRequest->items()->where('status', \App\Models\ToolRequest::STATUS_APPROVED)->exists();
            $parentRequest->update([
                'status' => $hasApproved ? \App\Models\ToolRequest::STATUS_APPROVED : \App\Models\ToolRequest::STATUS_REJECTED
            ]);
        }

        return redirect()->route('admin.tools')->with('success',
            $toolToIssue
                ? "Item approved and {$toolToIssue->name} issued to {$toolRequestItem->toolRequest->technician->user->name}."
                : 'Item acknowledged. Remember to issue an actual tool when ready.'
        );
    }

    /**
     * Reject a pending tool request with a short reason.
     */
    public function rejectToolRequestItem(Request $request, \App\Models\ToolRequestItem $toolRequestItem)
    {
        if (!$toolRequestItem->isPending()) {
            return back()->withErrors(['toolRequest' => 'Only pending items can be rejected.']);
        }

        $request->validate([
            'decision_notes' => 'required|string|min:3|max:500',
        ]);

        $toolRequestItem->update([
            'status' => \App\Models\ToolRequest::STATUS_REJECTED,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
            'decision_notes' => $request->decision_notes,
        ]);

        // Optionally update the parent ToolRequest status based on its items
        $parentRequest = $toolRequestItem->toolRequest;
        if ($parentRequest && !$parentRequest->items()->where('status', \App\Models\ToolRequest::STATUS_PENDING)->exists()) {
            // All items processed, set parent to approved/rejected
            $hasApproved = $parentRequest->items()->where('status', \App\Models\ToolRequest::STATUS_APPROVED)->exists();
            $parentRequest->update([
                'status' => $hasApproved ? \App\Models\ToolRequest::STATUS_APPROVED : \App\Models\ToolRequest::STATUS_REJECTED
            ]);
        }

        return redirect()->route('admin.tools')->with('success', 'Tool item rejected.');
    }

    public function storeTool(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'serial_number' => 'nullable|string|max:255|unique:tools,serial_number',
            'category' => 'required|string|max:255',
            'condition' => 'required|in:new,good,fair,needs_repair,damaged',
            'description' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        Tool::create([
            'name' => $request->name,
            'serial_number' => $request->serial_number,
            'category' => $request->category,
            'condition' => $request->condition,
            'description' => $request->description,
            'notes' => $request->notes,
            'status' => Tool::STATUS_AVAILABLE
        ]);

        return redirect()->route('admin.tools')->with('success', 'Tool added to inventory successfully!');
    }

    public function updateTool(Request $request, Tool $tool)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'serial_number' => 'nullable|string|max:255|unique:tools,serial_number,' . $tool->id,
            'category' => 'required|string|max:255',
            'condition' => 'required|in:new,good,fair,needs_repair,damaged',
            'description' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        $tool->update([
            'name' => $request->name,
            'serial_number' => $request->serial_number,
            'category' => $request->category,
            'condition' => $request->condition,
            'description' => $request->description,
            'notes' => $request->notes
        ]);

        return redirect()->route('admin.tools')->with('success', 'Tool updated successfully!');
    }

    public function destroyTool(Tool $tool)
    {
        if ($tool->status === Tool::STATUS_ISSUED) {
            return redirect()->route('admin.tools')->with('error', 'Cannot delete a tool that is currently issued.');
        }

        $tool->delete();

        return redirect()->route('admin.tools')->with('success', 'Tool deleted successfully!');
    }

    public function assignTool(Request $request, Tool $tool)
    {
        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'service_request_id' => 'nullable|exists:service_requests,id',
            'expected_return_date' => 'nullable|date|after:today',
            'notes' => 'nullable|string'
        ]);

        // #17 — block damaged tools (and anything in maintenance) from
        // being issued. Tool must be marked back to a serviceable
        // condition before allocation.
        if ($tool->status === Tool::STATUS_DAMAGED || in_array($tool->condition, ['damaged', 'needs_repair'], true)) {
            return redirect()->route('admin.tools')->with('error',
                "Cannot issue '{$tool->name}' — it is currently marked as " . ($tool->status === Tool::STATUS_DAMAGED ? 'damaged' : $tool->condition) .
                '. Update its condition to good/fair first.'
            );
        }
        if ($tool->status !== Tool::STATUS_AVAILABLE) {
            return redirect()->route('admin.tools')->with('error',
                "Cannot issue '{$tool->name}' — current status is '{$tool->status}'. Only available tools can be issued."
            );
        }

        $technician = Technician::findOrFail($request->technician_id);
        $serviceRequest = $request->service_request_id ? ServiceRequest::findOrFail($request->service_request_id) : null;

        $tool->assignTo(
            $technician,
            $serviceRequest,
            $request->expected_return_date,
            $request->notes
        );

        return redirect()->route('admin.tools')->with('success', 'Tool issued successfully!');
    }

    public function returnTool(Request $request, Tool $tool)
    {
        $request->validate([
            'condition' => 'nullable|in:new,good,fair,needs_repair,damaged',
            'notes' => 'nullable|string'
        ]);

        $tool->returnTool($request->condition, $request->notes);

        return redirect()->route('admin.tools')->with('success', 'Tool returned to inventory!');
    }

    public function payments(Request $request)
    {
        $serviceRequestId = $request->input('service_request_id');
        $technicianId = $request->input('technician_id');

        // #43 — parse the date filter sent from the Admin Payments page.
        // Apply it against the "money moved" column on each table so the
        // filter actually narrows results when the user changes the
        // dates.
        $fromInput = $request->input('from') ?: $request->input('date_from');
        $toInput   = $request->input('to')   ?: $request->input('date_to');
        $from = $fromInput ? \Carbon\Carbon::parse($fromInput)->startOfDay() : null;
        $to   = $toInput   ? \Carbon\Carbon::parse($toInput)->endOfDay()   : null;

        // Helper: filter a query to only service requests involving the given technician
        $techSrFilter = function ($q) use ($technicianId) {
            $q->where('technician_id', $technicianId)
              ->orWhere('lead_technician_id', $technicianId)
              ->orWhereHas('subTasks', function ($sq) use ($technicianId) {
                  $sq->where('technician_id', $technicianId);
              });
        };

        // Client payments
        $paymentsQuery = Payment::with(['serviceRequest', 'user']);
        if ($serviceRequestId) {
            $paymentsQuery->where('service_request_id', $serviceRequestId);
        }
        if ($technicianId && !$serviceRequestId) {
            $paymentsQuery->whereHas('serviceRequest', $techSrFilter);
        }
        if ($from) { $paymentsQuery->where(function ($q) use ($from) { $q->where('paid_at', '>=', $from)->orWhere('created_at', '>=', $from); }); }
        if ($to)   { $paymentsQuery->where(function ($q) use ($to)   { $q->where('paid_at', '<=', $to)->orWhere('created_at', '<=', $to); }); }
        $payments = $paymentsQuery->orderBy('created_at', 'desc')->get();

        // Payment requests
        $paymentRequestsQuery = PaymentRequest::with(['serviceRequest', 'user']);
        if ($serviceRequestId) {
            $paymentRequestsQuery->where('service_request_id', $serviceRequestId);
        }
        if ($technicianId && !$serviceRequestId) {
            $paymentRequestsQuery->whereHas('serviceRequest', $techSrFilter);
        }
        if ($from) { $paymentRequestsQuery->where('created_at', '>=', $from); }
        if ($to)   { $paymentRequestsQuery->where('created_at', '<=', $to); }
        $paymentRequests = $paymentRequestsQuery->orderBy('created_at', 'desc')->get();

        // Technician payments
        $techPaymentsQuery = TechnicianPayment::with(['technician.user', 'serviceRequest']);
        if ($serviceRequestId) {
            $techPaymentsQuery->where('service_request_id', $serviceRequestId);
        }
        if ($technicianId) {
            $techPaymentsQuery->where('technician_id', $technicianId);
        }
        if ($from) { $techPaymentsQuery->where(function ($q) use ($from) { $q->where('paid_at', '>=', $from)->orWhere('created_at', '>=', $from); }); }
        if ($to)   { $techPaymentsQuery->where(function ($q) use ($to)   { $q->where('paid_at', '<=', $to)->orWhere('created_at', '<=', $to); }); }
        $technicianPayments = $techPaymentsQuery->orderBy('created_at', 'desc')->get();

        // Expenditures
        $expendituresQuery = Expenditure::with(['serviceRequest', 'recordedBy']);
        if ($serviceRequestId) {
            $expendituresQuery->where('service_request_id', $serviceRequestId);
        }
        if ($from) { $expendituresQuery->where('created_at', '>=', $from); }
        if ($to)   { $expendituresQuery->where('created_at', '<=', $to); }
        $expenditures = $expendituresQuery->orderBy('created_at', 'desc')->get();

        // KPI stats
        $stats = [
            'totalReceived' => (float) Payment::where('status', 'completed')->sum('amount'),
            'pendingPayments' => (float) PaymentRequest::where('status', 'pending')->sum('amount'),
            'paidToTechnicians' => (float) TechnicianPayment::where('status', 'completed')->sum('amount'),
            'totalExpenses' => (float) Expenditure::sum('amount'),
        ];

        // Budget summary for filtered SR
        // Delegate to the shared calculator so this page and JobDetails
        // NEVER disagree. Previously this was a duplicated block that
        // missed the Layer-3 sheet-entry accounting — the Budget Snapshot
        // on /admin/payments showed 0 spent for jobs paid via sheets even
        // though JobDetails correctly showed 15,000.
        $budgetSummary = null;
        if ($serviceRequestId) {
            $sr = ServiceRequest::with(['budget', 'technicianPayments', 'expenditures'])->find($serviceRequestId);
            $budgetSummary = $this->buildBudgetSummary($sr);
            if ($budgetSummary && $sr && $sr->budget) {
                // Payments page also expects the raw budget model attached
                // (used by the "Edit Budget" button on that card).
                $budgetSummary['budget'] = $sr->budget;
            }
        }

        // Service requests list for filter dropdown and payments modal
        $serviceRequests = ServiceRequest::with('subTasks:id,service_request_id,technician_id')
            ->select('id', 'request_id', 'description', 'technician_id', 'lead_technician_id')
            ->orderBy('created_at', 'desc')
            ->get();

        // Technicians list for payment modal
        $technicians = Technician::with('user')->orderBy('created_at', 'desc')->get();

        return Inertia::render('Admin/Payments', [
            'payments' => $payments,
            'paymentRequests' => $paymentRequests,
            'technicianPayments' => $technicianPayments,
            'expenditures' => $expenditures,
            'stats' => $stats,
            'budgetSummary' => $budgetSummary,
            'serviceRequests' => $serviceRequests,
            'technicians' => $technicians,
            'filters' => [
                'service_request_id' => $serviceRequestId,
                'technician_id' => $technicianId,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ]);
    }

    public function createAdminAssistedRfq()
    {
        $clients = User::query()
            ->where('role', User::ROLE_CLIENT)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        $serviceCategories = ServiceCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Admin/CreateAssistedRFQ', [
            'clients' => $clients,
            'serviceCategories' => $serviceCategories,
        ]);
    }

    public function storeAdminAssistedRfq(Request $request)
    {
        $clientMode = $request->input('client_mode', 'existing');

        $newClientTemporaryPassword = null;

        if ($clientMode === 'new') {
            $request->validate([
                'new_client.name'  => 'required|string|max:255',
                'new_client.email' => 'required|email|max:255|unique:users,email',
                'new_client.phone' => 'nullable|string|max:30',
                'service_category_id' => 'required|exists:service_categories,id',
                'description' => 'required|string|min:10|max:1000',
                'location' => 'required|string|max:255',
                'urgency' => 'required|in:low,medium,high',
                'files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx|max:10240',
            ]);

            // Capture plain-text password before hashing so we can include it in the welcome email
            $newClientTemporaryPassword = Str::random(16);

            $client = User::create([
                'name'     => $request->input('new_client.name'),
                'email'    => $request->input('new_client.email'),
                'phone'    => $request->input('new_client.phone'),
                'role'     => User::ROLE_CLIENT,
                'password' => Hash::make($newClientTemporaryPassword),
            ]);

            AuditLog::log(AuditLog::ACTION_CREATED, $client, null, [
                'note' => 'Client account created on-the-fly during admin-assisted RFQ',
                'created_by_admin_id' => auth()->id(),
            ]);
        } else {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'service_category_id' => 'required|exists:service_categories,id',
                'description' => 'required|string|min:10|max:1000',
                'location' => 'required|string|max:255',
                'urgency' => 'required|in:low,medium,high',
                'files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx|max:10240',
            ]);

            $client = User::query()
                ->where('id', $request->user_id)
                ->where('role', User::ROLE_CLIENT)
                ->firstOrFail();
        }

        $validated = $request->only(['service_category_id', 'description', 'location', 'urgency']);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(Str::random(6)),
            'user_id' => $client->id,
            'service_category_id' => $validated['service_category_id'],
            'description' => $validated['description'],
            'location' => $validated['location'],
            'urgency' => $validated['urgency'],
            'status' => ServiceRequest::STATUS_PENDING,
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY,
            'created_by_admin_id' => auth()->id(),
        ]);

        if ($request->hasFile('files')) {
            $uploadedFiles = [];
            foreach ($request->file('files') as $file) {
                $path = $file->store('service-requests/' . $serviceRequest->request_id, 'public');
                $uploadedFiles[] = [
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                ];
            }

            $serviceRequest->update(['files' => $uploadedFiles]);
        }

        AuditLog::log(AuditLog::ACTION_CREATED, $serviceRequest, null, [
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY,
            'client_name' => $client->name,
            'created_by_admin_id' => auth()->id(),
        ]);

        // Send welcome email only when a brand-new client account was just created
        if ($newClientTemporaryPassword !== null) {
            $serviceRequest->loadMissing('serviceCategory');
            Mail::to($client->email)->send(
                new \App\Mail\ClientAccountCreated($client, $newClientTemporaryPassword, $serviceRequest)
            );
        }

        return redirect()
            ->route('admin.rfq')
            ->with('success', "Admin-assisted service request created for {$client->name}. Request ID: {$serviceRequest->request_id}");
    }

    public function rfq(Request $request)
    {
        $query = ServiceRequest::with([
            'user',
            'serviceCategory',
            'technician.user',
            'assignedPm',
            'createdByAdmin:id,name,email',
            'proxyQuoteApprover:id,name,email',
            // Eager-load payment requests so the Request Payment modal can
            // show "Already Billed" / remaining balance and the prior
            // payment history without an extra round-trip.
            'paymentRequests:id,service_request_id,payment_request_id,amount,percentage,status,payment_method,paid_at,created_at',
            // Needed for the per-row "action reasons" chip on the RFQ list
            // (see scopeNeedsAdminAction on ServiceRequest for the rules).
            'progressReports:id,service_request_id,is_validated',
            'compensationAmendments:id,service_request_id,status',
        ]);

        // Search filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('request_id', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('serviceCategory', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Status filter — RFQ pipeline statuses (pending/quoted/approved/rejected)
        // live on `rfq_status`, while delivery statuses (awaiting_payment,
        // ready_for_assignment, en_route, in_progress) live on the main `status`
        // column. Route each value to the correct column.
        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $rfqStatuses = ['pending', 'quoted', 'approved', 'rejected'];
                if (in_array($status, $rfqStatuses, true)) {
                    $query->where('rfq_status', $status);
                } else {
                    $query->where('status', $status);
                }
            }
        }

        if ($origin = $request->input('origin')) {
            if (in_array($origin, [
                ServiceRequest::SUBMISSION_MODE_CLIENT_SELF,
                ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY,
            ], true)) {
                $query->where('submission_mode', $origin);
            }
        }

        // "Needs Action" toggle — narrows to REQs waiting on ops to do
        // something (see ServiceRequest::scopeNeedsAdminAction for the
        // exact rules). Client asked for a way to spot these without
        // scrolling every row as the list grows.
        $needsAction = $request->boolean('needs_action');
        if ($needsAction) {
            $query->needsAdminAction();
        }

        // Sort order
        $sortOrder = $request->input('sort', 'newest');
        $query->orderBy('created_at', $sortOrder === 'newest' ? 'desc' : 'asc');

        // Paginate
        $perPage = (int) $request->input('per_page', 15);
        $perPage = in_array($perPage, [10, 15, 25, 50]) ? $perPage : 15;
        $rfqs = $query->paginate($perPage)->withQueryString();

        // Calculate RFQ statistics (always unfiltered)
        $stats = [
            'pending' => ServiceRequest::where('rfq_status', 'pending')->count(),
            'quoted' => ServiceRequest::where('rfq_status', 'quoted')->count(),
            'approved' => ServiceRequest::where('rfq_status', 'approved')->count(),
            'rejected' => ServiceRequest::where('rfq_status', 'rejected')->count(),
            'total' => ServiceRequest::count(),
            'totalValue' => ServiceRequest::where('rfq_status', 'approved')->sum('quote_amount'),
            // Feeds the count badge on the "Needs Action" filter pill so
            // admins can see the queue depth without toggling the filter.
            'needsAction' => ServiceRequest::needsAdminAction()->count(),
        ];

        return Inertia::render('Admin/RFQ', [
            'rfqs' => $rfqs,
            'stats' => $stats,
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', 'all'),
                'origin' => $request->input('origin', 'all'),
                'sort' => $sortOrder,
                'per_page' => $perPage,
                'needs_action' => $needsAction,
            ],
        ]);
    }

    public function submitQuote(Request $request)
    {
        $request->validate([
            'service_request_id' => 'required|exists:service_requests,id',
            'materials' => 'nullable|array',
            'materials.*.name' => 'required_with:materials|string',
            'materials.*.quantity' => 'required_with:materials|numeric|min:0.01',
            'materials.*.unit_price' => 'required_with:materials|numeric|min:0',
            'labor_cost' => 'required|numeric|min:0',
            'transport_cost' => 'nullable|numeric|min:0',
            'down_payment' => 'nullable|numeric|min:0',
            'expected_duration_days' => 'nullable|integer|min:0|max:365',
            'total_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'is_revision' => 'nullable|boolean',
            // Legacy single-file field kept for older frontends that still
            // send materials_file. The new frontend sends materials_files[]
            // (array) so admins can attach multiple supporting documents.
            'materials_file' => 'nullable|file|mimes:pdf,docx,xlsx,xls|max:10240',
            'materials_files' => 'nullable|array|max:10',
            'materials_files.*' => 'file|mimes:pdf,docx,xlsx,xls,jpg,jpeg,png|max:10240',
            'billing_milestones' => 'nullable|array',
            'billing_milestones.*.label' => 'required_with:billing_milestones|string|max:200',
            'billing_milestones.*.progress_pct' => 'required_with:billing_milestones|numeric|min:1|max:100',
            'billing_milestones.*.amount' => 'required_with:billing_milestones|numeric|min:0',
        ]);

        $serviceRequest = ServiceRequest::findOrFail($request->service_request_id);

        // Legacy single-file path (backward compat with older clients).
        $filePath = null;
        if ($request->hasFile('materials_file')) {
            $file = $request->file('materials_file');
            $fileName = 'quote_materials_' . $serviceRequest->request_id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('quotes', $fileName, 'public');
        }

        // New multi-file path — appended to any existing array so a revision
        // that only adds a document doesn't wipe the earlier ones.
        $newFilePaths = [];
        if ($request->hasFile('materials_files')) {
            foreach ($request->file('materials_files') as $idx => $upload) {
                if (!$upload) continue;
                $ext = $upload->getClientOriginalExtension();
                $filename = 'quote_materials_' . $serviceRequest->request_id . '_' . time() . '_' . $idx . '.' . $ext;
                $newFilePaths[] = $upload->storeAs('quotes', $filename, 'public');
            }
        }
        $existingFilePaths = (array) ($serviceRequest->quote_materials_file_paths ?? []);
        $mergedFilePaths = !empty($newFilePaths)
            ? array_values(array_merge($existingFilePaths, $newFilePaths))
            : $existingFilePaths;

        $totalAmount = (float) $request->total_amount;
        $downPayment = $request->filled('down_payment') ? (float) $request->down_payment : null;
        if ($downPayment !== null && $downPayment > $totalAmount) {
            return back()->withErrors([
                'down_payment' => 'Down payment cannot exceed the total quotation amount.',
            ])->withInput();
        }

        // A revision is any submission where the admin explicitly clicked
        // "Revise" OR an existing quoted/approved/rejected quotation is
        // being re-submitted (#5 + #8 + #32 — also valid mid-job).
        $isRevision = (bool) $request->boolean('is_revision')
            || in_array(
                $serviceRequest->rfq_status,
                [
                    ServiceRequest::RFQ_STATUS_QUOTED,
                    ServiceRequest::RFQ_STATUS_APPROVED,
                    ServiceRequest::RFQ_STATUS_REJECTED,
                ],
                true,
            );

        // #32 — When revising a quotation while the job is in progress,
        // billed milestones are locked: the new total must be at least
        // what's already been paid out so the math reconciles.
        if ($isRevision) {
            $alreadyBilled = (float) PaymentRequest::where('service_request_id', $serviceRequest->id)
                ->whereIn('status', [PaymentRequest::STATUS_PAID])
                ->sum('amount');
            if ($alreadyBilled > 0 && $totalAmount + 0.001 < $alreadyBilled) {
                return back()->withErrors([
                    'total_amount' => sprintf(
                        'Revised total (KES %s) cannot be lower than what has already been paid (KES %s). Reduce the unbilled scope only.',
                        number_format($totalAmount, 2),
                        number_format($alreadyBilled, 2)
                    ),
                ])->withInput();
            }
        }

        // Normalise the submitted schedule. Milestones that have already
        // raised a bill are NOT touched by this — BillingService replaces
        // only the unbilled tail. Wiping the whole schedule here is what
        // re-billed clients for milestones they had already paid.
        $billingMilestones = null;
        if ($request->filled('billing_milestones')) {
            $billingMilestones = collect($request->billing_milestones)
                ->map(fn ($m) => [
                    'label'        => $m['label'],
                    'progress_pct' => (float) $m['progress_pct'],
                    'amount'       => (float) $m['amount'],
                ])
                ->sortBy('progress_pct')
                ->values()
                ->all();
        }

        $updateData = [
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => $totalAmount,
            'quote_materials' => $request->materials,
            'quote_labor_cost' => $request->labor_cost,
            'quote_transport_cost' => (float) ($request->transport_cost ?? 0),
            'quote_down_payment' => $downPayment,
            'expected_duration_days' => $request->filled('expected_duration_days')
                ? (int) $request->expected_duration_days
                : $serviceRequest->expected_duration_days,
            'quote_notes' => $request->notes,
            'quote_materials_file_path' => $filePath ?? $serviceRequest->quote_materials_file_path,
            'quote_materials_file_paths' => !empty($mergedFilePaths) ? $mergedFilePaths : null,
        ];

        if ($isRevision) {
            $updateData['quote_revision_count'] = (int) ($serviceRequest->quote_revision_count ?? 0) + 1;
            $updateData['quote_last_revised_at'] = now();
        }

        // Transition status to awaiting_quote_approval (also resets the cycle
        // if a previously-approved quotation is being revised so the client
        // can re-approve the new figures).
        if ($isRevision || in_array($serviceRequest->status, [
            ServiceRequest::STATUS_AWAITING_QUOTE_GENERATION,
            ServiceRequest::STATUS_PENDING,
            'pending',
        ])) {
            $updateData['status'] = ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL;
        }

        $serviceRequest->update($updateData);

        // #4 — When a quote is revised, any UNPAID pending payment requests
        // (manual or milestone-triggered) need to be cancelled so the
        // client isn't asked to pay against the stale figure. We record
        // the cancelled IDs to mention in the revision email.
        //
        // Order matters: cancel first, THEN rewrite the schedule. Cancelling
        // frees the milestones whose bills are being withdrawn so they can be
        // replaced, while milestones already PAID keep their payment link and
        // survive untouched.
        $cancelledRequestIds = [];
        if ($isRevision) {
            $cancelledQuery = PaymentRequest::where('service_request_id', $serviceRequest->id)
                ->where('status', PaymentRequest::STATUS_PENDING);
            $cancelledRequestIds = $cancelledQuery->pluck('payment_request_id')->all();
            $cancelledIds = $cancelledQuery->pluck('id')->all();
            $cancelledQuery->update([
                'status' => PaymentRequest::STATUS_CANCELLED,
                'notes'  => \Illuminate\Support\Facades\DB::raw("CONCAT(COALESCE(notes,''), '\n[Cancelled by quote revision on " . now()->toDateTimeString() . "]')"),
            ]);

            // Detach milestones whose bill was just withdrawn so the new
            // schedule can replace them.
            \App\Models\ReqBillingMilestone::where('service_request_id', $serviceRequest->id)
                ->whereIn('payment_request_id', $cancelledIds)
                ->update(['payment_request_id' => null, 'triggered_at' => null]);
        }

        app(\App\Services\BillingService::class)
            ->replaceUnbilledMilestones($serviceRequest->fresh(), $billingMilestones);

        // Send the appropriate email
        try {
            if ($isRevision) {
                Mail::to($serviceRequest->user->email)->send(
                    new \App\Mail\QuotationRevised($serviceRequest->fresh(), $cancelledRequestIds)
                );
            } else {
                Mail::to($serviceRequest->user->email)->send(new QuotationSent($serviceRequest));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Quotation email failed', [
                'service_request_id' => $serviceRequest->id,
                'is_revision' => $isRevision,
                'error' => $e->getMessage(),
            ]);
        }

        $message = $isRevision
            ? "Revised quotation (revision #{$updateData['quote_revision_count']}) sent to client."
            : 'Quotation sent to client successfully!';

        return redirect()->route('admin.rfq')->with('success', $message);
    }

    /**
     * Item 4: Download the quotation PDF for an RFQ. Mirrors the exact
     * template + payload the QuotationSent mailer attaches so what admin
     * downloads is byte-identical to what the client received. Only works
     * for RFQs that have been quoted (or approved) — nothing to render
     * before a quote exists.
     */
    public function downloadQuotationPdf(ServiceRequest $serviceRequest)
    {
        if (!in_array($serviceRequest->rfq_status, [
            ServiceRequest::RFQ_STATUS_QUOTED,
            ServiceRequest::RFQ_STATUS_APPROVED,
        ], true)) {
            return redirect()->route('admin.rfq')
                ->with('error', 'No quotation to download — this RFQ has not been quoted yet.');
        }

        $milestones = $serviceRequest->milestones()
            ->orderBy('progress_step')
            ->get(['progress_step', 'amount', 'notes', 'status']);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.quotation', [
            'serviceRequest' => $serviceRequest,
            'materials'      => $serviceRequest->quote_materials ?? [],
            'laborCost'      => $serviceRequest->quote_labor_cost ?? 0,
            'transportCost'  => $serviceRequest->quote_transport_cost ?? 0,
            'downPayment'    => $serviceRequest->quote_down_payment ?? 0,
            'totalAmount'    => $serviceRequest->quote_amount ?? 0,
            'notes'          => $serviceRequest->quote_notes,
            'milestones'     => $milestones,
            'mpesaPaybill'   => config('services.mpesa.shortcode'),
            'bank'           => config('services.bank'),
        ]);

        $filename = 'Quotation-' . ($serviceRequest->request_id ?? $serviceRequest->id) . '.pdf';
        return $pdf->download($filename);
    }

    public function rejectRFQ(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'reason' => 'required|string|min:10'
        ]);

        $serviceRequest->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_REJECTED,
            'rejection_reason' => $request->reason
        ]);

        // Send email notification to client
        Mail::to($serviceRequest->user->email)->send(new QuotationRejected($serviceRequest));

        return redirect()->route('admin.rfq')->with('success', 'Service request rejected successfully.');
    }

    /**
     * Cancel a service request outright (as opposed to rejecting the quotation).
     *
     * Fills the gap where admin-assisted RFQs couldn't be pulled once they
     * were in flight — clients had a decline path for their own RFQs, admins
     * had nothing equivalent for the ones they'd created on behalf. Works
     * for both submission modes; guarded against terminal states so we
     * can't cancel something that's already completed/closed/cancelled.
     */
    public function cancelRfq(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        $terminalStatuses = [
            ServiceRequest::STATUS_COMPLETED,
            ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
            ServiceRequest::STATUS_CLOSED,
            ServiceRequest::STATUS_ARCHIVED,
            ServiceRequest::STATUS_CANCELLED,
        ];

        if (in_array($serviceRequest->status, $terminalStatuses, true)) {
            return redirect()->route('admin.rfq')
                ->with('error', 'This request is already in a terminal state and cannot be cancelled.');
        }

        $oldValues = [
            'status'     => $serviceRequest->status,
            'rfq_status' => $serviceRequest->rfq_status,
        ];

        $serviceRequest->update([
            'status'           => ServiceRequest::STATUS_CANCELLED,
            'rejection_reason' => 'Cancelled by admin: ' . $request->reason,
        ]);

        AuditLog::log(AuditLog::ACTION_STATE_CHANGED, $serviceRequest, $oldValues, [
            'status'         => $serviceRequest->status,
            'cancelled_by'   => auth()->id(),
            'cancelled_at'   => now()->toDateTimeString(),
            'cancel_reason'  => $request->reason,
        ]);

        return redirect()->route('admin.rfq')
            ->with('success', 'Service request cancelled successfully.');
    }

    public function approveRfqOnBehalf(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'note' => 'required|string|min:10|max:1000',
        ]);

        if (!$serviceRequest->isAdminAssisted()) {
            return redirect()->route('admin.rfq')
                ->with('error', 'Only admin-assisted requests can be approved on behalf of a client.');
        }

        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_QUOTED) {
            return redirect()->route('admin.rfq')
                ->with('error', 'This quotation cannot be proxy-approved in its current status.');
        }

        $oldValues = [
            'rfq_status' => $serviceRequest->rfq_status,
            'status' => $serviceRequest->status,
            'proxy_quote_approved_by' => $serviceRequest->proxy_quote_approved_by,
            'proxy_quote_approved_at' => optional($serviceRequest->proxy_quote_approved_at)?->toDateTimeString(),
        ];

        $updateData = [
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'proxy_quote_approved_by' => auth()->id(),
            'proxy_quote_approved_at' => now(),
            'proxy_quote_approval_note' => $request->note,
        ];

        if (in_array($serviceRequest->status, [
            ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            ServiceRequest::STATUS_PENDING,
            'pending',
        ], true)) {
            $updateData['status'] = ServiceRequest::STATUS_AWAITING_PAYMENT;
        }

        $serviceRequest->update($updateData);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $serviceRequest, $oldValues, [
            'rfq_status' => $serviceRequest->rfq_status,
            'status' => $serviceRequest->status,
            'proxy_quote_approved_by' => auth()->id(),
            'proxy_quote_approved_at' => $serviceRequest->proxy_quote_approved_at?->toDateTimeString(),
            'proxy_quote_approval_note' => $serviceRequest->proxy_quote_approval_note,
        ]);

        return redirect()->route('admin.rfq')
            ->with('success', 'Quotation approved on behalf of the client. The request now continues through the normal workflow.');
    }

    public function users(Request $request)
    {
        $query = User::with('technician')->orderBy('created_at', 'desc');

        // Search Filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Role Filter
        if ($request->filled('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        $users = $query->paginate(10)->withQueryString();

        $stats = [
            'total' => User::count(),
            'clients' => User::where('role', 'client')->count(),
            'technicians' => User::where('role', 'technician')->count(),
            'admins' => User::where('role', 'admin')->count()
        ];

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'stats' => $stats,
            'filters' => $request->only(['search', 'role'])
        ]);
    }

    public function storeUser(Request $request)
    {
        // Strip technician-only fields when creating any non-technician role.
        // The Vue form sends these as empty strings or stale values from a
        // previous selection — without this, validation has to dance around
        // them with nullable rules that some hosting layers don't honour
        // the same way (e.g. when ConvertEmptyStringsToNull is disabled).
        if ($request->input('role') !== 'technician') {
            $request->merge([
                'specialization' => null,
                'location'       => null,
                'availability'   => null,
                'skills'         => null,
            ]);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'role' => ['required', Rule::in(User::ROLES)],
            'specialization' => 'nullable|required_if:role,technician|string|max:255',
            'location' => 'nullable|required_if:role,technician|string|max:255',
            'availability' => 'nullable|in:available,busy,on_leave',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array'
        ]);

        if ($request->role === 'technician') {
            return back()
                ->withErrors([
                    'role' => 'Create technicians from Technician Management so the required onboarding documents can be uploaded.',
                ])
                ->withInput();
        }

        // Create user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role,
            'email_verified_at' => now() // Auto-verify admin created users
        ]);

        // Create technician profile if role is technician
        if ($request->role === 'technician') {
            Technician::createWithReference([
                'user_id' => $user->id,
                'specialization' => $request->specialization,
                'location' => $request->location,
                'availability' => $request->availability ?? 'available',
                'bio' => $request->bio,
                'skills' => $request->skills,
                'rating' => 0,
                'total_jobs' => 0
            ]);
        }

        return redirect()->route('admin.users')->with('success', 'User created successfully!');
    }

    public function updateUser(Request $request, User $user)
    {
        // Strip technician-only fields when updating a non-technician.
        // Editing a client's name should never trip on an empty specialization
        // sitting in the form state from the technician branch of the modal.
        if ($request->input('role') !== 'technician') {
            $request->merge([
                'specialization' => null,
                'location'       => null,
                'availability'   => null,
                'skills'         => null,
            ]);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'role' => ['required', Rule::in(User::ROLES)],
            'specialization' => 'nullable|required_if:role,technician|string|max:255',
            'location' => 'nullable|required_if:role,technician|string|max:255',
            'availability' => 'nullable|in:available,busy,on_leave',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array'
        ]);

        if ($request->role === 'technician' && !$user->technician) {
            return back()
                ->withErrors([
                    'role' => 'Convert or create technicians from Technician Management so the required onboarding documents are captured.',
                ])
                ->withInput();
        }

        // Update user
        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'role' => $request->role
        ]);

        // Handle technician profile
        if ($request->role === 'technician') {
            if ($user->technician) {
                // Update existing technician
                $user->technician->update([
                    'specialization' => $request->specialization,
                    'location' => $request->location,
                    'availability' => $request->availability ?? 'available',
                    'bio' => $request->bio,
                    'skills' => $request->skills
                ]);
            } else {
                // Create new technician profile
                Technician::createWithReference([
                    'user_id' => $user->id,
                    'specialization' => $request->specialization,
                    'location' => $request->location,
                    'availability' => $request->availability ?? 'available',
                    'bio' => $request->bio,
                    'skills' => $request->skills,
                    'rating' => 0,
                    'total_jobs' => 0
                ]);
            }
        } else {
            // Remove technician profile if role changed from technician
            if ($user->technician) {
                $user->technician->delete();
            }
        }

        return redirect()->route('admin.users')->with('success', 'User updated successfully!');
    }

    public function destroyUser(User $user)
    {
        // Prevent deleting admin users
        if ($user->role === 'admin') {
            return redirect()->route('admin.users')->with('error', 'Cannot delete admin users.');
        }

        // Check for active jobs if technician
        if ($user->role === 'technician' && $user->technician) {
            $activeJobs = $user->technician->serviceRequests()->whereIn('status', ['assigned', 'in_progress'])->count();
            if ($activeJobs > 0) {
                return redirect()->route('admin.users')->with('error', 'Cannot delete technician with active jobs.');
            }
        }

        // Check for any non-terminal service requests if client. The previous
        // version missed many statuses (awaiting_payment, ready_for_assignment,
        // en_route, on_site, etc.), which is why the deletion appeared to silently
        // succeed in the UI but the user still showed up on refresh.
        if ($user->role === 'client') {
            $terminalStatuses = ['completed', 'closed', 'cancelled', 'rejected'];
            $activeRequests = $user->serviceRequests()
                ->whereNotIn('status', $terminalStatuses)
                ->get(['id', 'request_id', 'status']);

            if ($activeRequests->count() > 0) {
                $refs = $activeRequests->pluck('request_id')->take(5)->implode(', ');
                $more = $activeRequests->count() > 5 ? ' (+' . ($activeRequests->count() - 5) . ' more)' : '';
                return redirect()->route('admin.users')->with(
                    'error',
                    sprintf(
                        'Cannot delete client: they have %d active service request%s — %s%s. Close, cancel or complete these requests first.',
                        $activeRequests->count(),
                        $activeRequests->count() === 1 ? '' : 's',
                        $refs,
                        $more
                    )
                );
            }

            // Also block deletion if they have pending payment requests
            // (money owed or partially paid).
            $pendingPayments = \App\Models\PaymentRequest::where('user_id', $user->id)
                ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
                ->count();
            if ($pendingPayments > 0) {
                return redirect()->route('admin.users')->with(
                    'error',
                    "Cannot delete client: they have {$pendingPayments} pending payment request" .
                    ($pendingPayments === 1 ? '' : 's') . '. Settle or cancel these first.'
                );
            }
        }

        $user->delete();

        return redirect()->route('admin.users')->with('success', 'User deleted successfully!');
    }

    /**
     * Repair duplicate Payment rows on a specific service request.
     * Runs the same dedup logic as the artisan command but targeted to a
     * single SR so admin can fix the client-reported case without affecting
     * anything else.
     */
    public function deduplicatePayments(Request $request, ServiceRequest $serviceRequest)
    {
        $dryRun = (bool) $request->boolean('dry_run', false);

        \Illuminate\Support\Facades\Artisan::call('payments:deduplicate', array_filter([
            '--dry-run' => $dryRun,
            '--service-request' => $serviceRequest->id,
        ]));

        $output = trim(\Illuminate\Support\Facades\Artisan::output());

        return back()->with('success', ($dryRun ? '[Dry-run] ' : '') . 'Payment dedup complete. ' . $output);
    }

    /**
     * Manually trigger the final-balance payment request for one job.
     * Same logic as the scheduled `payments:raise-final` command (#13),
     * but admin can invoke it on-demand if the schedule hasn't fired yet
     * or they want to bill before the 24h grace.
     */
    public function raiseFinalPayment(ServiceRequest $serviceRequest)
    {
        \Illuminate\Support\Facades\Artisan::call('payments:raise-final', [
            '--service-request' => $serviceRequest->id,
        ]);
        $output = trim(\Illuminate\Support\Facades\Artisan::output());
        return back()->with('success', 'Final payment request triggered. ' . $output);
    }

    /**
     * Request payment from client for an approved service request.
     */
    public function requestPayment(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'percentage' => 'nullable|numeric|min:0|max:100',
            'amount' => 'nullable|numeric|min:1',
            'is_down_payment' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        if (!$request->filled('percentage') && !$request->filled('amount')) {
            return response()->json([
                'error' => 'Either a percentage or a fixed amount is required.',
            ], 422);
        }

        // Check if RFQ is approved
        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return response()->json([
                'error' => 'Payment can only be requested for approved service requests.'
            ], 422);
        }

        $quoteAmount = (float) $serviceRequest->quote_amount;
        if ($quoteAmount <= 0) {
            return response()->json([
                'error' => 'Cannot bill against a zero-value quotation.',
            ], 422);
        }

        // #14a: Compute amount, then enforce the approved-quote cap so the
        // sum of all non-cancelled billings stays at or below quote_amount.
        if ($request->filled('amount')) {
            $amount = (float) $request->amount;
            $percentage = round(($amount / $quoteAmount) * 100, 2);
        } else {
            $percentage = (float) $request->percentage;
            $amount = round(($percentage / 100) * $quoteAmount, 2);
        }

        $billing = app(\App\Services\BillingService::class);
        $alreadyBilled = $billing->billed($serviceRequest);
        $remaining = $billing->billableRemaining($serviceRequest);

        if ($amount > $remaining + 0.001) {
            return response()->json([
                'error' => sprintf(
                    'This request (KES %s) exceeds the remaining approved balance (KES %s of KES %s already billed). Request additional client approval before billing beyond the quotation.',
                    number_format($amount, 2),
                    number_format($alreadyBilled, 2),
                    number_format($quoteAmount, 2)
                ),
                'remaining' => $remaining,
                'already_billed' => $alreadyBilled,
                'quote_amount' => $quoteAmount,
            ], 422);
        }

        // #14b: Determine if this should be treated as the down payment and
        // block duplicate down-payment requests.
        $isDownPayment = $request->boolean('is_down_payment', $alreadyBilled <= 0);
        if ($isDownPayment && $serviceRequest->down_payment_requested) {
            return response()->json([
                'error' => 'A down payment has already been requested for this job. Send a progress payment request instead.',
            ], 422);
        }

        // Create payment request
        $paymentRequest = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $serviceRequest->id,
            'user_id' => $serviceRequest->user_id,
            'requested_by' => auth()->id(),
            'percentage' => $percentage,
            'amount' => $amount,
            'status' => PaymentRequest::STATUS_PENDING,
            'notes' => $request->notes,
        ]);

        if ($isDownPayment) {
            $serviceRequest->update(['down_payment_requested' => true]);
        }

        // Send notification to client
        $serviceRequest->user->notify(new PaymentRequestNotification($paymentRequest));

        return response()->json([
            'success' => true,
            'message' => 'Payment request sent successfully!',
            'payment_request' => $paymentRequest,
        ]);
    }

    /**
     * For admin-assisted RFQs: admin confirms payment directly instead of
     * sending a request to the client. Creates the PaymentRequest + Payment
     * in one step and transitions the SR to READY_FOR_ASSIGNMENT.
     */
    public function confirmPaymentOnBehalf(Request $request, ServiceRequest $serviceRequest)
    {
        $serviceRequest->loadMissing('user');

        if ($serviceRequest->submission_mode !== ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY) {
            return response()->json(['error' => 'This action is only available for admin-assisted service requests.'], 422);
        }

        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return response()->json(['error' => 'Payment can only be confirmed for approved service requests.'], 422);
        }

        $request->validate([
            'percentage'           => 'required|numeric|min:1|max:100',
            'payment_method'       => 'required|in:cash,cheque,bank_deposit,mpesa',
            'cheque_number'        => 'required_if:payment_method,cheque|nullable|string|max:50',
            'bank_reference'       => 'required_if:payment_method,bank_deposit|nullable|string|max:100',
            'mpesa_receipt_number' => 'required_if:payment_method,mpesa|nullable|string|max:20',
            'phone_number'         => 'nullable|string|max:20',
            'notes'                => 'nullable|string|max:500',
            // #42 — require supporting documentation for cheque, bank
            // deposit, and M-Pesa confirmations. Cash payments are
            // typically receipted offline, so evidence stays optional
            // there but encouraged.
            'evidence'             => 'required_if:payment_method,cheque|required_if:payment_method,bank_deposit|required_if:payment_method,mpesa|nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $quoteTotal = (float) $serviceRequest->quote_amount;
        $amount = round(($request->percentage / 100) * $quoteTotal, 2);

        $alreadyPaid = (float) PaymentRequest::where('service_request_id', $serviceRequest->id)
            ->whereIn('status', [PaymentRequest::STATUS_PAID])
            ->sum('amount');

        $remaining = round($quoteTotal - $alreadyPaid, 2);
        if ($amount > $remaining + 0.001) {
            return response()->json([
                'error' => sprintf(
                    'This payment (KES %s) would exceed the remaining balance (KES %s). Total quote is KES %s with KES %s already received.',
                    number_format($amount, 2),
                    number_format(max($remaining, 0), 2),
                    number_format($quoteTotal, 2),
                    number_format($alreadyPaid, 2)
                ),
            ], 422);
        }

        $paymentPayload = [
            'requested_by'         => auth()->id(),
            'percentage'           => $request->percentage,
            'amount'               => $amount,
            'notes'                => $request->notes,
            'payment_method'       => $request->payment_method,
            'cheque_number'        => $request->cheque_number,
            'bank_reference'       => $request->bank_reference,
            'mpesa_receipt_number' => $request->mpesa_receipt_number,
            'phone_number'         => $request->phone_number,
        ];

        if ($request->payment_method === 'mpesa' && $request->mpesa_receipt_number) {
            // Mirror the receipt into the legacy transaction id field used by
            // the M-Pesa callback handler so downstream reporting can find it.
            $paymentPayload['mpesa_transaction_id'] = $request->mpesa_receipt_number;
        }

        // Reuse any existing pending payment request for this SR (may exist from an earlier
        // attempt or a mistakenly sent normal request) — update it with the new details.
        $existingPending = PaymentRequest::where('service_request_id', $serviceRequest->id)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->first();

        if ($existingPending) {
            $existingPending->update($paymentPayload);
            $paymentRequest = $existingPending->fresh();
        } else {
            $paymentRequest = PaymentRequest::create(array_merge([
                'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
                'service_request_id' => $serviceRequest->id,
                'user_id'            => $serviceRequest->user_id,
                'status'             => PaymentRequest::STATUS_PENDING,
            ], $paymentPayload));
        }

        // Store proof of payment if uploaded
        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store('payment-evidence', 'public');
            $paymentRequest->update(['evidence_path' => $path]);
        }

        // Immediately mark as paid — admin is confirming on behalf
        $paymentRequest->markAsPaid($request->payment_method);

        // Create the Payment record — dedup so a late callback can't double up.
        Payment::recordCompleted([
            'payment_request_id'   => $paymentRequest->id,
            'service_request_id'   => $serviceRequest->id,
            'user_id'              => $serviceRequest->user_id,
            'amount'               => $amount,
            'payment_method'       => $request->payment_method,
            'phone_number'         => $request->phone_number ?: ($serviceRequest->user->phone ?? ''),
            'mpesa_receipt_number' => $request->mpesa_receipt_number,
            'mpesa_transaction_id' => $request->mpesa_receipt_number,
            'account_reference'    => $serviceRequest->request_id,
            'paid_at'              => now(),
            'notes'                => 'Payment confirmed on behalf of client by admin (' . auth()->user()->name . ')',
        ]);

        // Advance the service request status
        if (in_array($serviceRequest->status, [
            ServiceRequest::STATUS_AWAITING_PAYMENT,
            ServiceRequest::STATUS_PAYMENT_PENDING_APPROVAL,
            'pending',
        ])) {
            $serviceRequest->update(['status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT]);
        }

        AuditLog::log(AuditLog::ACTION_APPROVAL, $paymentRequest, null, [
            'note'    => 'Admin confirmed payment on behalf of client',
            'amount'  => $amount,
            'method'  => $request->payment_method,
            'admin'   => auth()->user()->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Payment of KSH " . number_format($amount, 2) . " confirmed on behalf of client.",
        ]);
    }

    // ==================== SUB-TASK MANAGEMENT ====================

    public function addSubTask(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $maxOrder = $serviceRequest->subTasks()->max('order') ?? 0;

        ServiceSubTask::create([
            'service_request_id' => $serviceRequest->id,
            'title' => $request->title,
            'description' => $request->description,
            'order' => $maxOrder + 1,
        ]);

        // Mark service request as having sub-tasks
        if (!$serviceRequest->has_sub_tasks) {
            $serviceRequest->update(['has_sub_tasks' => true]);
        }

        return redirect()->route('admin.jobs.show', $serviceRequest)->with('success', 'Sub-task added successfully!');
    }

    public function updateSubTask(Request $request, ServiceSubTask $serviceSubTask)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $serviceSubTask->update([
            'title' => $request->title,
            'description' => $request->description,
        ]);

        return redirect()->route('admin.jobs.show', $serviceSubTask->service_request_id)->with('success', 'Sub-task updated successfully!');
    }

    public function deleteSubTask(ServiceSubTask $serviceSubTask)
    {
        $serviceRequest = $serviceSubTask->serviceRequest;
        $serviceSubTask->delete();

        // If no sub-tasks remain, reset the flag
        if ($serviceRequest->subTasks()->count() === 0) {
            $serviceRequest->update(['has_sub_tasks' => false]);
        }

        $serviceRequest->recalculateProgress();

        return redirect()->route('admin.jobs.show', $serviceRequest)->with('success', 'Sub-task deleted successfully!');
    }

    public function assignSubTaskTechnician(Request $request, ServiceSubTask $serviceSubTask)
    {
        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'agreed_compensation' => 'required|numeric|min:0',
            'compensation_notes' => 'nullable|string|max:1000',
        ]);

        $technician = Technician::findOrFail($request->technician_id);
        $serviceRequest = $serviceSubTask->serviceRequest;

        // Check if RFQ has been approved (if RFQ workflow is enabled)
        if ($serviceRequest->rfq_status && $serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return redirect()->route('admin.jobs.show', $serviceRequest)->with('error', 'Cannot assign technician until RFQ is approved by client.');
        }

        $this->ensureLaborBudgetCapacity(
            $serviceRequest,
            (float) $request->agreed_compensation,
            excludeSubTaskId: $serviceSubTask->id
        );
        $this->ensureTechnicianMilestoneCoverage(
            $serviceRequest,
            (int) $technician->id,
            (float) $request->agreed_compensation
        );

        // Assign technician to sub-task
        $serviceSubTask->update([
            'technician_id' => $technician->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'assigned_at' => now(),
            'agreed_compensation' => (float) $request->agreed_compensation,
            'compensation_notes' => $request->compensation_notes,
        ]);

        $this->syncSubTaskAssignment(
            $serviceSubTask,
            $technician,
            (float) $request->agreed_compensation,
            $request->compensation_notes
        );

        // Determine if this is the first assigned technician (becomes lead)
        $isFirstAssignment = !$serviceRequest->lead_technician_id;

        if ($isFirstAssignment) {
            $serviceRequest->update([
                'lead_technician_id' => $technician->id,
                'technician_id' => $technician->id,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);

            // Send email notifications
            if ($technician->user && $technician->user->email) {
                Mail::to($technician->user->email)->send(new JobAssigned($serviceRequest));
            }
            if ($serviceRequest->user && $serviceRequest->user->email) {
                Mail::to($serviceRequest->user->email)->send(new TechnicianAssigned($serviceRequest, $technician));
            }
        }

        // Update technician availability
        if ($technician->availability === 'available') {
            $technician->update(['availability' => 'busy']);
        }

        return redirect()->route('admin.jobs.show', $serviceRequest)->with('success', 'Technician assigned to sub-task successfully!');
    }

    /**
     * Assign a PM to an RFQ.
     */
    public function assignPm(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'pm_id' => 'required|exists:users,id',
        ]);

        $pm = User::findOrFail($request->pm_id);
        if ($pm->role !== 'project_manager') {
            return redirect()->back()->with('error', 'Selected user is not a Project Manager.');
        }

        $serviceRequest->update([
            'assigned_pm_id' => $pm->id,
            'status' => ServiceRequest::STATUS_AWAITING_TECH_AVAILABILITY,
        ]);

        \App\Models\AuditLog::log(
            \App\Models\AuditLog::ACTION_ASSIGNMENT,
            $serviceRequest,
            null,
            ['assigned_pm_id' => $pm->id, 'pm_name' => $pm->name]
        );

        return redirect()->back()->with('success', "RFQ assigned to PM: {$pm->name}");
    }

    /**
     * View audit logs.
     */
    public function auditLogs(Request $request)
    {
        $logs = \App\Models\AuditLog::with('user')
            ->when($request->action, fn($q, $a) => $q->where('action', $a))
            ->when($request->user_id, fn($q, $u) => $q->where('user_id', $u))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return Inertia::render('Admin/AuditLogs', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'user_id']),
        ]);
    }

    /**
     * View technician leads.
     */
    public function technicianLeads(Request $request)
    {
        $leads = \App\Models\TechnicianLead::orderBy('created_at', 'desc')->paginate(20);

        return Inertia::render('Admin/TechnicianLeads', [
            'leads' => $leads,
        ]);
    }

    /**
     * Reports page.
     */
    public function reports(Request $request)
    {
        $reportingService = app(\App\Services\ReportingService::class);

        $from = $request->from ? \Carbon\Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? \Carbon\Carbon::parse($request->to) : now();

        $revenueReport = $reportingService->getRevenueReport($from, $to);

        return Inertia::render('Admin/Reports', [
            'report' => $revenueReport,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function rfqRevenueReport(Request $request)
    {
        $reportingService = app(\App\Services\ReportingService::class);

        $from = $request->from ? \Carbon\Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? \Carbon\Carbon::parse($request->to) : now();
        $clientId = $request->client_id ? (int) $request->client_id : null;

        return Inertia::render('Admin/ReportRfqRevenue', [
            'report' => $reportingService->getRfqRevenueReport($from, $to, null, $clientId),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'client_id' => $clientId],
        ]);
    }

    public function clientRevenueReport(Request $request)
    {
        $reportingService = app(\App\Services\ReportingService::class);

        $from = $request->from ? \Carbon\Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? \Carbon\Carbon::parse($request->to) : now();

        return Inertia::render('Admin/ReportClientRevenue', [
            'report' => $reportingService->getClientRevenueReport($from, $to),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function exportRfqRevenueReport(Request $request, string $format)
    {
        [$from, $to] = $this->resolveReportRange($request);
        $reportingService = app(\App\Services\ReportingService::class);
        $clientId = $request->client_id ? (int) $request->client_id : null;

        return $this->exportRevenueBreakdown(
            report: $reportingService->getRfqRevenueReport($from, $to, null, $clientId),
            variant: 'rfq',
            format: $format,
            scopeLabel: 'Admin',
        );
    }

    public function exportClientRevenueReport(Request $request, string $format)
    {
        [$from, $to] = $this->resolveReportRange($request);
        $reportingService = app(\App\Services\ReportingService::class);

        return $this->exportRevenueBreakdown(
            report: $reportingService->getClientRevenueReport($from, $to),
            variant: 'client',
            format: $format,
            scopeLabel: 'Admin',
        );
    }

    /**
     * Approve technician vetting.
     */
    public function approveTechnician(Technician $technician)
    {
        $technician->update([
            'vetting_status' => 'approved',
            'vetted_by' => auth()->id(),
            'vetted_at' => now(),
        ]);

        \App\Models\AuditLog::log(\App\Models\AuditLog::ACTION_APPROVAL, $technician);

        return redirect()->back()->with('success', 'Technician approved.');
    }

    private function resolveReportRange(Request $request): array
    {
        $from = $request->from ? \Carbon\Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? \Carbon\Carbon::parse($request->to) : now();

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
     * Reject technician vetting.
     */
    public function rejectTechnician(Technician $technician)
    {
        $technician->update([
            'vetting_status' => 'rejected',
            'vetted_by' => auth()->id(),
            'vetted_at' => now(),
        ]);

        \App\Models\AuditLog::log(\App\Models\AuditLog::ACTION_UPDATED, $technician, null, ['vetting_status' => 'rejected']);

        return redirect()->back()->with('success', 'Technician rejected.');
    }

    public function technicianReport(Technician $technician)
    {
        $technician->load('user');

        // Direct payments to technician (labor, materials, other)
        $directPayments = TechnicianPayment::where('technician_id', $technician->id)
            ->with('serviceRequest:id,request_id,job_reference,status,quote_amount')
            ->orderBy('paid_at', 'desc')
            ->get();

        // Payment sheet entries for this technician
        $sheetEntries = TechnicianPaymentEntry::where('technician_id', $technician->id)
            ->with([
                'serviceRequest:id,request_id,job_reference,status,quote_amount',
                'paymentSheet:id,sheet_reference,period_start,period_end,status',
            ])
            ->orderBy('id', 'desc')
            ->get();

        // Service requests this technician is assigned to (with milestones)
        $serviceRequests = ServiceRequest::where('technician_id', $technician->id)
            ->orWhere('lead_technician_id', $technician->id)
            ->with(['serviceCategory:id,name', 'milestones.allocations.technician.user', 'budget'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Build per-service-request payment summary
        $paymentService = app(\App\Services\TechnicianPaymentService::class);
        $jobPayments = $serviceRequests->map(function ($sr) use ($technician, $directPayments, $sheetEntries, $paymentService) {
            $srDirectPayments = $directPayments->where('service_request_id', $sr->id);
            $srSheetEntries = $sheetEntries->where('service_request_id', $sr->id);

            // Delegate to the canonical getTotalLabourPaid — same source
            // of truth as the JobDetails Labour card + overpayment cap.
            // Previously this rolled its own sum that ignored paid_amount
            // for STATUS_PAID entries, so a tech paid via Mark Paid saw
            // 'Total paid' drift from what other pages showed. Now they
            // agree byte-for-byte.
            $totalPaid = $paymentService->getTotalLabourPaid($sr->id, $technician->id);

            // Agreed compensation: prefer the canonical resolveApprovedAmount
            // (checks JobAssignment, sub-tasks, technician_payout in order).
            // Previously the report only looked at the FIRST sheet entry
            // and returned 0 if the tech had never been paid via a sheet,
            // so early-in-the-job techs showed 0 agreed.
            $agreedCompensation = $paymentService->resolveApprovedAmount($sr, $technician->id);
            $latestProgress = (int) ($srSheetEntries->first()?->cumulative_progress_pct ?? 0);

            return [
                'id' => $sr->id,
                'request_id' => $sr->request_id,
                'job_reference' => $sr->job_reference ?? $sr->request_id,
                'service_name' => $sr->serviceCategory->name ?? 'N/A',
                'status' => $sr->status,
                'quote_amount' => (float) ($sr->quote_amount ?? 0),
                'agreed_compensation' => $agreedCompensation,
                'cumulative_progress' => $latestProgress,
                'total_paid' => $totalPaid,
                'is_lead' => $sr->lead_technician_id === $technician->id,
                'milestones' => $sr->milestones->map(fn ($m) => [
                    'id' => $m->id,
                    'progress_step' => $m->progress_step,
                    'amount' => (float) $m->amount,
                    'labor_release_amount' => (float) ($m->labor_release_amount ?? 0),
                    'status' => $m->status,
                    'notes' => $m->notes,
                    'allocated_amount' => (float) $m->allocations
                        ->where('technician_id', $technician->id)
                        ->sum('allocated_amount'),
                ]),
                'direct_payments' => $srDirectPayments->map(fn ($p) => [
                    'id' => $p->id,
                    'payment_id' => $p->payment_id,
                    'category' => $p->category,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'payment_method' => $p->payment_method,
                    'paid_at' => $p->paid_at?->toDateString(),
                    'notes' => $p->notes,
                ])->values(),
                'sheet_entries' => $srSheetEntries->map(fn ($e) => [
                    'id' => $e->id,
                    'sheet_reference' => $e->paymentSheet?->sheet_reference,
                    'period' => $e->paymentSheet
                        ? $e->paymentSheet->period_start . ' - ' . $e->paymentSheet->period_end
                        : 'N/A',
                    'agreed_compensation' => (float) $e->agreed_compensation,
                    'cumulative_progress_pct' => (int) $e->cumulative_progress_pct,
                    'cumulative_amount_due' => (float) $e->cumulative_amount_due,
                    'previous_cumulative_paid' => (float) $e->previous_cumulative_paid,
                    'current_period_payable' => (float) $e->current_period_payable,
                    'status' => $e->status,
                ])->values(),
            ];
        });

        // Summary
        $totalEarned = $jobPayments->sum('total_paid');
        $totalAgreed = $jobPayments->sum('agreed_compensation');

        return Inertia::render('Admin/TechnicianReport', [
            'technician' => $technician,
            'jobPayments' => $jobPayments,
            'summary' => [
                'total_earned' => (float) $totalEarned,
                'total_agreed' => (float) $totalAgreed,
                'total_jobs' => $jobPayments->count(),
                'active_jobs' => $jobPayments->whereIn('status', ['assigned', 'in_progress'])->count(),
                'completed_jobs' => $jobPayments->whereIn('status', ['completed', 'closed'])->count(),
            ],
        ]);
    }

    /**
     * Approve compensation amendment.
     */
    public function approveCompensationAmendment(\App\Models\CompensationAmendment $amendment)
    {
        $amendment->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        // Apply the amendment to the job assignment
        $assignment = \App\Models\JobAssignment::where('service_request_id', $amendment->service_request_id)
            ->where('technician_id', $amendment->technician_id)
            ->first();

        if ($assignment) {
            $assignment->update(['agreed_compensation' => $amendment->proposed_amount]);
        }

        \App\Models\AuditLog::log(\App\Models\AuditLog::ACTION_APPROVAL, $amendment);

        return redirect()->back()->with('success', 'Compensation amendment approved.');
    }

    /**
     * Reject compensation amendment.
     */
    public function rejectCompensationAmendment(Request $request, \App\Models\CompensationAmendment $amendment)
    {
        $amendment->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $request->review_notes,
        ]);

        \App\Models\AuditLog::log(\App\Models\AuditLog::ACTION_UPDATED, $amendment, null, ['status' => 'rejected']);

        return redirect()->back()->with('success', 'Compensation amendment rejected.');
    }
}
