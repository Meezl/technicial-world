<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{{ $digest->reference }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color:#111827; line-height:1.5;">

    <h2 style="margin:0 0 4px;">Daily progress</h2>
    <p style="margin:0 0 20px; color:#6b7280;">
        {{ $organisation->name }} &middot; {{ $digest->period_date->format('d M Y') }} &middot;
        {{ $digest->job_count }} {{ \Illuminate\Support\Str::plural('job', $digest->job_count) }},
        {{ $digest->report_count }} {{ \Illuminate\Support\Str::plural('update', $digest->report_count) }}
    </p>

    {{-- One segment per job, which is what makes a dozen buildings readable
         in a single email rather than a wall of updates. --}}
    @foreach ($segments as $segment)
        @php $request = $segment['request']; @endphp

        <div style="margin-bottom:22px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
            <div style="background:#f9fafb; padding:10px 14px; border-bottom:1px solid #e5e7eb;">
                <strong>{{ $request->request_id }}</strong>
                @if ($request->property)
                    <span style="color:#6b7280;">
                        &middot; {{ $request->property->code ? "{$request->property->name} ({$request->property->code})" : $request->property->name }}
                    </span>
                @endif
                <span style="float:right; color:#1e40af; font-weight:bold;">{{ (int) $segment['progress'] }}%</span>
            </div>

            <div style="padding:12px 14px;">
                <p style="margin:0 0 10px; color:#4b5563; font-size:13px;">{{ $request->description }}</p>

                @foreach ($segment['reports'] as $report)
                    <div style="padding:8px 0; border-top:1px solid #f3f4f6;">
                        <div style="font-size:13px;">
                            <strong>{{ optional($report->report_date)->format('d M') }}</strong>
                            @if ($report->subTask) &middot; {{ $report->subTask->title }} @endif
                            @if ($report->technician?->user) &middot; {{ $report->technician->user->name }} @endif
                            <span style="color:#6b7280;">
                                &middot; {{ (int) ($report->validated_percent ?? $report->percent_complete) }}%
                            </span>
                        </div>
                        @if ($report->client_visible_notes)
                            <div style="font-size:13px; color:#4b5563; margin-top:3px;">{{ $report->client_visible_notes }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <p style="max-width:640px; color:#6b7280; font-size:13px;">
        This is your one update for the day, covering every job under way. Individual jobs and their
        full history remain available on your portal.
    </p>

    <p style="margin-top:24px; color:#6b7280; font-size:12px;">
        Technician World &middot; {{ $digest->reference }}
    </p>
</body></html>
