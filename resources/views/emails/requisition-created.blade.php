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

        .requisition-card {
            background: #f8f9fa;
            border-left: 4px solid #f97316;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .items-table th,
        .items-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .items-table th {
            background: #f1f5f9;
            color: #053272;
            font-size: 12px;
            text-transform: uppercase;
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

        .alert {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 12px;
            border-radius: 6px;
            margin: 20px 0;
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
            <p>A new requisition has been submitted by <strong>{{ $creatorName }}</strong> and requires your review.</p>

            <div class="requisition-card">
                <h3 style="margin: 0 0 10px; color: #053272;">{{ $requisition->project->name }}</h3>
                @if($requisition->description)
                    <p style="color: #666; margin: 0;">{{ $requisition->description }}</p>
                @endif
            </div>

            <div class="alert">
                <strong>Action Required:</strong> Please review and approve/reject the items below.
            </div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Unit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requisition->items as $item)
                        <tr>
                            <td>{{ $item->name }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td>{{ $item->unit }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <a href="{{ url('/admin/requisitions') }}" class="btn">Review Requisition</a>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} Technician World. All rights reserved.</p>
        </div>
    </div>
</body>

</html>