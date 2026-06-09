<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Technician Reassignment - Technician World</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #1F2937; margin: 0; padding: 0; background: #F3F4F6; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
        .header { background: #053272; color: #ffffff; padding: 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .body { padding: 24px; }
        .change-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0;
        }
        .change-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; border-bottom: 1px dashed #E2E8F0; }
        .change-row:last-child { border-bottom: none; }
        .change-label { color: #64748B; font-weight: 600; }
        .change-value { color: #0F172A; font-weight: 700; }
        .reason-card { background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 12px 16px; border-radius: 6px; margin: 16px 0; }
        .reason-card strong { display: block; margin-bottom: 4px; color: #92400E; }
        .reason-card p { margin: 0; color: #78350F; font-size: 14px; }
        .footer { padding: 18px 24px; text-align: center; font-size: 12px; color: #64748B; border-top: 1px solid #E5E7EB; background: #FAFAFA; }
        .cta-row { text-align: center; margin: 20px 0; }
        .cta-btn { display: inline-block; background: #2563EB; color: #ffffff !important; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Technician World</h1>
            <p style="margin:6px 0 0;font-size:14px;opacity:0.88;">Technician Reassignment Notice</p>
        </div>

        <div class="body">
            <p>Dear {{ $serviceRequest->user->name ?? 'Valued client' }},</p>

            <p>We're writing to inform you that the technician assigned to your service request <strong>{{ $serviceRequest->request_id }}</strong> has been changed. The transition will be handled by our project management team to ensure work continues smoothly.</p>

            <div class="change-card">
                <h3 style="margin:0 0 12px;color:#053272;font-size:15px;">Assignment Change</h3>
                <div class="change-row">
                    <span class="change-label">Service Request</span>
                    <span class="change-value">{{ $serviceRequest->request_id }}</span>
                </div>
                @if($previousTechnician)
                <div class="change-row">
                    <span class="change-label">Previous Technician</span>
                    <span class="change-value">{{ $previousTechnician->user->name ?? 'Unknown' }}</span>
                </div>
                @endif
                <div class="change-row">
                    <span class="change-label">New Technician</span>
                    <span class="change-value">{{ $newTechnician->user->name ?? 'Unknown' }}</span>
                </div>
                @if($newTechnician->specialization)
                <div class="change-row">
                    <span class="change-label">Specialization</span>
                    <span class="change-value">{{ $newTechnician->specialization }}</span>
                </div>
                @endif
            </div>

            @if($reason)
            <div class="reason-card">
                <strong>Reason for reassignment</strong>
                <p>{{ $reason }}</p>
            </div>
            @endif

            <p>Your project timeline and the agreed scope remain unchanged. The new technician has full context and will continue from where the previous one left off.</p>

            <div class="cta-row">
                <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}" class="cta-btn">View Request Details</a>
            </div>

            <p style="font-size:14px;color:#475569;">If you have any concerns, reply to this email or call us on 0117 962 395.</p>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} Technician World. This is an automated email — please don't reply directly.
        </div>
    </div>
</body>
</html>
