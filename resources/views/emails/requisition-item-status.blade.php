<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: linear-gradient(135deg, #00334b 0%, #053272 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .content {
            padding: 30px;
        }

        .item-card {
            background: #f8f9fa;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
        }

        .status-approved {
            background: #dcfce7;
            color: #16a34a;
            border-left: 4px solid #16a34a;
        }

        .status-rejected {
            background: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
        }

        .status-procured,
        .status-awaiting_payment {
            background: #dbeafe;
            color: #2563eb;
            border-left: 4px solid #2563eb;
        }

        .status-paid {
            background: #dcfce7;
            color: #16a34a;
            border-left: 4px solid #16a34a;
        }

        .status-in_transit {
            background: #fef3c7;
            color: #d97706;
            border-left: 4px solid #d97706;
        }

        .status-delivered {
            background: #e0e7ff;
            color: #4f46e5;
            border-left: 4px solid #4f46e5;
        }

        .status-acknowledged,
        .status-closed {
            background: #f3f4f6;
            color: #6b7280;
            border-left: 4px solid #6b7280;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .btn {
            display: inline-block;
            background: #053272;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }

        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 12px;
        }

        .notes {
            background: #fef3c7;
            padding: 12px;
            border-radius: 6px;
            margin-top: 15px;
            color: #92400e;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>TECHNICIAN WORLD</h1>
        </div>
        <div class="content">
            <p>Hello,</p>
            <p>An update has been made to a requisition item by <strong>{{ $actorName }}</strong>.</p>

            <div class="item-card status-{{ $item->status }}">
                <h3 style="margin: 0 0 15px; color: #053272;">{{ $item->name }}</h3>

                <div class="detail-row">
                    <span>Project:</span>
                    <strong>{{ $item->requisition->project->name ?? 'N/A' }}</strong>
                </div>
                <div class="detail-row">
                    <span>Quantity:</span>
                    <strong>{{ $item->quantity }} {{ $item->unit }}</strong>
                </div>
                <div class="detail-row">
                    <span>New Status:</span>
                    <span class="status-badge">{{ ucfirst(str_replace('_', ' ', $item->status)) }}</span>
                </div>
                @if($item->supplier_name)
                    <div class="detail-row">
                        <span>Supplier:</span>
                        <strong>{{ $item->supplier_name }}</strong>
                    </div>
                @endif
                @if($item->price)
                    <div class="detail-row">
                        <span>Price:</span>
                        <strong>{{ $item->currency }} {{ number_format($item->price, 2) }}</strong>
                    </div>
                @endif
            </div>

            @if($notes)
                <div class="notes">
                    <strong>Notes:</strong> {{ $notes }}
                </div>
            @endif

            @php
                $actionMessages = [
                    'approve' => 'This item has been approved and is ready for procurement.',
                    'reject' => 'This item has been rejected. Please see the notes above for the reason.',
                    'procure' => 'This item has been procured and is awaiting payment approval.',
                    'pay' => 'Payment has been approved. The item is ready for dispatch.',
                    'transit' => 'This item is now in transit to the site.',
                    'deliver' => 'This item has been delivered and is awaiting site acknowledgment.',
                    'acknowledge' => 'This item has been acknowledged and the requisition is now closed.',
                ];
            @endphp

            <p style="color: #666; margin-top: 20px;">
                {{ $actionMessages[$action] ?? 'The item status has been updated.' }}</p>

            <a href="{{ url('/admin/requisitions') }}" class="btn">View Requisitions</a>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} Technician World. All rights reserved.</p>
        </div>
    </div>
</body>

</html>