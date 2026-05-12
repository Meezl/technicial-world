<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Sheet - {{ $sheet->sheet_reference }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #1a56db;
            padding-bottom: 15px;
        }
        .header h1 {
            margin: 0;
            color: #1a56db;
            font-size: 22px;
        }
        .header h2 {
            margin: 5px 0 0;
            font-size: 14px;
            color: #666;
            font-weight: normal;
        }
        .meta-info {
            margin-bottom: 20px;
        }
        .meta-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-info td {
            padding: 4px 8px;
            font-size: 11px;
        }
        .meta-info .label {
            font-weight: bold;
            color: #555;
            width: 150px;
        }
        .entries-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .entries-table th {
            background-color: #1a56db;
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        .entries-table td {
            padding: 6px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 11px;
        }
        .entries-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .entries-table .amount {
            text-align: right;
            font-family: monospace;
        }
        .total-row {
            font-weight: bold;
            background-color: #e8f0fe !important;
            border-top: 2px solid #1a56db;
        }
        .footer {
            margin-top: 40px;
            font-size: 10px;
            color: #888;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .signature-section {
            margin-top: 50px;
            display: table;
            width: 100%;
        }
        .signature-block {
            display: table-cell;
            width: 45%;
            padding-top: 40px;
            border-top: 1px solid #333;
            text-align: center;
            font-size: 11px;
        }
        .signature-spacer {
            display: table-cell;
            width: 10%;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-finalized {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-draft {
            background-color: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TECHNICIAN WORLD</h1>
        <h2>Technician Weekly Payment Sheet</h2>
    </div>

    <div class="meta-info">
        <table>
            <tr>
                <td class="label">Sheet Reference:</td>
                <td>{{ $sheet->sheet_reference }}</td>
                <td class="label">Status:</td>
                <td>
                    <span class="status-badge {{ $sheet->status === 'finalized' ? 'status-finalized' : 'status-draft' }}">
                        {{ ucfirst($sheet->status) }}
                    </span>
                </td>
            </tr>
            <tr>
                <td class="label">Period:</td>
                <td>{{ $sheet->period_start->format('d M Y') }} - {{ $sheet->period_end->format('d M Y') }}</td>
                <td class="label">Created By:</td>
                <td>{{ $sheet->creator->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="label">Generated:</td>
                <td>{{ now()->format('d M Y, H:i') }}</td>
                <td class="label">Total Amount:</td>
                <td><strong>KES {{ number_format($sheet->total_amount, 2) }}</strong></td>
            </tr>
            @if($sheet->notes)
            <tr>
                <td class="label">Notes:</td>
                <td colspan="3">{{ $sheet->notes }}</td>
            </tr>
            @endif
        </table>
    </div>

    <table class="entries-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Technician</th>
                <th>Job Reference</th>
                <th class="amount">Agreed Comp.</th>
                <th>Progress %</th>
                <th class="amount">Cumulative Due</th>
                <th class="amount">Prev. Paid</th>
                <th class="amount">Current Payable</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sheet->entries as $index => $entry)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $entry->technician->user->name ?? 'N/A' }}</td>
                <td>{{ $entry->serviceRequest->job_reference ?? $entry->serviceRequest->request_id ?? 'N/A' }}</td>
                <td class="amount">{{ number_format($entry->agreed_compensation, 2) }}</td>
                <td>{{ $entry->cumulative_progress_pct }}%</td>
                <td class="amount">{{ number_format($entry->cumulative_amount_due, 2) }}</td>
                <td class="amount">{{ number_format($entry->previous_cumulative_paid, 2) }}</td>
                <td class="amount"><strong>{{ number_format($entry->current_period_payable, 2) }}</strong></td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="7" style="text-align: right; padding-right: 10px;">TOTAL PAYABLE:</td>
                <td class="amount">KES {{ number_format($sheet->entries->sum('current_period_payable'), 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="signature-section">
        <div class="signature-block">
            Prepared By (Project Manager)
        </div>
        <div class="signature-spacer"></div>
        <div class="signature-block">
            Approved By (Admin)
        </div>
    </div>

    <div class="footer">
        <p>This document was generated by Technician World Payment System. Sheet Ref: {{ $sheet->sheet_reference }}</p>
    </div>
</body>
</html>
