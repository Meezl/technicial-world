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
            background-color: #007bff;
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
            background-color: #28a745;
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
            <h2>New Job Assignment</h2>
        </div>

        <div class="content">
            <p>Hello,</p>
            <p>You have been assigned a new service request. Please find the details below:</p>

            <table class="details-table">
                <tr>
                    <th>Job ID</th>
                    <td>{{ $serviceRequest->request_id }}</td>
                </tr>
                <tr>
                    <th>Service Category</th>
                    <td>{{ $serviceRequest->serviceCategory->name ?? 'General' }}</td>
                </tr>
                <tr>
                    <th>Client Name</th>
                    <td>{{ $clientName }}</td>
                </tr>
                <tr>
                    <th>Phone</th>
                    <td><a href="tel:{{ $clientPhone }}">{{ $clientPhone ?? 'N/A' }}</a></td>
                </tr>
                <tr>
                    <th>Location</th>
                    <td>{{ $location }}</td>
                </tr>
                <tr>
                    <th>Urgency</th>
                    <td><span
                            style="text-transform: capitalize; color: {{ $urgency === 'high' ? 'red' : 'inherit' }}; font-weight: bold;">{{ $urgency }}</span>
                    </td>
                </tr>
                <tr>
                    <th>Description</th>
                    <td>{{ $description }}</td>
                </tr>
            </table>

            <p>Please log in to your dashboard to view full details and manage this job.</p>

            <div style="text-align: center;">
                <a href="{{ route('login') }}" class="btn">Login to Dashboard</a>
            </div>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} Technician World. All rights reserved.</p>
        </div>
    </div>
</body>

</html>