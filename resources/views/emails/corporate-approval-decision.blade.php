<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{{ $serviceRequest->quote_reference }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color:#111827; line-height:1.5;">

    <h2 style="margin:0 0 4px; color: {{ $declined ? '#b91c1c' : '#15803d' }};">
        {{ $declined ? 'Quotation declined' : 'Quotation approved' }}
    </h2>
    <p style="margin:0 0 20px; color:#6b7280;">
        {{ $serviceRequest->quote_reference }} &middot; {{ $serviceRequest->organisation?->name }}
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; max-width:640px;">
        <tr><td style="color:#6b7280; width:180px;">Property</td><td><strong>{{ $serviceRequest->property?->label ?? '—' }}</strong></td></tr>
        <tr><td style="color:#6b7280;">Raised by</td><td>{{ $serviceRequest->raisedByMember?->name_on_documents ?? '—' }}</td></tr>
        <tr><td style="color:#6b7280;">Stage</td><td>{{ $approval->stageLabel() }}</td></tr>
        <tr><td style="color:#6b7280;">Decided by</td><td>{{ $approval->member?->name_on_documents ?? $approval->decidedBy?->name ?? '—' }}</td></tr>
        <tr><td style="color:#6b7280;">Quotation</td><td>KES {{ number_format((float) $serviceRequest->quote_amount, 2) }}</td></tr>
    </table>

    @if ($declined)
        <div style="margin:24px 0; padding:14px 16px; background:#fef2f2; border-left:4px solid #dc2626;">
            <p style="margin:0 0 6px; font-weight:bold;">Their comments</p>
            <p style="margin:0; white-space:pre-line;">{{ $declineComments }}</p>
        </div>
        <p style="max-width:640px;">
            The request is back with Technician World. Either the office or the assigned project
            manager can revise the quotation &mdash; the revision keeps the same reference with an
            R suffix, and re-opens the client&rsquo;s approval chain against the new figures.
        </p>
    @else
        <div style="margin:24px 0; padding:14px 16px; background:#f0fdf4; border-left:4px solid #16a34a;">
            <p style="margin:0 0 6px; font-weight:bold;">Purchase order</p>
            <table cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
                <tr><td style="color:#6b7280; width:160px;">LPO number</td><td><strong>{{ $approval->lpo_number ?? '—' }}</strong></td></tr>
                <tr><td style="color:#6b7280;">Payer KRA PIN</td><td>{{ $approval->payer_kra_pin ?? '—' }}</td></tr>
                <tr><td style="color:#6b7280;">Signed by</td><td>{{ $approval->signatory_name ?? '—' }}</td></tr>
            </table>
        </div>
        <p style="max-width:640px;">
            The job is ready for assignment. No deposit is due &mdash; this account runs against its
            standing float.
        </p>
    @endif

    @if ($approval->comments && !$declined)
        <p style="max-width:640px;"><strong>Note from the approver:</strong> {{ $approval->comments }}</p>
    @endif

    <p style="margin-top:28px; color:#6b7280; font-size:12px;">Technician World</p>
</body>
</html>
