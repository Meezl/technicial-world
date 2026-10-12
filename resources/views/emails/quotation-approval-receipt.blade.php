<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approval received</title>
    <style>
        body { margin:0; padding:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#0f172a; }
        .wrap { max-width:600px; margin:0 auto; padding:24px 16px; }
        .card { background:#ffffff; border-radius:14px; padding:28px 24px; }
        .tick { width:52px; height:52px; line-height:52px; border-radius:50%; background:#16a34a; color:#ffffff; font-size:26px; text-align:center; margin:0 auto 14px; }
        h1 { margin:0 0 6px; font-size:20px; text-align:center; }
        .lead { margin:0 0 20px; font-size:14px; color:#475569; text-align:center; }
        .facts { border:1px solid #e2e8f0; border-radius:12px; padding:4px 16px; margin:0 0 20px; }
        .row { display:flex; justify-content:space-between; padding:11px 0; border-bottom:1px solid #f1f5f9; font-size:14px; }
        .row:last-child { border-bottom:0; }
        .row span:first-child { color:#64748b; }
        .row span:last-child { font-weight:700; text-align:right; }
        .total span:last-child { color:#16a34a; font-size:16px; }
        .next { background:#f8fafc; border-radius:12px; padding:16px; font-size:13.5px; line-height:1.6; color:#334155; }
        .next strong { display:block; margin-bottom:6px; color:#0f172a; }
        .foot { text-align:center; font-size:12px; color:#94a3b8; margin-top:18px; line-height:1.6; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="tick">&#10003;</div>
        <h1>We have your approval</h1>

        {{-- Says which act it was. A client who paid rather than pressing a
             button should see their own version of events reflected back, not
             be told they clicked something they never clicked. --}}
        <p class="lead">
            @if($paidInstead)
                Your payment has been received and recorded as your acceptance of the quotation for
                {{ $reference }}.
            @else
                Thank you — your approval of the quotation for {{ $reference }} has been received.
            @endif
        </p>

        <div class="facts">
            <div class="row">
                <span>Reference</span>
                <span>{{ $reference }}</span>
            </div>
            @if($serviceRequest->serviceCategory)
            <div class="row">
                <span>Service</span>
                <span>{{ $serviceRequest->serviceCategory->name }}</span>
            </div>
            @endif
            <div class="row">
                <span>Location</span>
                <span>{{ $serviceRequest->location }}</span>
            </div>
            @if($approvedAt)
            <div class="row">
                <span>Approved on</span>
                <span>{{ \Carbon\Carbon::parse($approvedAt)->format('d M Y, g:ia') }}</span>
            </div>
            @endif
            <div class="row total">
                <span>Agreed amount</span>
                <span>KSH {{ number_format((float) $amount, 2) }}</span>
            </div>
        </div>

        <div class="next">
            <strong>What happens next</strong>
            @if($paidInstead)
                Your job is being scheduled. We will confirm the technician and the date,
                and you can follow progress in your portal at any time.
            @else
                We will confirm the schedule and let you know what is payable and when.
                Nothing further is needed from you right now.
            @endif
        </div>

        <p class="foot">
            Keep this email — it is your record of what was agreed and when.<br>
            Questions? info@technicianworld.com &middot; 0117 962 395
        </p>
    </div>
</div>
</body>
</html>
