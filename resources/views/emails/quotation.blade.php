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
            <div class="total-line">
                <span>Labor Cost:</span>
                <span>KSH {{ number_format($laborCost ?? 0, 2) }}</span>
            </div>
            <div class="total-line final">
                <span>Total Amount:</span>
                <span>KSH {{ number_format($totalAmount ?? 0, 2) }}</span>
            </div>
        </div>

        @if($notes)
        <div class="section">
            <h3>Additional Notes</h3>
            <p>{{ $notes }}</p>
        </div>
        @endif

        <div class="actions">
            <p><strong>Please review the quotation and let us know your decision.</strong></p>
            <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}" class="btn btn-success">
                Approve Quotation
            </a>
            <a href="{{ url('/client/request-status/' . $serviceRequest->id) }}" class="btn btn-danger">
                Decline Quotation
            </a>
        </div>

        <div class="footer">
            <p>Thank you for choosing Technician World!</p>
            <p>For any questions, please contact us at info@technicianworld.com or call +254 700 000 000</p>
            <p>This is an automated email. Please do not reply directly to this message.</p>
        </div>
    </div>
</body>
</html>