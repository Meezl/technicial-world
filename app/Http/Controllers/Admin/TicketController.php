<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TicketStatusChanged;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $query = Ticket::query()->with('user:id,name,email');

        if ($status = $request->input('status')) {
            if (in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
                $query->where('status', $status);
            }
        }
        if ($urgency = $request->input('urgency')) {
            if (in_array($urgency, ['emergency', 'urgent', 'normal'], true)) {
                $query->where('urgency', $urgency);
            }
        }
        // Filtered against the values tickets actually carry, not a hardcoded
        // three. The public forms now file the office's real trade names, and
        // the old whitelist silently ignored every one of them — picking
        // "Roofing Services" returned the unfiltered list.
        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }
        if ($type = $request->input('type')) {
            if (in_array($type, [Ticket::TYPE_SUPPORT, Ticket::TYPE_CALLOUT, Ticket::TYPE_ENQUIRY], true)) {
                $query->where('type', $type);
            }
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_ref', 'like', "%{$search}%")
                  ->orWhere('filer_name', 'like', "%{$search}%")
                  ->orWhere('filer_email', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        // Emergency / urgent first, then newest
        $tickets = $query
            // CASE rather than MySQL's FIELD(): the same ordering, but the
            // page can be exercised by the test suite, which runs on SQLite.
            ->orderByRaw("CASE urgency WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $counts = [
            'all'         => Ticket::count(),
            'open'        => Ticket::where('status', 'open')->count(),
            'in_progress' => Ticket::where('status', 'in_progress')->count(),
            'resolved'    => Ticket::where('status', 'resolved')->count(),
            'closed'      => Ticket::where('status', 'closed')->count(),
            'emergency_open' => Ticket::where('status', '!=', 'closed')
                ->where('urgency', 'emergency')->count(),
        ];

        return Inertia::render('Admin/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $request->only(['status', 'urgency', 'category', 'type', 'search']),
            'counts'  => $counts,
            // Every category the tickets table holds, so legacy slugs stay
            // filterable alongside the trade names filed since.
            'categoryOptions' => Ticket::query()
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->all(),
        ]);
    }

    public function show(Ticket $ticket)
    {
        $ticket->load(['user:id,name,email', 'statusLogs.changedBy:id,name', 'resolver:id,name', 'closer:id,name']);

        return Inertia::render('Admin/Tickets/Show', [
            'ticket' => $ticket,
        ]);
    }

    /**
     * Transition the ticket's status. Validates the transition is legal,
     * writes a status log, updates resolver/closer fields, and emails the filer.
     */
    public function transition(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'to_status' => 'required|in:open,in_progress,resolved,closed',
            'note'      => 'nullable|string|max:2000',
            // Required when moving to resolved
            'resolution_summary' => 'nullable|string|max:5000',
        ]);

        $from = $ticket->status;
        $to   = $data['to_status'];

        if ($from === $to) {
            return back()->with('error', 'Ticket is already in that status.');
        }

        // Allowed transitions
        $allowed = [
            'open'        => ['in_progress', 'closed'],            // skip to closed for spam/no-action
            'in_progress' => ['resolved', 'closed'],
            'resolved'    => ['in_progress', 'closed'],            // back to in_progress = reopen
            'closed'      => ['in_progress'],                      // explicit reopen of closed
        ];
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            return back()->with('error', "Cannot move from {$from} to {$to}.");
        }

        if ($to === Ticket::STATUS_RESOLVED && empty($data['resolution_summary']) && empty($ticket->resolution_summary)) {
            return back()->withErrors([
                'resolution_summary' => 'A resolution summary is required when marking a ticket as resolved.',
            ])->withInput();
        }

        DB::transaction(function () use ($ticket, $from, $to, $data, $request) {
            $updates = ['status' => $to];

            if ($to === Ticket::STATUS_RESOLVED) {
                $updates['resolved_by'] = $request->user()->id;
                $updates['resolved_at'] = now();
                if (!empty($data['resolution_summary'])) {
                    $updates['resolution_summary'] = $data['resolution_summary'];
                }
            }
            if ($to === Ticket::STATUS_CLOSED) {
                $updates['closed_by'] = $request->user()->id;
                $updates['closed_at'] = now();
            }
            // Reopening clears the closed/resolved stamps so the next close is clean
            if ($to === Ticket::STATUS_IN_PROGRESS) {
                if ($from === Ticket::STATUS_RESOLVED) {
                    $updates['resolved_at'] = null;
                    $updates['resolved_by'] = null;
                }
                if ($from === Ticket::STATUS_CLOSED) {
                    $updates['closed_at'] = null;
                    $updates['closed_by'] = null;
                }
            }

            $ticket->update($updates);

            $log = $ticket->statusLogs()->create([
                'from_status' => $from,
                'to_status'   => $to,
                'changed_by'  => $request->user()->id,
                'note'        => $data['note'] ?? null,
            ]);

            // Notify the filer
            try {
                Mail::to($ticket->filer_email)->send(new TicketStatusChanged($ticket->fresh(), $log));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('TicketStatusChanged email failed', [
                    'ticket_id' => $ticket->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        });

        return back()->with('success', "Ticket marked as " . ucfirst(str_replace('_', ' ', $to)) . " and the filer has been notified.");
    }
}
