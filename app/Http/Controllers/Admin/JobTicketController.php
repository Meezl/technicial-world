<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketFeeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Tickets raised under an existing job — a site visit, sample panels, a
 * call-back — and the attendance fee that goes with them.
 *
 * Exists because billable activity inside a job previously had nowhere to
 * live: the only way to bank a sample fee was to raise a second REQ for work
 * that belonged to the first.
 *
 * Fees here are entered by hand. The callout fee matrix governs standalone
 * callouts, not attendance inside a job already under way.
 */
class JobTicketController extends Controller
{
    public function __construct(private TicketFeeService $fees)
    {
    }

    /**
     * Raise a ticket against a job, on the client's behalf.
     */
    public function store(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'subject'     => 'required|string|max:200',
            'description' => 'required|string|min:5|max:5000',
            'category'    => ['required', Rule::in([
                Ticket::CATEGORY_ELECTRICAL, Ticket::CATEGORY_PLUMBING, Ticket::CATEGORY_OTHER,
            ])],
            'urgency'     => ['required', Rule::in([
                Ticket::URGENCY_EMERGENCY, Ticket::URGENCY_URGENT, Ticket::URGENCY_NORMAL,
            ])],
            'location'    => 'nullable|string|max:200',

            'charge_type' => ['required', Rule::in([
                Ticket::CHARGE_CHARGEABLE, Ticket::CHARGE_INCLUDED,
                Ticket::CHARGE_WAIVED, Ticket::CHARGE_WARRANTY,
            ])],
            // Only meaningful when the ticket is chargeable.
            'fee_amount'    => 'required_if:charge_type,chargeable|nullable|numeric|min:1|max:1000000',
            'charge_reason' => 'required_unless:charge_type,chargeable|nullable|string|max:1000',

            // Bill immediately, or leave it to be raised once scoped.
            'bill_now'    => 'nullable|boolean',
        ]);

        $actor = $request->user();
        $client = $serviceRequest->user;

        if (!$client) {
            return back()->withErrors([
                'subject' => 'This job has no client account attached, so a ticket cannot be billed to anyone.',
            ]);
        }

        $isZeroCharge = $data['charge_type'] !== Ticket::CHARGE_CHARGEABLE;

        // A waiver is an admin decision. Included and warranty are
        // classifications rather than write-offs, so a PM may set those.
        if (in_array($data['charge_type'], TicketFeeService::ADMIN_ONLY_CHARGE_TYPES, true)
            && $actor->role !== User::ROLE_ADMIN) {
            return back()->withErrors([
                'charge_type' => 'Only an admin may waive an attendance fee.',
            ]);
        }

        $ticket = Ticket::create([
            'ticket_ref'         => Ticket::generateRef(),
            'user_id'            => $client->id,
            'service_request_id' => $serviceRequest->id,
            'filer_name'         => $client->name,
            'filer_email'        => $client->email,
            'filer_phone'        => $client->phone,
            'category'           => $data['category'],
            'urgency'            => $data['urgency'],
            'location'           => $data['location'] ?? $serviceRequest->location,
            'subject'            => $data['subject'],
            'description'        => $data['description'],
            'status'             => Ticket::STATUS_OPEN,
            'type'               => Ticket::TYPE_CALLOUT,
            'fee_amount'         => $isZeroCharge ? 0 : $data['fee_amount'],
            'charge_type'        => $data['charge_type'],
            'charge_reason'      => $data['charge_reason'] ?? null,
            'fee_authorised_by'  => $isZeroCharge ? $actor->id : null,
            'fee_authorised_at'  => $isZeroCharge ? now() : null,
            'created_by'         => $actor->id,
        ]);

        $ticket->statusLogs()->create([
            'from_status' => null,
            'to_status'   => Ticket::STATUS_OPEN,
            'changed_by'  => $actor->id,
            'note'        => "Raised under {$serviceRequest->request_id} by {$actor->name}.",
        ]);

        if ($request->boolean('bill_now') && $ticket->isChargeable()) {
            try {
                $this->fees->raiseFee($ticket, $actor->id);
            } catch (RuntimeException $e) {
                return back()->withErrors(['fee_amount' => $e->getMessage()]);
            }
        }

        return back()->with('success', "Ticket {$ticket->ticket_ref} raised under {$serviceRequest->request_id}.");
    }

    /**
     * Bill the attendance fee for a ticket that was raised without one.
     */
    public function raiseFee(Request $request, Ticket $ticket)
    {
        try {
            $paymentRequest = $this->fees->raiseFee($ticket, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['fee' => $e->getMessage()]);
        }

        if (!$paymentRequest) {
            return back()->withErrors([
                'fee' => 'This ticket is not chargeable, so there is nothing to bill.',
            ]);
        }

        return back()->with(
            'success',
            'Attendance fee of KES ' . number_format((float) $paymentRequest->amount, 2) . ' requested.'
        );
    }

    /**
     * Mark a ticket as not chargeable — included in the quoted work, a
     * warranty return, or a deliberate write-off.
     */
    public function zeroCharge(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'charge_type' => ['required', Rule::in(Ticket::ZERO_CHARGE_TYPES)],
            'reason'      => 'required|string|max:1000',
        ]);

        try {
            $this->fees->setZeroCharge($ticket, $data['charge_type'], $data['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['charge_type' => $e->getMessage()]);
        }

        return back()->with('success', 'Ticket marked as ' . $ticket->fresh()->chargeTypeLabel() . '.');
    }
}
