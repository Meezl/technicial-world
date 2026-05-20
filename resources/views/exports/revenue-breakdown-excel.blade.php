<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #cbd5e1; padding: 8px; }
        th { background: #0f4c81; color: #fff; font-weight: bold; }
        .meta td { background: #f8fafc; }
        .summary td { background: #eff6ff; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    @php
        $title = $variant === 'rfq' ? 'RFQ Revenue Report' : 'Client Revenue Report';
        $rows = $report['rows'] ?? [];
        $totals = $report['totals'] ?? [];
        $period = $report['period'] ?? [];
        $formatMoney = fn ($value) => number_format((float) $value, 2);
    @endphp

    <table class="meta">
        <tr>
            <td><strong>Company</strong></td>
            <td>Technician World</td>
            <td><strong>Scope</strong></td>
            <td>{{ $scopeLabel }}</td>
        </tr>
        <tr>
            <td><strong>Report</strong></td>
            <td>{{ $title }}</td>
            <td><strong>Period</strong></td>
            <td>{{ $period['from'] ?? '' }} to {{ $period['to'] ?? '' }}</td>
        </tr>
        <tr>
            <td><strong>Generated At</strong></td>
            <td>{{ $generatedAt->format('Y-m-d H:i') }}</td>
            <td><strong>Rows</strong></td>
            <td>{{ count($rows) }}</td>
        </tr>
    </table>

    <br>

    <table class="summary">
        @if($variant === 'rfq')
            <tr>
                <td><strong>Tracked RFQs</strong></td>
                <td>{{ $totals['rfq_count'] ?? 0 }}</td>
                <td><strong>Gross Quoted</strong></td>
                <td>{{ $formatMoney($totals['total_gross_quoted'] ?? 0) }}</td>
                <td><strong>Collected in Window</strong></td>
                <td>{{ $formatMoney($totals['total_collected_in_period'] ?? 0) }}</td>
                <td><strong>Outstanding</strong></td>
                <td>{{ $formatMoney($totals['total_outstanding'] ?? 0) }}</td>
            </tr>
        @else
            <tr>
                <td><strong>Tracked Clients</strong></td>
                <td>{{ $totals['client_count'] ?? 0 }}</td>
                <td><strong>RFQ Volume</strong></td>
                <td>{{ $totals['total_rfq_count'] ?? 0 }}</td>
                <td><strong>Gross Business</strong></td>
                <td>{{ $formatMoney($totals['total_gross_quoted'] ?? 0) }}</td>
                <td><strong>Collected in Window</strong></td>
                <td>{{ $formatMoney($totals['total_collected_in_period'] ?? 0) }}</td>
            </tr>
        @endif
    </table>

    <br>

    <table>
        <thead>
            @if($variant === 'rfq')
                <tr>
                    <th>RFQ / Job</th>
                    <th>Request ID</th>
                    <th>Client</th>
                    <th>Client Email</th>
                    <th>Service</th>
                    <th>Origin</th>
                    <th>Created By</th>
                    <th>Approved By</th>
                    <th>Quote Type</th>
                    <th>Status</th>
                    <th class="text-right">Gross Quote</th>
                    <th class="text-right">Collected in Window</th>
                    <th class="text-right">Collected to Date</th>
                    <th class="text-right">Outstanding</th>
                    <th>Approved Quote Date</th>
                    <th>Latest Payment Date</th>
                </tr>
            @else
                <tr>
                    <th>Client</th>
                    <th>Client Email</th>
                    <th class="text-right">RFQ Count</th>
                    <th class="text-right">Admin Assisted</th>
                    <th class="text-right">Self Submitted</th>
                    <th class="text-right">Payment Count in Window</th>
                    <th class="text-right">Gross Business</th>
                    <th class="text-right">Collected in Window</th>
                    <th class="text-right">Collected to Date</th>
                    <th class="text-right">Outstanding</th>
                    <th class="text-right">Average RFQ Value</th>
                    <th class="text-right">Collection Rate (%)</th>
                    <th>Latest Payment Date</th>
                </tr>
            @endif
        </thead>
        <tbody>
            @forelse($rows as $row)
                @if($variant === 'rfq')
                    <tr>
                        <td>{{ $row['job_reference'] ?? '' }}</td>
                        <td>{{ $row['request_id'] ?? '' }}</td>
                        <td>{{ $row['client_name'] ?? '' }}</td>
                        <td>{{ $row['client_email'] ?? '' }}</td>
                        <td>{{ $row['service_name'] ?? '' }}</td>
                        <td>{{ $row['submission_mode_label'] ?? '' }}</td>
                        <td>{{ $row['created_by_admin_name'] ?? '' }}</td>
                        <td>{{ $row['proxy_quote_approved_by_name'] ?? ($row['quote_approval_actor'] ?? '') }}</td>
                        <td>{{ $row['quote_label'] ?? '' }}</td>
                        <td>{{ $row['status'] ?? '' }}</td>
                        <td class="text-right">{{ $formatMoney($row['gross_quoted_amount'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['collected_in_period'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['total_collected'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['outstanding_total'] ?? 0) }}</td>
                        <td>{{ $row['approved_quote_date'] ?? '' }}</td>
                        <td>{{ $row['latest_payment_date'] ?? '' }}</td>
                    </tr>
                @else
                    <tr>
                        <td>{{ $row['client_name'] ?? '' }}</td>
                        <td>{{ $row['client_email'] ?? '' }}</td>
                        <td class="text-right">{{ $row['rfq_count'] ?? 0 }}</td>
                        <td class="text-right">{{ $row['admin_assisted_rfq_count'] ?? 0 }}</td>
                        <td class="text-right">{{ $row['client_self_rfq_count'] ?? 0 }}</td>
                        <td class="text-right">{{ $row['payment_count_in_period'] ?? 0 }}</td>
                        <td class="text-right">{{ $formatMoney($row['gross_quoted_amount'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['collected_in_period'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['total_collected'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['outstanding_total'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['average_rfq_value'] ?? 0) }}</td>
                        <td class="text-right">{{ number_format((float) ($row['collection_rate'] ?? 0), 1) }}</td>
                        <td>{{ $row['latest_payment_date'] ?? '' }}</td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="12">No report rows available for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
