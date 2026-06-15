<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9; }
        .header { background-color: #28a745; color: white; padding: 10px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { padding: 20px; background-color: white; }
        .details-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .details-table th, .details-table td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
        .details-table th { background-color: #f8f9fa; width: 35%; }
        .progress-bar-wrap { background: #e9ecef; border-radius: 4px; height: 16px; margin: 4px 0; }
        .progress-bar-fill { background: #28a745; height: 16px; border-radius: 4px; }
        .footer { margin-top: 20px; font-size: 0.9em; text-align: center; color: #666; }
        .btn { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Progress Update — {{ $report->validated_percent ?? $report->percent_complete }}% Complete</h2>
        </div>
        <div class="content">
            <p>Hello {{ $serviceRequest->user->name ?? 'Valued Client' }},</p>
            <p>
                We have a progress update for your service request
                <strong>{{ $serviceRequest->request_id }}</strong>.
                The team has validated the latest site report:
            </p>

            <table class="details-table">
                <tr>
                    <th>Job Reference</th>
                    <td>{{ $serviceRequest->request_id }}</td>
                </tr>
                <tr>
                    <th>Service</th>
                    <td>{{ $serviceRequest->serviceCategory->name ?? 'General Service' }}</td>
                </tr>
                <tr>
                    <th>Report Date</th>
                    <td>{{ $report->report_date ? \Carbon\Carbon::parse($report->report_date)->format('d M Y') : 'N/A' }}</td>
                </tr>
                <tr>
                    <th>Validated Progress</th>
                    <td>
                        {{ $report->validated_percent ?? $report->percent_complete }}%
                        <div class="progress-bar-wrap">
                            <div class="progress-bar-fill" style="width:{{ $report->validated_percent ?? $report->percent_complete }}%;"></div>
                        </div>
                    </td>
                </tr>
                @if($report->validation_notes)
                <tr>
                    <th>Notes</th>
                    <td>{{ $report->validation_notes }}</td>
                </tr>
                @endif
            </table>

            @if(($report->validated_percent ?? $report->percent_complete) >= 100)
                <p><strong>Your job is now 100% complete. Our team will be in touch shortly to close out the project.</strong></p>
            @else
                <p>Work is progressing on schedule. Log in to your portal to view photos and the full project timeline.</p>
            @endif

            <div style="text-align:center;">
                <a href="{{ url('/client/requests/' . $serviceRequest->id) }}" class="btn">View Job Status</a>
            </div>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Technician World. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
