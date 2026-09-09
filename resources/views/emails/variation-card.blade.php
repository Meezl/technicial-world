<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{{ $card->card_number }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color:#111827; line-height:1.5;">

    <h2 style="margin:0 0 4px;">
        {{ $toApprover ? 'Additional scope needs your decision' : 'Variation card approved' }}
    </h2>
    <p style="margin:0 0 20px; color:#6b7280;">
        {{ $card->card_number }} &middot; {{ $card->serviceRequest?->request_id }}
        @if ($card->serviceRequest?->property) &middot; {{ $card->serviceRequest->property->label }} @endif
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; max-width:640px;">
        <tr><td style="color:#6b7280; width:150px;">Raised by</td>
            <td>{{ $card->raisedByMember?->name_on_documents ?? '—' }}</td></tr>
        @if ($card->decidedByMember)
            <tr><td style="color:#6b7280;">Approved by</td><td>{{ $card->decidedByMember->name_on_documents }}</td></tr>
        @endif
    </table>

    <div style="margin:20px 0; padding:14px 16px; background:#f9fafb; border-left:4px solid #2563eb;">
        <p style="margin:0 0 6px; font-weight:bold;">Additional scope</p>
        <p style="margin:0 0 12px; white-space:pre-line;">{{ $card->scope_description }}</p>
        <p style="margin:0 0 6px; font-weight:bold;">Why it is needed</p>
        <p style="margin:0; white-space:pre-line;">{{ $card->justification }}</p>
    </div>

    @if ($card->decision_comments)
        <p><strong>Comments:</strong> {{ $card->decision_comments }}</p>
    @endif

    <p style="max-width:640px;">
        @if ($toApprover)
            Approving does not commit to a figure &mdash; Technician World will quote the additional
            scope, and that quotation comes back to you for approval in the usual way.
        @else
            The client&rsquo;s manager has agreed to this scope. Price it as a variation against the
            original job; the quotation goes back to them for approval.
        @endif
    </p>

    <p style="margin-top:28px; color:#6b7280; font-size:12px;">Technician World</p>
</body></html>
