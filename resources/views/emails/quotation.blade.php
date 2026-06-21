<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation - Technician World</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 20px;
        }
        .header {
            background: #053272;
            color: white;
            padding: 20px;
            text-align: center;
            margin: -20px -20px 20px -20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .section {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .section h3 {
            margin-top: 0;
            color: #053272;
            border-bottom: 2px solid #053272;
            padding-bottom: 5px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
        }
        .info-item label {
            font-weight: bold;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .info-item span {
            color: #333;
        }
        .materials-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .materials-table th,
        .materials-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .materials-table th {
            background: #053272;
            color: white;
            font-weight: bold;
        }
        .materials-table tr:nth-child(even) {
            background: #f2f2f2;
        }
        .total-section {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
        }
        .total-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 5px 0;
        }
        .total-line.final {
            border-top: 2px solid #053272;
            font-weight: bold;
            font-size: 18px;
            color: #053272;
            margin-top: 10px;
            padding-top: 10px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 14px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #053272;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
            font-weight: bold;
        }
        .btn:hover {
            background: #041f52;
        }
        .btn-success {
            background: #16A34A;
        }
        .btn-danger {
            background: #DC2626;
        }
        .actions {
            text-align: center;
            margin: 25px 0;
        }
        @media (max-width: 600px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            .materials-table {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>TECHNICIAN WORLD</h1>
            <p>Service Quotation</p>
        </div>

        <!-- Approve/decline actions placed at the top so they remain visible
             in Gmail and other clients that collapse long emails under a
             "quoted text" or "..." expander. The full breakdown follows. -->
        <div class="actions" style="margin:18px 0 24px;text-align:center;">
            <p style="margin:0 0 12px;font-size:15px;color:#0F172A;">
                <strong>Please review the quotation and let us know your decision.</strong>
            </p>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto;">
                <tr>
                    <td style="padding:0 6px;">
                        <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}"
                           style="display:inline-block;padding:12px 24px;background:#16A34A;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:15px;">
                            ✓ Approve Quotation
                        </a>
                    </td>
                    <td style="padding:0 6px;">
                        <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}"
                           style="display:inline-block;padding:12px 24px;background:#DC2626;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:15px;">
                            ✕ Decline Quotation
                        </a>
                    </td>
                </tr>
            </table>
            <p style="margin:14px 0 0;font-size:13px;color:#64748B;">
                Both buttons open your secure quote page where you can confirm or decline.
            </p>
        </div>

        <div class="section">
            <h3>Request Details</h3>
            <div class="info-grid">
                <div class="info-item">
                    <label>Request ID:</label>
                    <span>{{ $serviceRequest->request_id ?: 'REQ-' . $serviceRequest->id }}</span>
                </div>
                <div class="info-item">
                    <label>Client Name:</label>
                    <span>{{ $serviceRequest->user->name }}</span>
                </div>
                <div class="info-item">
                    <label>Service Category:</label>
                    <span>{{ $serviceRequest->serviceCategory->name ?? 'General Service' }}</span>
                </div>
                <div class="info-item">
                    <label>Date Requested:</label>
                    <span>{{ $serviceRequest->created_at->format('F j, Y') }}</span>
                </div>
            </div>
            @if($serviceRequest->description)
            <div class="info-item">
                <label>Description:</label>
                <p>{{ $serviceRequest->description }}</p>
            </div>
            @endif
        </div>

        @if($materials && count($materials) > 0)
        <div class="section">
            <h3>Materials Required</h3>
            <table class="materials-table">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Quantity</th>
                        <th>Unit Price (KSH)</th>
                        <th>Total (KSH)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($materials as $material)
                    <tr>
                        <td>{{ $material['name'] }}</td>
                        <td>{{ $material['quantity'] }}</td>
                        <td>{{ number_format($material['unit_price'], 2) }}</td>
                        <td>{{ number_format($material['quantity'] * $material['unit_price'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="total-section">
            <h3>Cost Breakdown</h3>
            @if($materials && count($materials) > 0)
            <div class="total-line">
                <span>Materials Total:</span>
                <span>KSH {{ number_format(collect($materials)->sum(function($item) { return $item['quantity'] * $item['unit_price']; }), 2) }}</span>
            </div>
            @endif
            @if(($transportCost ?? 0) > 0)
            <div class="total-line">
                <span>Transport:</span>
                <span>KSH {{ number_format($transportCost, 2) }}</span>
            </div>
            @endif
            <div class="total-line">
                <span>Labor Cost:</span>
                <span>KSH {{ number_format($laborCost ?? 0, 2) }}</span>
            </div>
            <div class="total-line final">
                <span>Total Amount:</span>
                <span>KSH {{ number_format($totalAmount ?? 0, 2) }}</span>
            </div>
            @if(!empty($downPayment) && $downPayment > 0)
            <div class="total-line" style="margin-top:12px;padding-top:12px;border-top:1px dashed #d1d5db;">
                <span style="color:#92400E;font-weight:600;">Required Down Payment:</span>
                <span style="color:#92400E;font-weight:600;">KSH {{ number_format($downPayment, 2) }}</span>
            </div>
            <p style="font-size:13px;color:#64748B;margin:8px 0 0;">
                A deposit of KSH {{ number_format($downPayment, 2) }} is required to proceed once you approve the quotation.
            </p>
            @endif
        </div>

        @if(!empty($mpesaPaybill))
        <div class="section" style="background:#ECFDF5;border-color:#A7F3D0;">
            <h3 style="color:#065F46;border-bottom-color:#16A34A;">Pay via M-Pesa</h3>
            <p style="margin: 4px 0 12px;font-size:14px;color:#065F46;">
                Once you approve the quotation, you can pay using M-Pesa Paybill.
            </p>
            <div style="background:#ffffff;border:1px solid #A7F3D0;border-radius:6px;padding:14px;">
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #D1FAE5;font-size:14px;">
                    <strong style="color:#065F46;">Paybill Number</strong>
                    <span style="font-family:'Courier New',monospace;font-weight:700;color:#0F172A;">{{ $mpesaPaybill }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:14px;">
                    <strong style="color:#065F46;">Account Number</strong>
                    <span style="font-family:'Courier New',monospace;font-weight:700;color:#0F172A;">{{ $mpesaAccountRef }}</span>
                </div>
            </div>
            <p style="font-size:12.5px;color:#065F46;margin:12px 0 0;">
                Use <strong>{{ $mpesaAccountRef }}</strong> as the account reference so we can automatically link your payment to this request.
            </p>
        </div>
        @endif

        @if(!empty($bank['name']))
        <div class="section" style="background:#EFF6FF;border-color:#BFDBFE;">
            <h3 style="color:#1E3A8A;border-bottom-color:#3B82F6;">Pay via Bank Transfer</h3>
            <p style="margin: 4px 0 12px;font-size:14px;color:#1E3A8A;">
                You can also settle the deposit or final balance via direct bank transfer.
            </p>
            <div style="background:#ffffff;border:1px solid #BFDBFE;border-radius:6px;padding:14px;">
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #DBEAFE;font-size:14px;">
                    <strong style="color:#1E3A8A;">Bank</strong>
                    <span style="font-weight:700;color:#0F172A;">{{ $bank['name'] }}</span>
                </div>
                @if(!empty($bank['branch']))
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #DBEAFE;font-size:14px;">
                    <strong style="color:#1E3A8A;">Branch</strong>
                    <span style="font-weight:700;color:#0F172A;">{{ $bank['branch'] }}</span>
                </div>
                @endif
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #DBEAFE;font-size:14px;">
                    <strong style="color:#1E3A8A;">Account Name</strong>
                    <span style="font-weight:700;color:#0F172A;">{{ $bank['account_name'] }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;{{ !empty($bank['swift_code']) ? 'border-bottom:1px dashed #DBEAFE;' : '' }}font-size:14px;">
                    <strong style="color:#1E3A8A;">Account Number</strong>
                    <span style="font-family:'Courier New',monospace;font-weight:700;color:#0F172A;">{{ $bank['account_number'] }}</span>
                </div>
                @if(!empty($bank['swift_code']))
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:14px;">
                    <strong style="color:#1E3A8A;">SWIFT Code</strong>
                    <span style="font-family:'Courier New',monospace;font-weight:700;color:#0F172A;">{{ $bank['swift_code'] }}</span>
                </div>
                @endif
            </div>
            <p style="font-size:12.5px;color:#1E3A8A;margin:12px 0 0;">
                Quote <strong>{{ $mpesaAccountRef }}</strong> in the transfer narration and share the bank slip with us via the portal or by replying to this email.
            </p>
        </div>
        @endif

        @if(!empty($milestones) && count($milestones) > 0)
        <div class="section">
            <h3>Payment Milestones</h3>
            <p style="font-size:13px;color:#555;margin:0 0 12px;">
                The job is billed in stages tied to delivery progress. Each milestone is invoiced once the corresponding work is validated.
            </p>
            <table class="materials-table">
                <thead>
                    <tr>
                        <th>Milestone</th>
                        <th>Progress</th>
                        <th>Amount (KSH)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($milestones as $milestone)
                    <tr>
                        <td>{{ $milestone->notes ?: 'Milestone ' . $loop->iteration }}</td>
                        <td>{{ $milestone->progress_step }}%</td>
                        <td>{{ number_format((float) $milestone->amount, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($notes)
        <div class="section">
            <h3>Additional Notes</h3>
            <p>{{ $notes }}</p>
        </div>
        @endif

        <div class="actions">
            <p><strong>Ready to proceed? Review and respond above, or use these buttons.</strong></p>
            <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}" class="btn btn-success">
                Approve Quotation
            </a>
            <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}" class="btn btn-danger">
                Decline Quotation
            </a>
        </div>

        <div class="footer">
            <p>Thank you for choosing Technician World!</p>
            <p>For any questions, please contact us at info@technicianworld.com or call 0117 962 395</p>
            <p>This is an automated email. Please do not reply directly to this message.</p>
        </div>
    </div>
</body>
</html>