<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $refund->refund_ref }} - Technician World</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; }
        .header { background: #053272; color: white; padding: 20px; text-align: center; margin: -20px -20px 20px -20px; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: .85; }
        .headline { text-align: center; padding: 18px; border-radius: 6px; margin-bottom: 20px; background: #E7F6EE; border: 1px solid #16A34A; }
        .headline label { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #555; margin-bottom: 4px; }
        .headline .amount { font-size: 30px; font-weight: bold; color: #15803D; }
        .section { margin-bottom: 20px; padding: 14px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9; }
        .section h3 { margin-top: 0; color: #053272; border-bottom: 2px solid #053272; padding-bottom: 5px; font-size: 15px; }
        .kv { display: flex; justify-content: space-between; gap: 1rem; font-size: 13.5px; padding: 4px 0; }
        .kv span:last-child { font-weight: bold; }
        .btn { display: inline-block; padding: 12px 24px; color: white; text-decoration: none; border-radius: 5px; margin: 6px 4px; font-weight: bold; font-size: 15px; background: #053272; }
        .cta { text-align: center; margin: 22px 0; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $refund->refund_ref }}</h1>
            <p>Job {{ $serviceRequest->request_id }}</p>
        </div>

        <div class="headline">
            <label>
                @if ($isSettled)
                    Refund sent
                @elseif ($isCreditNote)
                    Credited to your job
                @else
                    Refund approved
                @endif
            </label>
            <div class="amount">KSH {{ number_format($amount, 2) }}</div>
        </div>

        {{-- A credit note moves no money, so it must not read as one that does. --}}
        @if ($isSettled)
            <p style="font-size:14px;">
                We have sent you KSH {{ number_format($amount, 2) }} for your job
                {{ $serviceRequest->request_id }}. Depending on your bank or mobile
                money provider it may take a short while to appear.
            </p>
        @elseif ($isCreditNote)
            <p style="font-size:14px;">
                We have applied a credit of KSH {{ number_format($amount, 2) }} to your
                job {{ $serviceRequest->request_id }}. This reduces what you owe on this
                job — no payment will be sent to you separately.
            </p>
        @else
            <p style="font-size:14px;">
                We have approved a refund of KSH {{ number_format($amount, 2) }} on your
                job {{ $serviceRequest->request_id }}. We are arranging the payment now
                and will confirm as soon as it has been sent.
            </p>
        @endif

        <div class="section">
            <h3>Why</h3>
            <p style="margin:0; font-size:13.5px;">{{ $refund->reason }}</p>
        </div>

        <div class="section">
            <h3>Details</h3>
            <div class="kv"><span>Reference</span><span>{{ $refund->refund_ref }}</span></div>
            <div class="kv"><span>Amount</span><span>KSH {{ number_format($amount, 2) }}</span></div>
            @if ($isSettled && $refund->settlement_reference)
                <div class="kv"><span>Sent under reference</span><span>{{ $refund->settlement_reference }}</span></div>
            @endif
        </div>

        <div class="cta">
            <a href="{{ $reviewUrl }}" class="btn">View your job</a>
        </div>

        <div class="footer">
            <p>Technician World &mdash; Professional Technical Services</p>
            <p>Questions about this refund? Reply to this email and we will come back to you.</p>
        </div>
    </div>
</body>
</html>
