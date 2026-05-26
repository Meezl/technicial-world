<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome to Technician World</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.55; color: #1F2937; margin: 0; padding: 0; background: #F3F4F6; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
        .header { background: #053272; color: #ffffff; padding: 28px 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 6px 0 0; font-size: 14px; opacity: 0.88; }
        .body { padding: 24px; }
        .greeting { font-size: 16px; margin-bottom: 14px; }
        .creds-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-left: 4px solid #2563EB;
            border-radius: 8px;
            padding: 16px 18px;
            margin: 20px 0;
        }
        .creds-card h3 { margin: 0 0 12px; color: #053272; font-size: 15px; }
        .creds-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; border-bottom: 1px dashed #E2E8F0; }
        .creds-row:last-child { border-bottom: none; }
        .creds-label { color: #64748B; font-weight: 600; }
        .creds-value { color: #0F172A; font-family: 'Courier New', monospace; font-weight: 700; }
        .cta-row { text-align: center; margin: 22px 0 18px; }
        .cta-btn {
            display: inline-block;
            background: #2563EB;
            color: #ffffff !important;
            padding: 12px 26px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            font-size: 15px;
        }
        .warning {
            background: #FEF3C7;
            border-left: 4px solid #F59E0B;
            padding: 12px 14px;
            border-radius: 6px;
            font-size: 13px;
            color: #92400E;
            margin: 18px 0;
        }
        .info-card { background: #ECFDF5; border-left: 4px solid #16A34A; padding: 12px 14px; border-radius: 6px; font-size: 13px; color: #065F46; margin: 14px 0; }
        .footer { padding: 18px 24px; text-align: center; font-size: 12px; color: #64748B; border-top: 1px solid #E5E7EB; background: #FAFAFA; }
        ul { margin: 8px 0 0 18px; padding: 0; }
        ul li { margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Welcome to Technician World</h1>
            <p>Your technician account is ready</p>
        </div>

        <div class="body">
            <p class="greeting">Hello {{ $technicianUser->name }},</p>

            <p>You have been onboarded as a technician on Technician World. Use the credentials below to sign in for the first time.</p>

            <div class="creds-card">
                <h3>Your sign-in details</h3>
                <div class="creds-row">
                    <span class="creds-label">Technician ID</span>
                    <span class="creds-value">{{ $technician->technician_id }}</span>
                </div>
                <div class="creds-row">
                    <span class="creds-label">Email</span>
                    <span class="creds-value">{{ $technicianUser->email }}</span>
                </div>
                <div class="creds-row">
                    <span class="creds-label">Temporary Password</span>
                    <span class="creds-value">{{ $temporaryPassword }}</span>
                </div>
            </div>

            <div class="cta-row">
                <a href="{{ $loginUrl }}" class="cta-btn">Sign In to Your Account</a>
            </div>

            <div class="warning">
                <strong>For your security:</strong> change this temporary password the first time you sign in. You can update it from your Profile screen.
            </div>

            <p style="font-size: 14px; margin-top: 18px;"><strong>What you can do on the technician portal:</strong></p>
            <ul style="font-size: 14px; color: #334155;">
                <li>View jobs assigned to you and start them when ready.</li>
                <li>Submit progress reports with photos from the field.</li>
                <li>Track your earnings and payment status per job.</li>
                <li>Toggle your availability so the team knows when you can take new work.</li>
                <li>Keep your compliance documents (NCA license, ID, KRA PIN) up to date.</li>
            </ul>

            <div class="info-card">
                If you didn't expect this account, or you have any questions about your onboarding, contact the Technician World team at <strong>support@technicianworld.co.ke</strong>.
            </div>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} Technician World. All rights reserved.<br>
            This is an automated email — please don't reply directly.
        </div>
    </div>
</body>
</html>
