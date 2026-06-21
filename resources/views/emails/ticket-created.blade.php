<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: Arial, sans-serif; line-height: 1.5; color: #333; background:#f5f5f5; padding:20px; }
    .container { max-width: 640px; margin: 0 auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
    .header { padding:20px; color:#fff; }
    .urgency-emergency { background:#dc2626; }
    .urgency-urgent { background:#f97316; }
    .urgency-normal { background:#1a56db; }
    .content { padding:20px; }
    .meta-table { width:100%; border-collapse:collapse; margin:16px 0; }
    .meta-table th { text-align:left; padding:8px; background:#f9fafb; width:120px; font-weight:600; color:#6b7280; }
    .meta-table td { padding:8px; border-top:1px solid #e5e7eb; }
    .desc { background:#f9fafb; padding:14px; border-radius:6px; border-left:3px solid #1a56db; white-space:pre-wrap; }
    .btn { display:inline-block; padding:10px 20px; background:#1a56db; color:#fff!important; text-decoration:none; border-radius:6px; margin-top:16px; }
    .footer { padding:14px; font-size:12px; color:#6b7280; text-align:center; border-top:1px solid #eee; }
</style>
</head>
<body>
<div class="container">
    <div class="header urgency-{{ $ticket->urgency }}">
        <h2 style="margin:0;">New Ticket — {{ $ticket->ticket_ref }}</h2>
        <p style="margin:4px 0 0;">{!! $ticket->urgencyLabel() !!} · {{ ucfirst($ticket->category) }}</p>
    </div>

    <div class="content">
        <h3 style="margin-top:0;">{{ $ticket->subject }}</h3>

        <table class="meta-table">
            <tr><th>Filed by</th><td>{{ $ticket->filer_name }}</td></tr>
            <tr><th>Email</th><td><a href="mailto:{{ $ticket->filer_email }}">{{ $ticket->filer_email }}</a></td></tr>
            @if($ticket->filer_phone)
            <tr><th>Phone</th><td><a href="tel:{{ $ticket->filer_phone }}">{{ $ticket->filer_phone }}</a></td></tr>
            @endif
            @if($ticket->location)
            <tr><th>Location</th><td>{{ $ticket->location }}</td></tr>
            @endif
            <tr><th>Category</th><td>{{ ucfirst($ticket->category) }}</td></tr>
            <tr><th>Urgency</th><td>{!! $ticket->urgencyLabel() !!}</td></tr>
            <tr><th>Account</th><td>{{ $ticket->user_id ? 'Registered client (#' . $ticket->user_id . ')' : 'Guest (no account)' }}</td></tr>
            <tr><th>Filed at</th><td>{{ $ticket->created_at->format('d M Y H:i') }}</td></tr>
        </table>

        <h4 style="margin:18px 0 6px;">Description</h4>
        <div class="desc">{{ $ticket->description }}</div>

        <div style="text-align:center;">
            <a href="{{ url('/admin/tickets/' . $ticket->id) }}" class="btn">Open Ticket in Portal</a>
        </div>

        <p style="font-size:13px;color:#6b7280;margin-top:18px;">
            Reply directly to the client at <strong>{{ $ticket->filer_email }}</strong> to start the conversation,
            then mark the ticket as <em>In Progress</em> in the admin portal to confirm acknowledgement.
        </p>
    </div>

    <div class="footer">
        Technician World · Ticket alert · Sent to admins and project managers
    </div>
</div>
</body>
</html>
