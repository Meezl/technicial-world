<!DOCTYPE html>
<html>

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .header {
            background-color: #28a745;
            color: white;
            padding: 10px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }

        .content {
            padding: 20px;
            background-color: white;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .details-table th,
        .details-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .details-table th {
            background-color: #f8f9fa;
            width: 30%;
        }

        .footer {
            margin-top: 20px;
            font-size: 0.9em;
            text-align: center;
            color: #666;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 15px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h2>Technician Assigned</h2>
        </div>

        <div class="content">
            <p>Hello {{ $serviceRequest->user->name }},</p>
            <p>Good news! A technician has been assigned to your service request.</p>

            <table class="details-table">
                <tr>
                    <th>Request ID</th>
                    <td>{{ $serviceRequest->request_id }}</td>
                </tr>
                <tr>
                    <th>Service Type</th>
                    <td>{{ $serviceCategory }}</td>
                </tr>
                <tr>
                    <th>Assigned Technician</th>
                    <td>{{ $technicianName }}</td>
                </tr>
                <tr>
                    <th>Technician Phone</th>
                    <td><a href="tel:{{ $technicianPhone }}">{{ $technicianPhone ?? 'N/A' }}</a></td>
                </tr>
                <tr>
                    <th>Description</th>
                    <td>{{ $description }}</td>
                </tr>
            </table>

            <p>The technician will review your request and may contact you shortly to coordinate arrival or discuss
                details.</p>

            <div style="text-align: center;">
                <a href="{{ route('login') }}" class="btn">View Request Status</a>
            </div>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} Technician World. All rights reserved.</p>
        </div>
    </div>
</body>

</html>