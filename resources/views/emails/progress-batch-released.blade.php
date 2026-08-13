<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9; }
        .header { background-color: #28a745; color: white; padding: 10px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { padding: 20px; background-color: white; }
        .report-card { border: 1px solid #eee; border-radius: 6px; padding: 12px 14px; margin: 12px 0; }
        .report-card h4 { margin: 0 0 6px; color: #1f2937; }
        .report-meta { font-size: 0.85em; color: #6b7280; margin: 0 0 8px; }
        .progress-bar-wrap { background: #e9ecef; border-radius: 4px; height: 14px; margin: 6px 0; }
        .progress-bar-fill { background: #28a745; height: 14px; border-radius: 4px; }
        .footer { margin-top: 20px; font-size: 0.9em; text-align: center; color: #666; }
        .btn { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Progress Update — {{ $serviceRequest->request_id }}</h2>
        </div>
        <div class="content">
            <p>Hello {{ $serviceRequest->user->name ?? 'Valued Client' }},</p>
            <p>
                Here is the latest progress on your job
                <strong>{{ $serviceRequest->request_id }}</strong>. The team has
                reviewed and released {{ $reports->count() }}
                {{ $reports->count() === 1 ? 'update' : 'updates' }} for you below.
            </p>

            @foreach ($reports as $report)
                @php
                    $pct = $report->validated_percent ?? $report->percent_complete;
                    $note = $report->client_visible_notes ?: $report->notes;
                @endphp
                <div class="report-card">
                    <h4>{{ $report->subTask->title ?? 'Overall progress' }}</h4>
                    <p class="report-meta">
                        {{ optional($report->report_date)->format('d M Y') }}
                        @if ($report->technician && $report->technician->user)
                            &middot; {{ $report->technician->user->name }}
                        @endif
                    </p>
                    <div class="progress-bar-wrap">
                        <div class="progress-bar-fill" style="width: {{ (int) $pct }}%;"></div>
                    </div>
                    <p style="margin: 4px 0 0;"><strong>{{ (int) $pct }}% complete</strong></p>
                    @if ($note)
                        <p style="margin: 8px 0 0;">{{ $note }}</p>
                    @endif
                </div>
            @endforeach

            <p style="margin-top: 16px;">
                You can see the full details, photos, and payment status in your
                dashboard.
            </p>
            <a class="btn" href="{{ url('/client/request-status/' . $serviceRequest->id) }}">View job details</a>
        </div>
        <div class="footer">
            <p>Technician World &middot; This is an automated progress notification.</p>
        </div>
    </div>
</body>
</html>
