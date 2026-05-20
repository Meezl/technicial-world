<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $variant === 'rfq' ? 'RFQ Revenue Report' : 'Client Revenue Report' }}</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
            padding: 24px;
        }
        .header {
            border-bottom: 2px solid #0f4c81;
            padding-bottom: 16px;
            margin-bottom: 18px;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            color: #0f4c81;
        }
        .header p {
            margin: 6px 0 0;
            color: #475569;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .meta td {
            padding: 6px 8px;
            border: 1px solid #dbe4ee;
        }
        .meta .label {
            width: 140px;
            font-weight: bold;
            background: #f8fafc;
        }
        .summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        .summary td {
            width: 25%;
            padding: 10px;
            border: 1px solid #dbe4ee;
            vertical-align: top;
        }
        .summary .label {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }
        .summary .value {
            display: block;
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
        }
        .section-title {
            margin: 18px 0 10px;
            font-size: 14px;
            color: #0f172a;
        }
        .leaders {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        .leaders td {
            width: 50%;
            padding: 10px;
            border: 1px solid #dbe4ee;
            vertical-align: top;
        }
        .leaders .label {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 5px;
        }
        .leaders strong {
            display: block;
            font-size: 13px;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        .report-table th {
            background: #0f4c81;
            color: #ffffff;
            text-align: left;
            font-size: 10px;
            padding: 8px 6px;
        }
        .report-table td {
            padding: 7px 6px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .report-table tr:nth-child(even) td {
            background: #f8fafc;
        }
        .text-right {
            text-align: right;
        }
        .muted {
            color: #64748b;
        }
        .footer {
            margin-top: 20px;
            font-size: 10px;
            color: #64748b;
            border-top: 1px solid #dbe4ee;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    @php
        $title = $variant === 'rfq' ? 'RFQ Revenue Report' : 'Client Revenue Report';
        $rows = $report['rows'] ?? [];
        $totals = $report['totals'] ?? [];
        $leaders = $report['leaders'] ?? [];
        $period = $report['period'] ?? [];
        $formatMoney = fn ($value) => 'KES ' . number_format((float) $value, 2);
        $formatDate = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('d M Y') : 'N/A';
        $topPrimary = $variant === 'rfq' ? ($leaders['top_collected_rfq'] ?? null) : ($leaders['top_value_client'] ?? null);
        $topSecondary = $variant === 'rfq' ? ($leaders['highest_quote_rfq'] ?? null) : ($leaders['top_volume_client'] ?? null);
    @endphp

    <div class="header">
        <h1>TECHNICIAN WORLD</h1>
        <p>{{ $scopeLabel }} - {{ $title }}</p>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Reporting Period</td>
            <td>{{ $formatDate($period['from'] ?? null) }} to {{ $formatDate($period['to'] ?? null) }}</td>
            <td class="label">Generated</td>
            <td>{{ $generatedAt->format('d M Y H:i') }}</td>
        </tr>
        <tr>
            <td class="label">Report Type</td>
            <td>{{ $title }}</td>
            <td class="label">Scope</td>
            <td>{{ $scopeLabel }}</td>
        </tr>
    </table>

    <table class="summary">
        @if($variant === 'rfq')
            <tr>
                <td>
                    <span class="label">Tracked RFQs</span>
                    <span class="value">{{ number_format((int) ($totals['rfq_count'] ?? 0)) }}</span>
                </td>
                <td>
                    <span class="label">Gross Quoted</span>
                    <span class="value">{{ $formatMoney($totals['total_gross_quoted'] ?? 0) }}</span>
                </td>
                <td>
                    <span class="label">Collected in Window</span>
                    <span class="value">{{ $formatMoney($totals['total_collected_in_period'] ?? 0) }}</span>
                </td>
                <td>
                    <span class="label">Outstanding</span>
                    <span class="value">{{ $formatMoney($totals['total_outstanding'] ?? 0) }}</span>
                </td>
            </tr>
        @else
            <tr>
                <td>
                    <span class="label">Tracked Clients</span>
                    <span class="value">{{ number_format((int) ($totals['client_count'] ?? 0)) }}</span>
                </td>
                <td>
                    <span class="label">RFQ Volume</span>
                    <span class="value">{{ number_format((int) ($totals['total_rfq_count'] ?? 0)) }}</span>
                </td>
                <td>
                    <span class="label">Gross Business</span>
                    <span class="value">{{ $formatMoney($totals['total_gross_quoted'] ?? 0) }}</span>
                </td>
                <td>
                    <span class="label">Collected in Window</span>
                    <span class="value">{{ $formatMoney($totals['total_collected_in_period'] ?? 0) }}</span>
                </td>
            </tr>
        @endif
    </table>

    <div class="section-title">Highlights</div>
    <table class="leaders">
        <tr>
            <td>
                <span class="label">{{ $variant === 'rfq' ? 'Top RFQ by Collections' : 'Top Client by Value' }}</span>
                @if($topPrimary)
                    <strong>{{ $variant === 'rfq' ? $topPrimary['job_reference'] : $topPrimary['client_name'] }}</strong>
                    <div class="muted">
                        {{ $variant === 'rfq'
                            ? (($topPrimary['client_name'] ?? 'N/A') . ' • ' . ($topPrimary['service_name'] ?? 'General service'))
                            : (($topPrimary['rfq_count'] ?? 0) . ' RFQs • ' . ($topPrimary['admin_assisted_rfq_count'] ?? 0) . ' admin-assisted') }}
                    </div>
                    <div>{{ $formatMoney($topPrimary['collected_in_period'] ?? 0) }} collected in window</div>
                @else
                    <div class="muted">No data available</div>
                @endif
            </td>
            <td>
                <span class="label">{{ $variant === 'rfq' ? 'Highest Quote RFQ' : 'Top Client by Volume' }}</span>
                @if($topSecondary)
                    <strong>{{ $variant === 'rfq' ? $topSecondary['job_reference'] : $topSecondary['client_name'] }}</strong>
                    <div class="muted">
                        {{ $variant === 'rfq'
                            ? (($topSecondary['submission_mode_label'] ?? 'Client Submitted') . ' • ' . ($topSecondary['quote_label'] ?? 'N/A'))
                            : (($topSecondary['rfq_count'] ?? 0) . ' RFQs • ' . ($topSecondary['client_self_rfq_count'] ?? 0) . ' self-submitted') }}
                    </div>
                    <div>
                        {{ $variant === 'rfq'
                            ? $formatMoney($topSecondary['gross_quoted_amount'] ?? 0) . ' gross quoted'
                            : $formatMoney($topSecondary['average_rfq_value'] ?? 0) . ' average RFQ value' }}
                    </div>
                @else
                    <div class="muted">No data available</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="section-title">Detailed Breakdown</div>
    <table class="report-table">
        <thead>
            @if($variant === 'rfq')
                <tr>
                    <th>RFQ / Job</th>
                    <th>Client</th>
                    <th>Origin / Service</th>
                    <th>Quote Type</th>
                    <th class="text-right">Gross Quote</th>
                    <th class="text-right">Collected in Window</th>
                    <th class="text-right">Collected to Date</th>
                    <th class="text-right">Outstanding</th>
                </tr>
            @else
                <tr>
                    <th>Client</th>
                    <th class="text-right">RFQs</th>
                    <th class="text-right">Assisted Mix</th>
                    <th class="text-right">Gross Business</th>
                    <th class="text-right">Collected in Window</th>
                    <th class="text-right">Collected to Date</th>
                    <th class="text-right">Outstanding</th>
                    <th class="text-right">Avg RFQ Value</th>
                </tr>
            @endif
        </thead>
        <tbody>
            @forelse($rows as $row)
                @if($variant === 'rfq')
                    <tr>
                        <td>
                            <strong>{{ $row['job_reference'] ?? 'N/A' }}</strong><br>
                            <span class="muted">{{ $row['request_id'] ?? '' }}</span>
                        </td>
                        <td>
                            {{ $row['client_name'] ?? 'N/A' }}<br>
                            <span class="muted">{{ $row['client_email'] ?? '' }}</span>
                        </td>
                        <td>
                            {{ $row['submission_mode_label'] ?? 'Client Submitted' }}<br>
                            <span class="muted">{{ $row['service_name'] ?? 'General service' }}</span><br>
                            @if(!empty($row['created_by_admin_name']))
                                <span class="muted">Created by {{ $row['created_by_admin_name'] }}</span><br>
                            @endif
                            @if(!empty($row['proxy_quote_approved_by_name']))
                                <span class="muted">Approved by {{ $row['proxy_quote_approved_by_name'] }}</span>
                            @endif
                        </td>
                        <td>{{ $row['quote_label'] ?? 'N/A' }}</td>
                        <td class="text-right">{{ $formatMoney($row['gross_quoted_amount'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['collected_in_period'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['total_collected'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['outstanding_total'] ?? 0) }}</td>
                    </tr>
                @else
                    <tr>
                        <td>
                            <strong>{{ $row['client_name'] ?? 'N/A' }}</strong><br>
                            <span class="muted">{{ $row['client_email'] ?? '' }}</span>
                        </td>
                        <td class="text-right">{{ number_format((int) ($row['rfq_count'] ?? 0)) }}</td>
                        <td class="text-right">{{ ($row['admin_assisted_rfq_count'] ?? 0) . ' / ' . ($row['client_self_rfq_count'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['gross_quoted_amount'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['collected_in_period'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['total_collected'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['outstanding_total'] ?? 0) }}</td>
                        <td class="text-right">{{ $formatMoney($row['average_rfq_value'] ?? 0) }}</td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="8">No report rows available for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Generated by Technician World reporting tools for the selected date range.
    </div>
</body>
</html>
