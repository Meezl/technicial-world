{{--
    The invoice the brief specifies.

    Required on every one of these: our PIN, our bank details, our logo, the
    REQ numbers, the requester's name, the validator's / approver's name, the
    property the work was done at, and every variation with who asked for it
    and who approved it. All of it is stamped onto the invoice at issue, so
    this template never has to reach through relations that may since have
    changed.

    One template serves both output modes. Consolidated puts every REQ on one
    form under a single total; separate repeats the form per REQ. The brief
    asks for both and the detail required is identical.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        .head { width: 100%; border-bottom: 2px solid #1e3a8a; padding-bottom: 10px; margin-bottom: 14px; }
        .head td { vertical-align: top; }
        .brand { font-size: 17px; font-weight: bold; color: #1e3a8a; }
        .muted { color: #6b7280; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: right; }
        .meta { text-align: right; font-size: 9.5px; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.grid th { background: #f3f4f6; text-align: left; padding: 5px 6px; border: 1px solid #d1d5db; font-size: 9px; text-transform: uppercase; letter-spacing: .03em; }
        table.grid td { padding: 5px 6px; border: 1px solid #e5e7eb; }
        .num { text-align: right; }
        .facts td { padding: 2px 0; font-size: 9.5px; }
        .facts .k { color: #6b7280; width: 110px; }
        .totals { width: 48%; margin-left: 52%; border-collapse: collapse; }
        .totals td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .totals .k { color: #6b7280; }
        .totals .grand td { font-weight: bold; border-top: 2px solid #1e3a8a; border-bottom: none; font-size: 11px; }
        .withheld td { color: #b45309; }
        .bank { margin-top: 16px; border: 1px solid #d1d5db; padding: 8px 10px; background: #f9fafb; }
        .section-title { font-weight: bold; margin: 14px 0 5px; font-size: 10.5px; }
        .page-break { page-break-after: always; }
        .vo { color: #1d4ed8; }
    </style>
</head>
<body>

@php
    // Consolidated: one header, every invoice's lines beneath, one total.
    // Separate: the whole form repeated per invoice.
    $groups = $consolidated ? [$invoices] : $invoices->map(fn($i) => collect([$i]));
    $issuerLogo = public_path('img/logo.png');
@endphp

@foreach ($groups as $groupIndex => $group)
    @php
        $first = $group->first();

        // On a consolidated form the client withholds against one total, so
        // the figures are the batch's own — not the per-invoice ones added
        // up. They differ by a cent, and the cent matters: this document is
        // what the client pays from, and the batch is what we reconcile the
        // bank against. The two disagreeing would put an accountant on a
        // hunt every month. See InvoicingService::batchTotals.
        $useBatch = $consolidated && $batch;

        $subtotal = $useBatch ? (float) $batch->subtotal_ex_vat : $group->sum(fn($i) => (float) $i->subtotal_ex_vat);
        $vat      = $useBatch ? (float) $batch->vat_amount      : $group->sum(fn($i) => (float) $i->vat_amount);
        $gross    = $useBatch ? (float) $batch->total_inc_vat   : $group->sum(fn($i) => (float) $i->total_inc_vat);
        $whvat    = $useBatch ? (float) $batch->whvat_amount    : $group->sum(fn($i) => (float) $i->whvat_amount);
        $wht      = $useBatch ? (float) $batch->wht_amount      : $group->sum(fn($i) => (float) $i->wht_amount);
        $net      = $useBatch ? (float) $batch->net_expected    : $group->sum(fn($i) => (float) $i->net_expected);
    @endphp

    <table class="head">
        <tr>
            <td style="width:60%;">
                @if (file_exists($issuerLogo))
                    <img src="{{ $issuerLogo }}" style="height:38px; margin-bottom:4px;" alt="">
                @endif
                <div class="brand">{{ $issuer['name'] }}</div>
                <div class="muted">{{ $issuer['address'] }}</div>
                <div class="muted">
                    {{ $issuer['email'] }}@if($issuer['phone']) &middot; {{ $issuer['phone'] }}@endif
                </div>
                <div><strong>PIN: {{ $issuer['kra_pin'] ?: '—' }}</strong></div>
            </td>
            <td>
                <div class="doc-title">
                    {{ $first->kind === 'tax_invoice' ? 'TAX INVOICE' : 'PROFORMA INVOICE' }}
                </div>
                <div class="meta">
                    @if ($consolidated && $batch)
                        <div><strong>{{ $batch->reference }}</strong></div>
                        <div class="muted">{{ $group->count() }} request(s)</div>
                    @else
                        <div><strong>{{ $first->invoice_number }}</strong></div>
                    @endif
                    <div class="muted">{{ optional($first->issued_at)->format('d M Y') }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table style="width:100%; margin-bottom:10px;"><tr>
        <td style="width:50%; vertical-align:top;">
            <div class="section-title">Billed to</div>
            <div><strong>{{ $organisation->name }}</strong></div>
            @if ($organisation->address)<div class="muted">{{ $organisation->address }}</div>@endif
            @if ($organisation->kra_pin)<div class="muted">PIN: {{ $organisation->kra_pin }}</div>@endif
        </td>
        <td style="vertical-align:top;">
            <div class="section-title">Payable by</div>
            <table class="facts">
                <tr><td class="k">Landlord PIN</td><td>{{ $first->payer_kra_pin ?: '—' }}</td></tr>
                @unless ($consolidated)
                    <tr><td class="k">LPO No.</td><td>{{ $first->lpo_number ?: '—' }}</td></tr>
                    <tr><td class="k">Property</td><td>{{ $first->property_name ?: '—' }}</td></tr>
                @endunless
            </table>
        </td>
    </tr></table>

    @foreach ($group as $invoice)
        <div class="section-title">
            {{ $invoice->serviceRequest?->request_id ?? $invoice->invoice_number }}
            @if ($invoice->property_name) &mdash; {{ $invoice->property_name }} @endif
        </div>

        <table class="facts" style="margin-bottom:5px;">
            <tr>
                <td class="k">Requested by</td><td style="width:150px;">{{ $invoice->requester_name ?: '—' }}</td>
                <td class="k">Approved by</td><td style="width:150px;">{{ $invoice->approver_name ?: '—' }}</td>
                <td class="k">Completed</td><td>{{ optional($invoice->job_completed_on)->format('d M Y') ?: '—' }}</td>
            </tr>
            @if ($consolidated)
                <tr>
                    <td class="k">LPO No.</td><td>{{ $invoice->lpo_number ?: '—' }}</td>
                    <td class="k">Invoice</td><td>{{ $invoice->invoice_number }}</td>
                    <td class="k">eTIMS</td><td>{{ $invoice->etims_receipt_number ?: '—' }}</td>
                </tr>
            @endif
        </table>

        <table class="grid">
            <thead>
                <tr>
                    <th style="width:14%;">Reference</th>
                    <th>Description</th>
                    <th style="width:15%;">Requested by</th>
                    <th style="width:15%;">Approved by</th>
                    <th style="width:14%;" class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td class="{{ $line->kind === 'variation' ? 'vo' : '' }}">{{ $line->reference ?: '—' }}</td>
                        <td>
                            @if ($line->kind === 'variation')<span class="vo">Variation:</span> @endif
                            {{ $line->description }}
                        </td>
                        <td>{{ $line->requested_by ?: '—' }}</td>
                        <td>{{ $line->approved_by ?: '—' }}</td>
                        <td class="num">{{ number_format((float) $line->amount_ex_vat, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <table class="totals">
        <tr><td class="k">Subtotal (excl. VAT)</td><td class="num">{{ number_format($subtotal, 2) }}</td></tr>
        <tr><td class="k">VAT @ {{ rtrim(rtrim(number_format((float) $first->vat_rate, 2), '0'), '.') }}%</td><td class="num">{{ number_format($vat, 2) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="num">{{ number_format($gross, 2) }}</td></tr>
        <tr class="withheld"><td class="k">Less WHVAT @ {{ rtrim(rtrim(number_format((float) $first->whvat_rate, 2), '0'), '.') }}%</td><td class="num">({{ number_format($whvat, 2) }})</td></tr>
        <tr class="withheld"><td class="k">Less WHT @ {{ rtrim(rtrim(number_format((float) $first->wht_rate, 2), '0'), '.') }}%</td><td class="num">({{ number_format($wht, 2) }})</td></tr>
        <tr class="grand"><td>Net payable</td><td class="num">{{ number_format($net, 2) }}</td></tr>
    </table>

    <div class="bank">
        <strong>Payment details</strong><br>
        Bank: {{ $issuer['bank_name'] ?: '—' }}@if($issuer['bank_branch']), {{ $issuer['bank_branch'] }}@endif<br>
        Account name: {{ $issuer['bank_account_name'] ?: '—' }}<br>
        Account number: {{ $issuer['bank_account_number'] ?: '—' }}
        @if($issuer['bank_swift'])<br>SWIFT: {{ $issuer['bank_swift'] }}@endif
        <br><span class="muted">
            Withholding certificates for the amounts deducted above should be posted to your account
            so the job can be closed as fully paid.
        </span>
    </div>

    @if (!$loop->last)<div class="page-break"></div>@endif
@endforeach

</body>
</html>
