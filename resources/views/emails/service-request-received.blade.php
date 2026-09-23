<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: Arial, sans-serif; line-height: 1.5; color: #333; background:#f5f5f5; padding:20px; }
    .container { max-width: 620px; margin:0 auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
    .header { background:#10b981; color:#fff; padding:20px; text-align:center; }
    .content { padding:24px; }
    .ref-box { background:#ecfdf5; border:1px dashed #6ee7b7; padding:12px 16px; border-radius:6px; text-align:center; margin:16px 0; }
    .ref-box code { font-family:'Courier New',monospace; font-size:18px; font-weight:700; color:#065f46; }
    .meta-table { width:100%; border-collapse:collapse; margin:16px 0; font-size:14px; }
    .meta-table th { text-align:left; padding:8px; background:#f9fafb; width:120px; font-weight:600; color:#6b7280; }
    .meta-table td { padding:8px; border-top:1px solid #e5e7eb; }
    .footer { padding:14px; font-size:12px; color:#6b7280; text-align:center; border-top:1px solid #eee; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h2 style="margin:0;">We've Received Your Request</h2>
    </div>

    <div class="content">
        <p>Hello {{ $serviceRequest->user->name ?? 'there' }},</p>
        <p>Thank you for choosing Technician World. Your service request has been received and our team has been alerted.</p>

        <div class="ref-box">
            Your reference: <br>
            <code>{{ $serviceRequest->request_id }}</code>
        </div>

        <table class="meta-table">
            <tr><th>Service</th><td>{{ $serviceRequest->serviceCategory->name ?? 'Not specified' }}</td></tr>
            <tr><th>Location</th><td>{{ $serviceRequest->location }}</td></tr>
            <tr><th>Urgency</th><td>{{ ucfirst($serviceRequest->urgency) }}</td></tr>
            <tr><th>Description</th><td>{{ $serviceRequest->description }}</td></tr>
        </table>

        <p><strong>What happens next?</strong></p>
        <p>Your request will be acted on within 2 hours. A member of our team will get in touch with you by email or phone.</p>

        <p>Please quote your reference number <strong>{{ $serviceRequest->request_id }}</strong> in any follow-up communication.</p>
    </div>

    <div class="footer">
        Technician World &middot; This is an automated confirmation.
    </div>
</div>
</body>
</html>
