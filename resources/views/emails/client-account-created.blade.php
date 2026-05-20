<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Technician World Account</title>
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
            padding: 28px 20px;
            text-align: center;
            margin: -20px -20px 28px -20px;
        }
        .header h1 {
            margin: 0 0 6px;
            font-size: 22px;
        }
        .header p {
            margin: 0;
            font-size: 14px;
            opacity: 0.85;
        }
        .greeting {
            font-size: 16px;
            margin-bottom: 18px;
        }
        .section {
            margin-bottom: 24px;
            padding: 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: #f9f9f9;
        }
        .section h3 {
            margin: 0 0 12px;
            color: #053272;
            border-bottom: 2px solid #053272;
            padding-bottom: 6px;
            font-size: 15px;
        }
        .credential-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        .credential-row:last-child { border-bottom: none; }
        .credential-row span:first-child { color: #666; font-weight: bold; }
        .credential-row span:last-child  { color: #053272; font-family: monospace; font-size: 15px; }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row span:first-child { color: #666; }
        .info-row span:last-child  { font-weight: 600; color: #333; }
        .notice {
            background: #fff8e1;
            border-left: 4px solid #f9a825;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 14px;
            margin-bottom: 24px;
            color: #5d4037;
        }
        .btn-wrap { text-align: center; margin: 28px 0; }
        .btn {
            display: inline-block;
            padding: 13px 32px;
            background: #053272;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            margin-top: 32px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #888;
            font-size: 13px;
        }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h1>Technician World</h1>
        <p>Professional Technical Services</p>
    </div>

    <p class="greeting">Hello {{ $client->name }},</p>

    <p>
        A service request has been opened on your behalf by our team. We have also created a
        client account for you so you can track its progress, review quotations, and manage
        your requests going forward.
    </p>

    <!-- Login credentials -->
    <div class="section">
        <h3>Your Login Credentials</h3>
        <div class="credential-row">
            <span>Email</span>
            <span>{{ $client->email }}</span>
        </div>
        <div class="credential-row">
            <span>Temporary Password</span>
            <span>{{ $temporaryPassword }}</span>
        </div>
    </div>

    <div class="notice">
        <strong>Important:</strong> This is a temporary password. Please log in and change it
        immediately from your account settings to keep your account secure.
    </div>

    <!-- Service request summary -->
    <div class="section">
        <h3>Service Request Summary</h3>
        <div class="info-row">
            <span>Request ID</span>
            <span>{{ $serviceRequest->request_id }}</span>
        </div>
        <div class="info-row">
            <span>Service</span>
            <span>{{ $serviceRequest->serviceCategory?->name ?? 'General Service' }}</span>
        </div>
        <div class="info-row">
            <span>Location</span>
            <span>{{ $serviceRequest->location }}</span>
        </div>
        <div class="info-row">
            <span>Urgency</span>
            <span>{{ ucfirst($serviceRequest->urgency) }}</span>
        </div>
        <div class="info-row">
            <span>Status</span>
            <span>Pending Review</span>
        </div>
    </div>

    <div class="btn-wrap">
        <a href="{{ $loginUrl }}" class="btn">Log In to Your Account</a>
    </div>

    <p style="font-size:14px; color:#555;">
        Once logged in you can track the status of your request, view and approve quotations,
        and manage payments — all from your client dashboard.
    </p>

    <div class="footer">
        <p>Technician World &mdash; Professional Technical Services</p>
        <p>If you did not expect this email or believe it was sent in error, please contact us immediately.</p>
    </div>

</div>
</body>
</html>
