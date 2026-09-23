{{--
    The list site security is handed at the gate.

    The brief asks for full names, ID numbers and passport photos "in a neat and
    printable list that can be prepared and issued in hard or soft copy to
    security". Everything here comes from ServiceRequest::attendanceRoster(),
    which returns the roster as data — so when the security portal in RP-3 is
    eventually built it consumes the same source rather than scraping this.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 26px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111827; }
        .head { width: 100%; border-bottom: 2px solid #1e3a8a; padding-bottom: 10px; margin-bottom: 14px; }
        .head td { vertical-align: top; }
        .brand { font-size: 16px; font-weight: bold; color: #1e3a8a; }
        .title { text-align: right; font-size: 14px; font-weight: bold; }
        .muted { color: #6b7280; }
        table.facts td { padding: 2px 0; }
        table.facts .k { color: #6b7280; width: 110px; }
        table.crew { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.crew th { background: #f3f4f6; text-align: left; padding: 6px; border: 1px solid #d1d5db;
                        font-size: 9px; text-transform: uppercase; letter-spacing: .03em; }
        table.crew td { padding: 6px; border: 1px solid #e5e7eb; vertical-align: middle; }
        .photo { width: 46px; height: 56px; object-fit: cover; border: 1px solid #d1d5db; }
        .photo-missing { width: 46px; height: 56px; border: 1px dashed #d1d5db; color: #9ca3af;
                         text-align: center; font-size: 7px; line-height: 56px; }
        .lead { font-weight: bold; }
        .lead-tag { background: #1e3a8a; color: #fff; padding: 1px 5px; border-radius: 3px; font-size: 8px; }
        .sign { margin-top: 26px; border-top: 1px solid #d1d5db; padding-top: 10px; }
        .sign td { padding-top: 22px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>

@php $issuerLogo = public_path('img/logo.png'); @endphp

<table class="head">
    <tr>
        <td style="width:60%;">
            @if (file_exists($issuerLogo))
                <img src="{{ $issuerLogo }}" style="height:34px; margin-bottom:4px;" alt="">
            @endif
            <div class="brand">{{ $issuer['name'] }}</div>
            <div class="muted">{{ $issuer['address'] }}</div>
        </td>
        <td>
            <div class="title">SITE ACCESS LIST</div>
            <div class="muted" style="text-align:right;">Issued {{ now()->format('d M Y H:i') }}</div>
        </td>
    </tr>
</table>

<table class="facts">
    <tr><td class="k">Request</td><td><strong>{{ $serviceRequest->request_id }}</strong></td></tr>
    @if ($serviceRequest->property)
        <tr><td class="k">Property</td><td><strong>{{ $serviceRequest->property->label }}</strong></td></tr>
    @endif
    @if ($serviceRequest->organisation)
        <tr><td class="k">Managed by</td><td>{{ $serviceRequest->organisation->name }}</td></tr>
    @endif
    <tr><td class="k">Location on site</td><td>{{ $serviceRequest->location ?: '—' }}</td></tr>
    <tr><td class="k">Works</td><td>{{ \Illuminate\Support\Str::limit($serviceRequest->description, 160) }}</td></tr>
    @if ($window['start'] || $window['end'])
        <tr>
            <td class="k">Expected on site</td>
            <td>
                {{ optional($window['start'])->format('d M Y') ?: '—' }}
                &ndash;
                {{ optional($window['end'])->format('d M Y') ?: '—' }}
            </td>
        </tr>
    @endif
</table>

<table class="crew">
    <thead>
        <tr>
            <th style="width:30px;">#</th>
            <th style="width:60px;">Photo</th>
            <th>Full name</th>
            <th style="width:110px;">ID number</th>
            <th>Role on site</th>
            <th style="width:130px;">Attending</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($roster as $person)
            <tr>
                <td>{{ $person['ref'] }}</td>
                <td>
                    @php
                        // dompdf reads from the filesystem, not the web path the
                        // portal uses, so the storage URL is translated back.
                        $photo = $person['photo_url']
                            ? storage_path('app/public/' . ltrim(str_replace('/storage/', '', $person['photo_url']), '/'))
                            : null;
                    @endphp
                    @if ($photo && file_exists($photo))
                        <img src="{{ $photo }}" class="photo" alt="">
                    @else
                        <div class="photo-missing">no photo</div>
                    @endif
                </td>
                <td class="{{ $person['is_lead'] ? 'lead' : '' }}">
                    {{ $person['name'] }}
                    @if ($person['is_lead']) <span class="lead-tag">LEAD</span> @endif
                </td>
                <td>{{ $person['national_id'] ?: '—' }}</td>
                <td>{{ $person['role'] }}</td>
                <td>{{ $person['attendance'] ?: '—' }}</td>
            </tr>
        @endforeach
        @if (empty($roster))
            <tr><td colspan="6" style="text-align:center; color:#6b7280;">No technicians assigned yet.</td></tr>
        @endif
    </tbody>
</table>

<p class="muted" style="margin-top:12px; font-size:9.5px;">
    Please admit only the people named above. Anyone attending who is not on this list should be
    turned away and Technician World contacted on {{ $issuer['phone'] ?: $issuer['email'] }}.
</p>

<table class="sign" style="width:100%;">
    <tr>
        <td style="width:50%;">Issued by (Technician World) &mdash; name &amp; signature</td>
        <td>Received by (Security) &mdash; name, signature &amp; date</td>
    </tr>
</table>

</body>
</html>
