<?php

namespace App\Http\Controllers;

use App\Mail\TicketAcknowledgement;
use App\Mail\TicketCreatedNotification;
use App\Models\ServiceCategory;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Validation\Rule;
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
        return Inertia::render('Public/CreateTicket', [
            'categories' => self::publicCategories(),
        ]);
    }

    /**
     * The public quote form. Same questions, same categories as a ticket.
     */
    public function contact()
    {
        return Inertia::render('Contact', [
            'categories' => self::publicCategories(),
        ]);
    }

    /**
     * The trades the office actually sells, for the public forms to offer.
     *
     * Both forms had a hardcoded handful — electrical, plumbing, other — while
     * the database carried a dozen. A caller with a roof problem had to file it
     * as "other", which tells the office nothing and cannot be routed to a
     * roofer. Read from service_categories so adding a trade there is all it
     * takes for the site to offer it.
     */
    public static function publicCategories(): array
    {
        return ServiceCategory::where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * What a submission from either public form has to carry.
     *
     * One definition, because the quote form and the ticket form ask the same
     * questions — that was the point of making them uniform. The category must
     * be one the office sells; the legacy slugs are deliberately not accepted
     * from new submissions, though old tickets keep theirs and still display.
     */
    private static function publicSubmissionRules(): array
    {
        return [
            'filer_name'  => 'required|string|max:120',
            'filer_email' => 'required|email|max:150',
            'filer_phone' => 'nullable|string|max:30',
            'category'    => ['required', 'string', Rule::in(self::publicCategories())],
            'urgency'     => 'required|in:emergency,urgent,normal',
            'location'    => 'nullable|string|max:200',
            'subject'     => 'required|string|max:200',
            'description' => 'required|string|min:10|max:5000',
        ];
    }

    /**
     * Store a new ticket from either a guest or a logged-in user.
     */
    public function store(Request $request)
    {
        $ticket = $this->fileTicket(
            $request,
            $request->validate(self::publicSubmissionRules()),
            Ticket::TYPE_SUPPORT
        );

        // Different post-submit destination depending on auth state
        if ($request->user()) {
            return redirect()->route('client.tickets.show', $ticket)
                ->with('success', "Ticket {$ticket->ticket_ref} created. We've emailed you a confirmation.");
        }

        return redirect()->route('tickets.create')
            ->with('success', "Ticket {$ticket->ticket_ref} created. We've emailed a confirmation to {$ticket->filer_email}.");
    }

    /**
     * A quote request from the public contact form.
     *
     * The form it replaces posted nowhere: it logged the submission to the
     * browser console and told the sender "we will get back to you soon".
     * Every enquiry made through that page was lost. It files a ticket now —
     * typed as an enquiry, not a fault — so it reaches the same queue, the
     * same office inbox and the same acknowledgement as anything else somebody
     * takes the trouble to send us.
     */
    public function storeEnquiry(Request $request)
    {
        $ticket = $this->fileTicket(
            $request,
            $request->validate(self::publicSubmissionRules()),
            Ticket::TYPE_ENQUIRY
        );

        if ($request->user()) {
            return redirect()->route('client.tickets.show', $ticket)
                ->with('success', "Request {$ticket->ticket_ref} received. We've emailed you a confirmation.");
        }

        return redirect()->route('contact')
            ->with('success', "Request {$ticket->ticket_ref} received. We've emailed a confirmation to {$ticket->filer_email} and the team will be in touch.");
    }

    /**
     * File a ticket and tell everyone who needs to know.
     *
     * Shared by the support form and the quote form: one record, one status
     * log, one note to the office, one acknowledgement to the sender. The two
     * differ only in type and in where the sender lands afterwards.
     *
     * Mail failures are logged and swallowed deliberately — a mail server
     * having a bad minute must not lose the submission, which is the whole
     * problem this is fixing.
     */
    private function fileTicket(Request $request, array $data, string $type): Ticket
    {
        $ticket = Ticket::create($data + [
            'ticket_ref' => Ticket::generateRef(),
            'user_id'    => $request->user()?->id,
            'status'     => Ticket::STATUS_OPEN,
            'type'       => $type,
        ]);

        $ticket->statusLogs()->create([
            'from_status' => null,
            'to_status'   => Ticket::STATUS_OPEN,
            'changed_by'  => $request->user()?->id,
            'note'        => $type === Ticket::TYPE_ENQUIRY
                ? 'Quote request filed from the website.'
                : 'Ticket filed.',
        ]);

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

        try {
            Mail::to($ticket->filer_email)->send(new TicketAcknowledgement($ticket));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TicketAcknowledgement email failed', [
                'ticket_id' => $ticket->id,
                'error'     => $e->getMessage(),
            ]);
        }

        return $ticket;
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
