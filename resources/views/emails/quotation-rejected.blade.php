<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Service Request Update - Technician World</title>
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
            background: #DC2626;
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
            color: #DC2626;
            border-bottom: 2px solid #DC2626;
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
        .reason-box {
            background: #FEE2E2;
            border: 1px solid #FECACA;
            border-radius: 5px;
            padding: 15px;
            margin-top: 15px;
        }
        .reason-box h4 {
            color: #DC2626;
            margin-top: 0;
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
        .actions {
            text-align: center;
            margin: 25px 0;
        }
        @media (max-width: 600px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>TECHNICIAN WORLD</h1>
            <p>Service Request Update</p>
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

        <div class="section">
            <h3>Request Status</h3>
            <p>We regret to inform you that your service request has been declined at this time.</p>

            @if($rejectionReason)
            <div class="reason-box">
                <h4>Reason for Decline:</h4>
                <p>{{ $rejectionReason }}</p>
            </div>
            @endif
        </div>

        <div class="section">
            <h3>Next Steps</h3>
            <p>If you have any questions about this decision or would like to discuss alternative solutions, please don't hesitate to contact our customer service team.</p>
            <p>You may also submit a new service request with additional details or modifications if applicable.</p>
        </div>

        <div class="actions">
            <a href="{{ url('/client/new-request') }}" class="btn">
                Submit New Request
            </a>
            <a href="{{ url('/contact') }}" class="btn">
                Contact Support
            </a>
        </div>

        <div class="footer">
            <p>Thank you for considering Technician World for your service needs.</p>
            <p>For any questions, please contact us at info@technicianworld.com or call 0117 962 395</p>
            <p>This is an automated email. Please do not reply directly to this message.</p>
        </div>
    </div>
</body>
</html>