<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Finished work, filed and findable.
 *
 * Deliberately a view rather than a move. Nothing is deleted and no row
 * changes table: an archived request keeps its payments, its progress reports
 * and its variations exactly where every other part of the system expects to
 * find them. "Archive" here means "not in the working list", which is the
 * actual complaint — 114 requests, most of them done, in the same list as
 * live work.
 *
 * Folders are year then month, because that is how the office already refers
 * to past work ("the Karen job, must have been March").
 */
class ArchiveController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceRequest::query()
            ->archived()
            ->with([
                'user:id,name,email',
                'serviceCategory:id,name',
                'technician.user:id,name',
            ]);

        $this->applyFilters($query, $request);

        $perPage = in_array((int) $request->input('per_page'), [10, 15, 25, 50])
            ? (int) $request->input('per_page')
            : 15;

        $requests = $query
            ->orderByRaw('COALESCE(completed_date, client_confirmation_date, updated_at) DESC')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (ServiceRequest $sr) => [
                'id' => $sr->id,
                'request_id' => $sr->request_id,
                'job_reference' => $sr->job_reference,
                'description' => $sr->description,
                'location' => $sr->location,
                'status' => $sr->status,
                'outcome' => $sr->archiveOutcome(),
                'archived_at' => $sr->archivedAt()?->toIso8601String(),
                'quote_amount' => $sr->quote_amount,
                'final_amount' => $sr->final_amount,
                'rejection_reason' => $sr->rejection_reason,
                'client' => $sr->user?->only(['id', 'name', 'email']),
                'category' => $sr->serviceCategory?->name,
                'technician' => $sr->technician?->user?->name,
            ]);

        return Inertia::render('Admin/Archive', [
            'requests' => $requests,
            'folders' => $this->folders($request),
            'clients' => $this->clientsWithArchivedWork(),
            'stats' => $this->stats(),
            'filters' => [
                'search' => $request->input('search', ''),
                'outcome' => $request->input('outcome', 'all'),
                'year' => $request->input('year'),
                'month' => $request->input('month'),
                'client_id' => $request->input('client_id'),
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Reopen a cancelled request.
     *
     * Only cancellations, and only back to where they were. cancelRfq records
     * the prior status in the audit log, so this restores a known state rather
     * than guessing one — a request dropped back into the wrong stage would be
     * worse than one left cancelled.
     *
     * Completed and closed jobs are deliberately not reopenable here. Undoing
     * a delivered job touches billing, technician payments and the client's
     * own record, and quietly flipping a status is not that conversation.
     */
    public function reopen(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        if ($serviceRequest->status !== ServiceRequest::STATUS_CANCELLED) {
            return back()->with('error', 'Only cancelled requests can be reopened from the archive.');
        }

        $priorStatus = $this->statusBeforeCancellation($serviceRequest);

        $serviceRequest->update([
            'status' => $priorStatus,
            // The cancellation reason described a decision that has been
            // reversed; leaving it would misdescribe the request from here on.
            'rejection_reason' => null,
        ]);

        AuditLog::log(AuditLog::ACTION_STATE_CHANGED, $serviceRequest, [
            'status' => ServiceRequest::STATUS_CANCELLED,
        ], [
            'status' => $priorStatus,
            'reopened_by' => auth()->id(),
            'reason' => $request->reason,
        ]);

        return back()->with(
            'success',
            "{$serviceRequest->request_id} reopened and back in RFQ Management."
        );
    }

    private function applyFilters($query, Request $request): void
    {
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('request_id', 'like', "%{$search}%")
                    ->orWhere('job_reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $outcome = $request->input('outcome');
        if ($outcome === 'cancelled') {
            $query->where('status', ServiceRequest::STATUS_CANCELLED);
        } elseif ($outcome === 'completed') {
            $query->whereIn('status', [
                ServiceRequest::STATUS_COMPLETED,
                ServiceRequest::STATUS_CLOSED,
                ServiceRequest::STATUS_ARCHIVED,
            ]);
        }

        if ($clientId = $request->input('client_id')) {
            $query->where('user_id', (int) $clientId);
        }

        // The filing date is the same expression the folder counts and the
        // ordering use, so a request always appears in the folder it is
        // counted under.
        if ($year = $request->input('year')) {
            $query->whereRaw($this->filedYearExpression() . ' = ?', [(string) $year]);

            if ($month = $request->input('month')) {
                $query->whereRaw($this->filedMonthExpression() . ' = ?', [
                    str_pad((string) $month, 2, '0', STR_PAD_LEFT),
                ]);
            }
        }
    }

    /**
     * Year folders, each carrying its months.
     *
     * Built from the same filed-date expression as the listing so the counts
     * on the folders match what opening one shows.
     */
    private function folders(Request $request): array
    {
        $rows = ServiceRequest::query()
            ->archived()
            ->selectRaw($this->filedYearExpression() . ' as y, ' . $this->filedMonthExpression() . ' as m, COUNT(*) as total')
            ->groupByRaw($this->filedYearExpression() . ', ' . $this->filedMonthExpression())
            ->get();

        return $rows
            ->groupBy('y')
            ->map(fn ($months, $year) => [
                'year' => (string) $year,
                'total' => (int) $months->sum('total'),
                'months' => $months
                    ->sortByDesc('m')
                    ->map(fn ($row) => [
                        'month' => (string) $row->m,
                        'label' => \Carbon\Carbon::createFromDate(null, (int) $row->m, 1)->format('F'),
                        'total' => (int) $row->total,
                    ])
                    ->values()
                    ->all(),
            ])
            ->sortKeysDesc()
            ->values()
            ->all();
    }

    private function clientsWithArchivedWork()
    {
        return User::query()
            ->whereIn('id', ServiceRequest::query()->archived()->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function stats(): array
    {
        $base = fn () => ServiceRequest::query()->archived();

        return [
            'total' => $base()->count(),
            'completed' => $base()->whereIn('status', [
                ServiceRequest::STATUS_COMPLETED,
                ServiceRequest::STATUS_CLOSED,
                ServiceRequest::STATUS_ARCHIVED,
            ])->count(),
            'cancelled' => $base()->where('status', ServiceRequest::STATUS_CANCELLED)->count(),
        ];
    }

    /**
     * The status a request held before it was cancelled.
     *
     * cancelRfq stores it in the audit log's old_values, so this is a lookup
     * rather than a guess. Falls back to the start of the pipeline when the
     * cancellation predates that logging.
     */
    private function statusBeforeCancellation(ServiceRequest $serviceRequest): string
    {
        $log = AuditLog::where('auditable_type', ServiceRequest::class)
            ->where('auditable_id', $serviceRequest->id)
            ->where('action', AuditLog::ACTION_STATE_CHANGED)
            ->latest('id')
            ->get()
            ->first(fn ($row) => ($row->new_values['status'] ?? null) === ServiceRequest::STATUS_CANCELLED);

        $prior = $log->old_values['status'] ?? null;

        if (!$prior || $prior === ServiceRequest::STATUS_CANCELLED) {
            return ServiceRequest::STATUS_PENDING;
        }

        return $prior;
    }

    /** Portable across sqlite (tests) and MySQL (everywhere else). */
    private function filedYearExpression(): string
    {
        $column = 'COALESCE(completed_date, client_confirmation_date, updated_at)';

        return config('database.default') === 'sqlite'
            ? "strftime('%Y', {$column})"
            : "DATE_FORMAT({$column}, '%Y')";
    }

    private function filedMonthExpression(): string
    {
        $column = 'COALESCE(completed_date, client_confirmation_date, updated_at)';

        return config('database.default') === 'sqlite'
            ? "strftime('%m', {$column})"
            : "DATE_FORMAT({$column}, '%m')";
    }
}
