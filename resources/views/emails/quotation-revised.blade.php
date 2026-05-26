<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Revised Quotation - Technician World</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; }
        .header { background: #053272; color: white; padding: 20px; text-align: center; margin: -20px -20px 20px -20px; }
        .header h1 { margin: 0; font-size: 24px; }
        .revision-banner {
            background: #FEF3C7;
            border-left: 4px solid #F59E0B;
            color: #92400E;
            padding: 14px 16px;
            margin: 0 0 18px;
            border-radius: 6px;
        }
        .revision-banner strong { display: block; font-size: 15px; margin-bottom: 4px; }
        .revision-banner p { margin: 0; font-size: 13.5px; line-height: 1.5; }
        .section { margin-bottom: 22px; padding: 14px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9; }
        .section h3 { margin-top: 0; color: #053272; border-bottom: 2px solid #053272; padding-bottom: 5px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; }
        .info-item label { display: block; font-weight: bold; color: #666; font-size: 12px; text-transform: uppercase; margin-bottom: 2px; }
        .materials-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .materials-table th, .materials-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .materials-table th { background: #053272; color: white; font-weight: bold; }
        .total-section { background: #e8f4fd; padding: 15px; border-radius: 5px; margin-top: 20px; }
        .total-line { display: flex; justify-content: space-between; margin-bottom: 8px; padding: 5px 0; }
        .total-line.final { border-top: 2px solid #053272; font-weight: bold; font-size: 18px; color: #053272; margin-top: 10px; padding-top: 10px; }
        .btn { display: inline-block; padding: 12px 24px; color: white; text-decoration: none; border-radius: 5px; margin: 6px 4px; font-weight: bold; font-size: 15px; }
        .btn-success { background: #16A34A; }
        .btn-danger { background: #DC2626; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>TECHNICIAN WORLD</h1>
            <p style="margin:4px 0 0;">Revised Quotation</p>
        </div>

        <div class="revision-banner">
            <strong>📝 Revised Quotation (Revision #{{ $revisionCount }})</strong>
            <p>Please disregard the previous quotation for this request. The figures below replace it in full.</p>
        </div>

        <!-- Action buttons at the top so they don't get hidden under Gmail's "show trimmed content" -->
        <div style="margin: 0 0 22px; text-align: center;">
            <p style="margin: 0 0 10px; font-size: 15px; color: #0F172A;">
                <strong>Review the revised quotation and let us know your decision.</strong>
            </p>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto;">
                <tr>
                    <td style="padding:0 6px;">
                        <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}" class="btn btn-success">
                            ✓ Approve Revised Quote
                        </a>
                    </td>
                    <td style="padding:0 6px;">
                        <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}" class="btn btn-danger">
                            ✕ Decline
                        </a>
                    </td>
                </tr>
            </table>
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
                    <label>Revised On:</label>
                    <span>{{ optional($serviceRequest->quote_last_revised_at)->format('F j, Y') ?? now()->format('F j, Y') }}</span>
                </div>
            </div>
        </div>

        @if($materials && count($materials) > 0)
        <div class="section">
            <h3>Materials Required</h3>
            <table class="materials-table">
                <thead>
                    <tr><th>Material</th><th>Quantity</th><th>Unit Price (KSH)</th><th>Total (KSH)</th></tr>
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
            <h3>Updated Cost Breakdown</h3>
            @if($materials && count($materials) > 0)
            <div class="total-line">
                <span>Materials Total:</span>
                <span>KSH {{ number_format(collect($materials)->sum(fn($i) => $i['quantity'] * $i['unit_price']), 2) }}</span>
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
                <span>New Total:</span>
                <span>KSH {{ number_format($totalAmount ?? 0, 2) }}</span>
            </div>
            @if(!empty($downPayment) && $downPayment > 0)
            <div class="total-line" style="margin-top:12px;padding-top:12px;border-top:1px dashed #d1d5db;">
                <span style="color:#92400E;font-weight:600;">Required Down Payment:</span>
                <span style="color:#92400E;font-weight:600;">KSH {{ number_format($downPayment, 2) }}</span>
            </div>
            @endif
        </div>

        @if(!empty($mpesaPaybill))
        <div class="section" style="background:#ECFDF5;border-color:#A7F3D0;">
            <h3 style="color:#065F46;border-bottom-color:#16A34A;">Pay via M-Pesa</h3>
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
            <p style="font-size:12.5px;color:#065F46;margin:10px 0 0;">
                Use <strong>{{ $mpesaAccountRef }}</strong> as the account reference. The system will auto-match your payment to this revised quotation.
            </p>
        </div>
        @endif

        @if($notes)
        <div class="section">
            <h3>Notes</h3>
            <p>{{ $notes }}</p>
        </div>
        @endif

        <div class="footer">
            <p>Thank you for your patience. If anything is unclear, reply to this email or call us at +254 700 000 000.</p>
            <p>&copy; {{ date('Y') }} Technician World. This is an automated email — please don't reply directly.</p>
        </div>
    </div>
</body>
</html>
