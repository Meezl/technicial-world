<?php

namespace App\Http\Controllers;

use App\Mail\TicketAcknowledgement;
use App\Mail\TicketCreatedNotification;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class TicketController extends Controller
{
    /**
     * Public ticket creation form — accessible at /open-ticket so
     * non-registered users can file tickets too.
     */
    public function create()
    {
        return Inertia::render('Public/CreateTicket');
    }

    /**
     * Store a new ticket from either a guest or a logged-in user.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'filer_name'  => 'required|string|max:120',
            'filer_email' => 'required|email|max:150',
            'filer_phone' => 'nullable|string|max:30',
            'category'    => 'required|in:electrical,plumbing,other',
            'urgency'     => 'required|in:emergency,urgent,normal',
            'location'    => 'nullable|string|max:200',
            'subject'     => 'required|string|max:200',
            'description' => 'required|string|min:10|max:5000',
        ]);

        $data['ticket_ref'] = Ticket::generateRef();
        $data['user_id']    = $request->user()?->id;
        $data['status']     = Ticket::STATUS_OPEN;

        $ticket = Ticket::create($data);

        // Initial status log
        $ticket->statusLogs()->create([
            'from_status' => null,
            'to_status'   => Ticket::STATUS_OPEN,
            'changed_by'  => $request->user()?->id,
            'note'        => 'Ticket filed.',
        ]);

        // Notify admin + every PM
        try {
            $recipients = User::whereIn('role', ['admin', 'project_manager'])
                ->whereNotNull('email')
                ->pluck('email')
                ->all();
            if (!empty($recipients)) {
                Mail::to($recipients)->send(new TicketCreatedNotification($ticket));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TicketCreatedNotification email failed', [
                'ticket_id' => $ticket->id,
                'error'     => $e->getMessage(),
            ]);
        }

        // Acknowledge filer
        try {
            Mail::to($ticket->filer_email)->send(new TicketAcknowledgement($ticket));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TicketAcknowledgement email failed', [
                'ticket_id' => $ticket->id,
                'error'     => $e->getMessage(),
            ]);
        }

        // Different post-submit destination depending on auth state
        if ($request->user()) {
            return redirect()->route('client.tickets.show', $ticket)
                ->with('success', "Ticket {$ticket->ticket_ref} created. We've emailed you a confirmation.");
        }

        return redirect()->route('tickets.create')
            ->with('success', "Ticket {$ticket->ticket_ref} created. We've emailed a confirmation to {$ticket->filer_email}.");
    }

    /**
     * Logged-in client viewing their own tickets list.
     */
    public function clientIndex(Request $request)
    {
        $tickets = Ticket::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('Client/Tickets/Index', [
            'tickets' => $tickets,
        ]);
    }

    /**
     * Logged-in client viewing one of their tickets.
     */
    public function clientShow(Request $request, Ticket $ticket)
    {
        if ($ticket->user_id !== $request->user()->id) {
            abort(403);
        }

        $ticket->load(['statusLogs.changedBy:id,name', 'resolver:id,name', 'closer:id,name']);

        return Inertia::render('Client/Tickets/Show', [
            'ticket' => $ticket,
        ]);
    }
}
