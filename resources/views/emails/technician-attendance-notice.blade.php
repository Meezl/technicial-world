<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Verdana, Arial, sans-serif; line-height: 1.6; color: #333; font-size: 14px; }
        .container { max-width: 720px; margin: 0 auto; padding: 24px; }
        .reference { font-weight: bold; text-transform: uppercase; margin-bottom: 20px; }
        table.roster { width: 100%; border-collapse: collapse; margin: 18px 0; font-size: 13px; }
        table.roster th, table.roster td { border: 1px solid #333; padding: 8px 10px; text-align: left; vertical-align: top; }
        table.roster th { background-color: #f2f2f2; font-weight: bold; }
        table.roster td.ref { width: 40px; text-align: center; }
        .lead-duty { display: block; }
        .notes { margin-top: 18px; padding: 12px; background: #f9f9f9; border-left: 3px solid #ccc; }
        .footer { margin-top: 28px; font-size: 12px; color: #666; }
    </style>
</head>

<body>
    <div class="container">
        <p class="reference">
            {{ $serviceRequest->request_id }} - {{ strtoupper($serviceRequest->description) }} - TECHNICIANS &amp; GANG MEMBERS
        </p>

        <p>Dear {{ $serviceRequest->user->name ?? 'Client' }},</p>

        <p>
            We refer to the Request Number referenced above. The following technicians have been
            assigned to the job and will be visiting your property
            @if ($window['start'] && $window['end'] && !$window['start']->isSameDay($window['end']))
                between {{ $window['start']->format('jS F Y') }} and {{ $window['end']->format('jS F Y') }}
            @elseif ($window['start'])
                on {{ $window['start']->format('jS F Y') }}
            @endif
            to work on the assignment:
        </p>

        <table class="roster">
            <thead>
                <tr>
                    <th class="ref">Ref</th>
                    <th>Name</th>
                    <th>ID No.</th>
                    <th>Role</th>
                    <th>Projected Dates of Attendance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roster as $member)
                    <tr>
                        <td class="ref">{{ $member['ref'] }}.</td>
                        <td>{{ $member['name'] }}</td>
                        {{-- Blank rather than a placeholder: security checks this
                             against the physical card, and inventing a value
                             would be worse than an empty cell. --}}
                        <td>{{ $member['national_id'] ?: '—' }}</td>
                        <td>{{ $member['role'] }}</td>
                        <td>{{ $member['attendance'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($additionalNotes)
            <div class="notes">{!! nl2br(e($additionalNotes)) !!}</div>
        @endif

        <p>
            Kindly grant them access to the site on the dates indicated. Should you need to
            confirm any of the above, please contact our office quoting
            {{ $serviceRequest->request_id }}.
        </p>

        <p>
            Regards,<br>
            <strong>Technician World</strong>
        </p>

        <div class="footer">
            This notice relates to service request {{ $serviceRequest->request_id }}
            @if ($serviceRequest->location) at {{ $serviceRequest->location }}@endif.
        </div>
    </div>
</body>

</html>
