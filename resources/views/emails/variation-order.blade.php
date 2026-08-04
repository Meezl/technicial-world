<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $vo->vo_number }} - Technician World</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; }
        .header { background: #053272; color: white; padding: 20px; text-align: center; margin: -20px -20px 20px -20px; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: .85; }
        .scope-note {
            background: #E8F0FB; border-left: 4px solid #053272; color: #16325c;
            padding: 14px 16px; margin: 0 0 20px; border-radius: 6px; font-size: 13.5px;
        }
        .scope-note strong { display: block; margin-bottom: 4px; font-size: 15px; }
        .section { margin-bottom: 22px; padding: 14px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9; }
        .section h3 { margin-top: 0; color: #053272; border-bottom: 2px solid #053272; padding-bottom: 5px; font-size: 15px; }
        .items-table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 13.5px; }
        .items-table th, .items-table td { padding: 9px; text-align: left; border-bottom: 1px solid #ddd; }
        .items-table th { background: #053272; color: white; }
        .items-table td.num, .items-table th.num { text-align: right; white-space: nowrap; }
        .headline { text-align: center; padding: 18px; border-radius: 6px; margin-bottom: 20px; }
        .headline.add { background: #E7F6EE; border: 1px solid #16A34A; }
        .headline.deduct { background: #FDECEA; border: 1px solid #DC2626; }
        .headline label { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #555; margin-bottom: 4px; }
        .headline .amount { font-size: 30px; font-weight: bold; }
        .headline.add .amount { color: #15803D; }
        .headline.deduct .amount { color: #B91C1C; }
        .totals { background: #e8f4fd; padding: 15px; border-radius: 5px; }
        .total-line { display: flex; justify-content: space-between; margin-bottom: 8px; padding: 4px 0; font-size: 14px; }
        .total-line.final { border-top: 2px solid #053272; font-weight: bold; font-size: 17px; color: #053272; margin-top: 10px; padding-top: 10px; }
        .btn { display: inline-block; padding: 12px 24px; color: white; text-decoration: none; border-radius: 5px; margin: 6px 4px; font-weight: bold; font-size: 15px; }
        .btn-success { background: #16A34A; }
        .cta { text-align: center; margin: 24px 0; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $vo->vo_number }}</h1>
            <p>Variation to job {{ $serviceRequest->request_id }}</p>
        </div>

        {{-- The point of the whole card: this is a change, not a re-quote. --}}
        <div class="scope-note">
            <strong>This is a change to your existing job.</strong>
            <p style="margin:0">
                It is not a new quotation, and it does not replace anything you have
                already approved or paid. You are only being asked about the amount below.
            </p>
        </div>

        <div class="headline {{ $isDeduction ? 'deduct' : 'add' }}">
            <label>{{ $isDeduction ? 'Reduction to your job' : 'Additional cost' }}</label>
            <div class="amount">
                {{ $isDeduction ? '−' : '+' }} KSH {{ number_format(abs($netAmount), 2) }}
            </div>
        </div>

        <div class="section">
            <h3>Why</h3>
            <p style="margin:0">{{ $vo->reason }}</p>
            @if ($vo->additional_days)
                <p style="margin:10px 0 0; font-size:13.5px;">
                    <strong>Effect on timing:</strong>
                    this adds approximately {{ $vo->additional_days }}
                    {{ \Illuminate\Support\Str::plural('day', $vo->additional_days) }} to the programme.
                </p>
            @endif
        </div>

        @if ($items->isNotEmpty())
            <div class="section">
                <h3>Breakdown</h3>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Qty</th>
                            <th class="num">Rate</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>
                                    {{ $item->description }}<br>
                                    <span style="color:#777; font-size:12px;">{{ ucfirst($item->category) }}</span>
                                </td>
                                <td class="num">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->unit }}</td>
                                <td class="num">{{ number_format($item->unit_price, 2) }}</td>
                                <td class="num">{{ number_format($item->total_price, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="totals">
            <div class="total-line">
                <span>Your job as it stands</span>
                <span>KSH {{ number_format($currentValue, 2) }}</span>
            </div>
            @if ($settled > 0)
                <div class="total-line">
                    <span>Already paid by you</span>
                    <span>KSH {{ number_format($settled, 2) }}</span>
                </div>
            @endif
            <div class="total-line">
                <span>{{ $isDeduction ? 'This reduction' : 'This variation' }}</span>
                <span>{{ $isDeduction ? '−' : '+' }} KSH {{ number_format(abs($netAmount), 2) }}</span>
            </div>
            <div class="total-line final">
                <span>Job value if you approve</span>
                <span>KSH {{ number_format($projectedValue, 2) }}</span>
            </div>
        </div>

        <div class="cta">
            <a href="{{ $reviewUrl }}" class="btn btn-success">Review &amp; respond</a>
        </div>

        <p style="font-size:13px; color:#666; text-align:center; margin:0;">
            Approving this affects only the amount shown above. Payments you have
            already made stand, and nothing you approved earlier changes.
        </p>

        <div class="footer">
            <p>Technician World &mdash; Professional Technical Services</p>
            <p>Questions? Reply to this email and we will come back to you.</p>
        </div>
    </div>
</body>
</html>
