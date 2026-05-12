<?php

namespace App\Services;

use App\Models\JobAssignment;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\TechnicianPayment;
use App\Models\TechnicianPaymentEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportingService
{
    /**
     * Get revenue report for a date range.
     */
    public function getRevenueReport(Carbon $from, Carbon $to, ?int $pmId = null, ?int $clientId = null): array
    {
        $snapshot = $this->buildRevenueSnapshot($from, $to, $pmId, $clientId);
        $rfqRows = $snapshot['rfq_rows'];
        $clientRows = $snapshot['client_rows'];

        $paymentQuery = Payment::query()
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereBetween('paid_at', [$from, $to]);

        if ($pmId) {
            $paymentQuery->whereHas('serviceRequest', function ($query) use ($pmId) {
                $query->forPm($pmId);
            });
        }

        $totalQuotedRevenue = (float) $rfqRows->sum('gross_quoted_amount');
        $totalCollected = (float) (clone $paymentQuery)->sum('amount');
        $outstanding = (float) $rfqRows->sum('outstanding_total');

        // Payment method breakdown
        $byMethod = (clone $paymentQuery)
            ->select('payment_method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        // Job status breakdown
        $jobStatusQuery = ServiceRequest::query()->whereBetween('created_at', [$from, $to]);

        if ($pmId) {
            $jobStatusQuery->forPm($pmId);
        }

        $jobStatusBreakdown = $jobStatusQuery
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
            ]);

        // Total jobs in period
        $totalJobsQuery = ServiceRequest::query()->whereBetween('created_at', [$from, $to]);
        $completedJobsQuery = ServiceRequest::query()
            ->whereIn('status', ['completed', 'closed'])
            ->whereBetween('created_at', [$from, $to]);

        if ($pmId) {
            $totalJobsQuery->forPm($pmId);
            $completedJobsQuery->forPm($pmId);
        }

        $totalJobs = $totalJobsQuery->count();
        $completedJobs = $completedJobsQuery->count();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_quoted_revenue' => (float) $totalQuotedRevenue,
            'total_collected' => (float) $totalCollected,
            'outstanding' => (float) $outstanding,
            'collection_rate' => $totalQuotedRevenue > 0
                ? round(($totalCollected / $totalQuotedRevenue) * 100, 1) : 0,
            'total_jobs' => $totalJobs,
            'completed_jobs' => $completedJobs,
            'by_job' => $rfqRows->map(function ($row) {
                return [
                    'job_reference' => $row['job_reference'],
                    'client' => $row['client_name'],
                    'quoted_amount' => $row['gross_quoted_amount'],
                    'collected' => $row['collected_in_period'],
                    'outstanding' => $row['outstanding_total'],
                ];
            })->values(),
            'by_client' => $clientRows->map(function ($row) {
                return [
                    'user_id' => $row['client_id'],
                    'total_paid' => $row['collected_in_period'],
                    'payment_count' => $row['payment_count_in_period'],
                    'user' => [
                        'name' => $row['client_name'],
                        'email' => $row['client_email'],
                    ],
                ];
            })->values(),
            'by_method' => $byMethod,
            'job_status_breakdown' => $jobStatusBreakdown,
        ];
    }

    /**
     * Get per-RFQ revenue report for a date range.
     */
    public function getRfqRevenueReport(Carbon $from, Carbon $to, ?int $pmId = null, ?int $clientId = null): array
    {
        $rfqRows = $this->buildRevenueSnapshot($from, $to, $pmId, $clientId)['rfq_rows'];
        $topCollected = $rfqRows->first();
        $highestQuote = $rfqRows->sortByDesc('gross_quoted_amount')->first();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'rfq_count' => $rfqRows->count(),
                'total_gross_quoted' => (float) $rfqRows->sum('gross_quoted_amount'),
                'total_collected_in_period' => (float) $rfqRows->sum('collected_in_period'),
                'total_collected' => (float) $rfqRows->sum('total_collected'),
                'total_outstanding' => (float) $rfqRows->sum('outstanding_total'),
                'amended_rfq_count' => (int) $rfqRows->where('is_amended', true)->count(),
            ],
            'leaders' => [
                'top_collected_rfq' => $topCollected,
                'highest_quote_rfq' => $highestQuote,
            ],
            'rows' => $rfqRows->values(),
        ];
    }

    /**
     * Get per-client revenue report for a date range.
     */
    public function getClientRevenueReport(Carbon $from, Carbon $to, ?int $pmId = null, ?int $clientId = null): array
    {
        $clientRows = $this->buildRevenueSnapshot($from, $to, $pmId, $clientId)['client_rows'];
        $topValueClient = $clientRows->first();
        $topVolumeClient = $clientRows
            ->sort(function ($left, $right) {
                return [$right['rfq_count'], $right['gross_quoted_amount']]
                    <=> [$left['rfq_count'], $left['gross_quoted_amount']];
            })
            ->first();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'client_count' => $clientRows->count(),
                'total_rfq_count' => (int) $clientRows->sum('rfq_count'),
                'total_gross_quoted' => (float) $clientRows->sum('gross_quoted_amount'),
                'total_collected_in_period' => (float) $clientRows->sum('collected_in_period'),
                'total_collected' => (float) $clientRows->sum('total_collected'),
                'total_outstanding' => (float) $clientRows->sum('outstanding_total'),
                'average_rfq_value' => $clientRows->sum('rfq_count') > 0
                    ? round($clientRows->sum('gross_quoted_amount') / $clientRows->sum('rfq_count'), 2)
                    : 0,
            ],
            'leaders' => [
                'top_value_client' => $topValueClient,
                'top_volume_client' => $topVolumeClient,
            ],
            'rows' => $clientRows->values(),
        ];
    }

    /**
     * Get client statement.
     */
    public function getClientStatement(int $userId, ?Carbon $from = null, ?Carbon $to = null, ?int $serviceRequestId = null): array
    {
        $query = Payment::where('user_id', $userId)->where('status', 'completed');

        if ($from) $query->where('paid_at', '>=', $from);
        if ($to) $query->where('paid_at', '<=', $to);
        if ($serviceRequestId) $query->where('service_request_id', $serviceRequestId);

        $payments = $query->with('serviceRequest:id,request_id,job_reference')
            ->orderBy('paid_at', 'desc')
            ->get();

        $totalPaid = $payments->sum('amount');

        // Per-RFQ breakdown
        $byRfq = $payments->groupBy('service_request_id')->map(function ($group) {
            $sr = $group->first()->serviceRequest;
            return [
                'job_reference' => $sr->job_reference ?? $sr->request_id ?? 'N/A',
                'total_paid' => $group->sum('amount'),
                'payment_count' => $group->count(),
                'payments' => $group->map(fn ($p) => [
                    'amount' => (float) $p->amount,
                    'method' => $p->payment_method,
                    'date' => $p->paid_at->toDateString(),
                    'status' => $p->admin_approval_status ?? $p->status,
                ]),
            ];
        })->values();

        return [
            'total_paid' => (float) $totalPaid,
            'payments' => $payments,
            'by_rfq' => $byRfq,
        ];
    }

    /**
     * Get technician earnings statement.
     */
    public function getTechnicianEarnings(int $technicianId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $serviceRequestIds = collect()
            ->merge(ServiceRequest::query()
                ->where('technician_id', $technicianId)
                ->orWhere('lead_technician_id', $technicianId)
                ->pluck('id'))
            ->merge(ServiceSubTask::query()
                ->where('technician_id', $technicianId)
                ->pluck('service_request_id'))
            ->merge(JobAssignment::query()
                ->where('technician_id', $technicianId)
                ->pluck('service_request_id'))
            ->merge(TechnicianPayment::query()
                ->where('technician_id', $technicianId)
                ->pluck('service_request_id'))
            ->merge(TechnicianPaymentEntry::query()
                ->where('technician_id', $technicianId)
                ->pluck('service_request_id'))
            ->filter()
            ->unique()
            ->values();

        $serviceRequests = ServiceRequest::query()
            ->whereIn('id', $serviceRequestIds)
            ->with([
                'user:id,name,email',
                'serviceCategory:id,name',
                'subTasks',
            ])
            ->get()
            ->keyBy('id');

        $assignments = JobAssignment::query()
            ->where('technician_id', $technicianId)
            ->whereIn('service_request_id', $serviceRequestIds)
            ->whereIn('status', [
                JobAssignment::STATUS_PENDING,
                JobAssignment::STATUS_ACCEPTED,
                JobAssignment::STATUS_COMPLETED,
            ])
            ->get()
            ->groupBy('service_request_id');

        $payments = TechnicianPayment::query()
            ->where('technician_id', $technicianId)
            ->whereIn('service_request_id', $serviceRequestIds)
            ->where('category', 'labor')
            ->where('status', 'completed')
            ->with('serviceRequest:id,request_id,job_reference')
            ->orderByDesc('paid_at')
            ->get();

        $sheetEntries = TechnicianPaymentEntry::query()
            ->where('technician_id', $technicianId)
            ->whereIn('service_request_id', $serviceRequestIds)
            ->whereIn('status', [TechnicianPaymentEntry::STATUS_APPROVED, TechnicianPaymentEntry::STATUS_PAID])
            ->with([
                'serviceRequest:id,request_id,job_reference',
                'paymentSheet:id,sheet_reference,period_start,period_end',
            ])
            ->orderByDesc('id')
            ->get();

        $periodDirectPayments = $payments->filter(function ($payment) use ($from, $to) {
            if (!$from && !$to) {
                return true;
            }

            $paidAt = $payment->paid_at;
            if (!$paidAt) {
                return false;
            }

            if ($from && $paidAt->lt($from->copy()->startOfDay())) {
                return false;
            }

            if ($to && $paidAt->gt($to->copy()->endOfDay())) {
                return false;
            }

            return true;
        });

        $periodSheetEntries = $sheetEntries->filter(function ($entry) use ($from, $to) {
            if (!$from && !$to) {
                return true;
            }

            $periodEnd = $entry->paymentSheet?->period_end
                ? Carbon::parse($entry->paymentSheet->period_end)
                : null;

            if (!$periodEnd) {
                return false;
            }

            if ($from && $periodEnd->lt($from->copy()->startOfDay())) {
                return false;
            }

            if ($to && $periodEnd->gt($to->copy()->endOfDay())) {
                return false;
            }

            return true;
        });

        $jobs = $serviceRequestIds->map(function ($serviceRequestId) use ($serviceRequests, $assignments, $payments, $sheetEntries, $technicianId) {
            $serviceRequest = $serviceRequests->get($serviceRequestId);
            if (!$serviceRequest) {
                return null;
            }

            $jobAssignments = $assignments->get($serviceRequestId, collect());
            $jobDirectPayments = $payments->where('service_request_id', $serviceRequestId);
            $jobSheetEntries = $sheetEntries->where('service_request_id', $serviceRequestId);
            $latestSheetEntry = $jobSheetEntries->first();

            $agreedFromAssignments = (float) $jobAssignments->sum(function ($assignment) {
                return (float) ($assignment->agreed_compensation ?? 0);
            });

            $agreedFromSubTasks = (float) $serviceRequest->subTasks
                ->where('technician_id', $technicianId)
                ->sum(function ($subTask) {
                    return (float) ($subTask->agreed_compensation ?? 0);
                });

            $agreedCompensation = $agreedFromAssignments > 0
                ? $agreedFromAssignments
                : ($agreedFromSubTasks > 0
                    ? $agreedFromSubTasks
                    : (float) ($latestSheetEntry?->agreed_compensation ?? 0));

            $directPaid = (float) $jobDirectPayments->sum(function ($payment) {
                return (float) ($payment->amount ?? 0);
            });

            $sheetPaid = (float) $jobSheetEntries->sum(function ($entry) {
                return (float) ($entry->current_period_payable ?? 0);
            });

            $paidToDate = $directPaid + $sheetPaid;
            $outstanding = max($agreedCompensation - $paidToDate, 0);
            $overpaid = max($paidToDate - $agreedCompensation, 0);

            $history = $jobDirectPayments->map(function ($payment) {
                return [
                    'type' => 'direct_payment',
                    'label' => 'Direct payout',
                    'amount' => (float) ($payment->amount ?? 0),
                    'date' => $payment->paid_at?->toDateString(),
                    'status' => $payment->status,
                    'reference' => $payment->payment_id,
                    'method' => $payment->payment_method,
                    'notes' => $payment->notes,
                ];
            })->merge($jobSheetEntries->map(function ($entry) {
                return [
                    'type' => 'payment_sheet',
                    'label' => 'Payment sheet',
                    'amount' => (float) ($entry->current_period_payable ?? 0),
                    'date' => $entry->paymentSheet?->period_end,
                    'status' => $entry->status,
                    'reference' => $entry->paymentSheet?->sheet_reference,
                    'method' => 'payment_sheet',
                    'notes' => $entry->paymentSheet
                        ? 'Coverage: ' . $entry->paymentSheet->period_start . ' to ' . $entry->paymentSheet->period_end
                        : null,
                ];
            }))
                ->sortByDesc('date')
                ->values()
                ->all();

            return [
                'service_request_id' => $serviceRequest->id,
                'job_reference' => $serviceRequest->job_reference ?? $serviceRequest->request_id ?? 'N/A',
                'request_id' => $serviceRequest->request_id,
                'service_name' => $serviceRequest->serviceCategory->name ?? 'N/A',
                'client_name' => $serviceRequest->user->name ?? 'Client',
                'status' => $serviceRequest->status,
                'agreed_compensation' => (float) $agreedCompensation,
                'paid_to_date' => (float) $paidToDate,
                'direct_paid' => (float) $directPaid,
                'sheet_paid' => (float) $sheetPaid,
                'outstanding_balance' => (float) $outstanding,
                'overpaid_balance' => (float) $overpaid,
                'latest_cumulative_due' => (float) ($latestSheetEntry?->cumulative_amount_due ?? 0),
                'latest_progress_pct' => (int) ($latestSheetEntry?->cumulative_progress_pct ?? 0),
                'latest_period_payable' => (float) ($latestSheetEntry?->current_period_payable ?? 0),
                'last_payment_date' => collect($history)->pluck('date')->filter()->sortDesc()->first(),
                'history' => $history,
            ];
        })
            ->filter()
            ->sort(function ($left, $right) {
                return [$right['outstanding_balance'], $right['paid_to_date'], $right['job_reference']]
                    <=> [$left['outstanding_balance'], $left['paid_to_date'], $left['job_reference']];
            })
            ->values();

        $paymentHistory = $periodDirectPayments->map(function ($payment) {
            $serviceRequest = $payment->serviceRequest;

            return [
                'type' => 'direct_payment',
                'service_request_id' => $payment->service_request_id,
                'job_reference' => $serviceRequest?->job_reference ?? $serviceRequest?->request_id ?? 'N/A',
                'amount' => (float) ($payment->amount ?? 0),
                'date' => $payment->paid_at?->toDateString(),
                'status' => $payment->status,
                'reference' => $payment->payment_id,
                'method' => $payment->payment_method,
                'notes' => $payment->notes,
            ];
        })->merge($periodSheetEntries->map(function ($entry) {
            $serviceRequest = $entry->serviceRequest;

            return [
                'type' => 'payment_sheet',
                'service_request_id' => $entry->service_request_id,
                'job_reference' => $serviceRequest?->job_reference ?? $serviceRequest?->request_id ?? 'N/A',
                'amount' => (float) ($entry->current_period_payable ?? 0),
                'date' => $entry->paymentSheet?->period_end,
                'status' => $entry->status,
                'reference' => $entry->paymentSheet?->sheet_reference,
                'method' => 'payment_sheet',
                'notes' => $entry->paymentSheet
                    ? 'Coverage: ' . $entry->paymentSheet->period_start . ' to ' . $entry->paymentSheet->period_end
                    : null,
            ];
        }))
            ->sortByDesc('date')
            ->values();

        $totalAgreed = (float) $jobs->sum('agreed_compensation');
        $totalPaid = (float) $jobs->sum('paid_to_date');
        $totalOutstanding = (float) $jobs->sum('outstanding_balance');

        return [
            'total_earned' => $totalPaid,
            'total_paid' => $totalPaid,
            'total_agreed' => $totalAgreed,
            'total_outstanding' => $totalOutstanding,
            'job_count' => $jobs->count(),
            'paid_in_period' => (float) $paymentHistory->sum('amount'),
            'entries' => $paymentHistory,
            'payment_history' => $paymentHistory,
            'by_job' => $jobs,
        ];
    }

    protected function buildRevenueSnapshot(Carbon $from, Carbon $to, ?int $pmId = null, ?int $clientId = null): array
    {
        $serviceRequests = $this->getRevenueScopedServiceRequests($from, $to, $pmId, $clientId);
        $rfqRows = $this->buildRfqRevenueRows($serviceRequests, $from, $to);

        return [
            'rfq_rows' => $rfqRows,
            'client_rows' => $this->buildClientRevenueRows($rfqRows),
        ];
    }

    protected function getRevenueScopedServiceRequests(Carbon $from, Carbon $to, ?int $pmId = null, ?int $clientId = null): Collection
    {
        $query = ServiceRequest::query()
            ->with([
                'user:id,name,email',
                'quotations' => function ($query) {
                    $query->select('id', 'service_request_id', 'version', 'status', 'grand_total', 'approved_at')
                        ->orderBy('version', 'desc');
                },
                'payments' => function ($query) {
                    $query->select('id', 'service_request_id', 'amount', 'status', 'paid_at')
                        ->where('status', Payment::STATUS_COMPLETED)
                        ->orderBy('paid_at', 'desc');
                },
            ])
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('created_at', [$from, $to])
                    ->orWhereHas('quotations', function ($quotationQuery) use ($from, $to) {
                        $quotationQuery->where('status', Quotation::STATUS_APPROVED)
                            ->whereBetween('approved_at', [$from, $to]);
                    })
                    ->orWhereHas('payments', function ($paymentQuery) use ($from, $to) {
                        $paymentQuery->where('status', Payment::STATUS_COMPLETED)
                            ->whereBetween('paid_at', [$from, $to]);
                    });
            })
            ->orderByDesc('created_at');

        if ($pmId) {
            $query->forPm($pmId);
        }

        if ($clientId) {
            $query->where('user_id', $clientId);
        }

        return $query->get();
    }

    protected function buildRfqRevenueRows(Collection $serviceRequests, Carbon $from, Carbon $to): Collection
    {
        return $serviceRequests
            ->map(function (ServiceRequest $serviceRequest) use ($from, $to) {
                $latestApprovedQuotation = $serviceRequest->quotations->first(function ($quotation) {
                    return $quotation->status === Quotation::STATUS_APPROVED;
                });

                $grossQuotedAmount = $this->resolveGrossQuotedAmount($serviceRequest, $latestApprovedQuotation);
                $paymentsInPeriod = $serviceRequest->payments->filter(function ($payment) use ($from, $to) {
                    return $payment->paid_at && $payment->paid_at->betweenIncluded($from, $to);
                });
                $totalCollected = (float) $serviceRequest->payments->sum('amount');
                $collectedInPeriod = (float) $paymentsInPeriod->sum('amount');

                return [
                    'service_request_id' => $serviceRequest->id,
                    'request_id' => $serviceRequest->request_id,
                    'job_reference' => $serviceRequest->job_reference ?? $serviceRequest->request_id,
                    'client_id' => $serviceRequest->user_id,
                    'client_name' => $serviceRequest->user->name ?? 'N/A',
                    'client_email' => $serviceRequest->user->email ?? null,
                    'status' => $serviceRequest->status,
                    'gross_quoted_amount' => $grossQuotedAmount,
                    'collected_in_period' => $collectedInPeriod,
                    'total_collected' => $totalCollected,
                    'outstanding_total' => max($grossQuotedAmount - $totalCollected, 0),
                    'payment_count_in_period' => $paymentsInPeriod->count(),
                    'total_payment_count' => $serviceRequest->payments->count(),
                    'latest_payment_date' => optional($serviceRequest->payments->first()?->paid_at)->toDateString(),
                    'approved_quote_date' => optional($latestApprovedQuotation?->approved_at)->toDateString(),
                    'quote_version' => $latestApprovedQuotation?->version,
                    'is_amended' => (int) ($latestApprovedQuotation?->version ?? 1) > 1,
                    'quote_label' => $this->resolveQuoteLabel($latestApprovedQuotation),
                ];
            })
            ->sort(function ($left, $right) {
                return [$right['collected_in_period'], $right['gross_quoted_amount']]
                    <=> [$left['collected_in_period'], $left['gross_quoted_amount']];
            })
            ->values();
    }

    protected function buildClientRevenueRows(Collection $rfqRows): Collection
    {
        return $rfqRows
            ->groupBy('client_id')
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $rfqCount = $rows->count();
                $grossQuotedAmount = (float) $rows->sum('gross_quoted_amount');
                $collectedInPeriod = (float) $rows->sum('collected_in_period');
                $totalCollected = (float) $rows->sum('total_collected');

                return [
                    'client_id' => $first['client_id'],
                    'client_name' => $first['client_name'],
                    'client_email' => $first['client_email'],
                    'rfq_count' => $rfqCount,
                    'gross_quoted_amount' => $grossQuotedAmount,
                    'collected_in_period' => $collectedInPeriod,
                    'total_collected' => $totalCollected,
                    'outstanding_total' => max($grossQuotedAmount - $totalCollected, 0),
                    'payment_count_in_period' => (int) $rows->sum('payment_count_in_period'),
                    'average_rfq_value' => $rfqCount > 0 ? round($grossQuotedAmount / $rfqCount, 2) : 0,
                    'latest_payment_date' => $rows->pluck('latest_payment_date')->filter()->sortDesc()->first(),
                    'collection_rate' => $grossQuotedAmount > 0
                        ? round(($collectedInPeriod / $grossQuotedAmount) * 100, 1)
                        : 0,
                ];
            })
            ->sort(function ($left, $right) {
                return [$right['collected_in_period'], $right['gross_quoted_amount']]
                    <=> [$left['collected_in_period'], $left['gross_quoted_amount']];
            })
            ->values();
    }

    protected function resolveGrossQuotedAmount(ServiceRequest $serviceRequest, ?Quotation $latestApprovedQuotation): float
    {
        foreach ([
            $latestApprovedQuotation?->grand_total,
            $serviceRequest->final_amount,
            $serviceRequest->quoted_amount,
            $serviceRequest->quote_amount,
        ] as $amount) {
            if ($amount !== null) {
                return (float) $amount;
            }
        }

        return 0.0;
    }

    protected function resolveQuoteLabel(?Quotation $latestApprovedQuotation): string
    {
        if (! $latestApprovedQuotation) {
            return 'Pending quote';
        }

        return $latestApprovedQuotation->version > 1
            ? "Amended v{$latestApprovedQuotation->version}"
            : 'Original quote';
    }
}
