<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{{ $batch->reference }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color:#111827; line-height:1.5;">
    <h2 style="margin:0 0 4px;">Proforma invoices</h2>
    <p style="margin:0 0 20px; color:#6b7280;">{{ $batch->reference }} &middot; {{ $batch->organisation?->name }}</p>

    <p style="max-width:640px;">
        Your deposit with us has reached its top-up threshold, so the invoices for the jobs
        completed and signed off since the last statement are enclosed below.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; max-width:680px; font-size:14px;">
        <tr style="background:#f3f4f6;">
            <th align="left">Request</th><th align="left">Property</th>
            <th align="left">Invoice</th><th align="right">Amount</th>
        </tr>
        @foreach ($batch->invoices as $invoice)
            <tr style="border-bottom:1px solid #e5e7eb;">
                <td>{{ $invoice->serviceRequest?->request_id ?? '—' }}</td>
                <td>{{ $invoice->property_name ?? '—' }}</td>
                <td>{{ $invoice->invoice_number }}</td>
                <td align="right">{{ number_format((float) $invoice->total_inc_vat, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <table cellpadding="5" cellspacing="0" style="margin-top:16px; border-collapse:collapse; font-size:14px;">
        <tr><td style="color:#6b7280;">Total</td><td align="right"><strong>{{ number_format((float) $batch->total_inc_vat, 2) }}</strong></td></tr>
        <tr><td style="color:#b45309;">Less WHVAT</td><td align="right" style="color:#b45309;">({{ number_format((float) $batch->whvat_amount, 2) }})</td></tr>
        <tr><td style="color:#b45309;">Less WHT</td><td align="right" style="color:#b45309;">({{ number_format((float) $batch->wht_amount, 2) }})</td></tr>
        <tr><td style="border-top:2px solid #111827;"><strong>Net payable</strong></td>
            <td align="right" style="border-top:2px solid #111827;"><strong>{{ number_format((float) $batch->net_expected, 2) }}</strong></td></tr>
    </table>

    <p style="max-width:640px; margin-top:20px;">
        You can post your proof of payment against these invoices from your portal, ticking the
        ones it covers. Please also send the withholding certificates when you have them — the
        jobs stay open on our side until those are in.
    </p>

    <p style="margin-top:28px; color:#6b7280; font-size:12px;">{{ $issuer['name'] }} &middot; PIN {{ $issuer['kra_pin'] ?: '—' }}</p>
</body></html>
