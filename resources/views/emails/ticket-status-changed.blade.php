<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: Arial, sans-serif; line-height: 1.5; color: #333; background:#f5f5f5; padding:20px; }
    .container { max-width: 620px; margin:0 auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
    .header { padding:20px; color:#fff; text-align:center; }
    .header-in_progress { background:#2563eb; }
    .header-resolved { background:#059669; }
    .header-closed { background:#6b7280; }
    .header-open { background:#f59e0b; }
    .content { padding:24px; }
    .note-box { background:#f9fafb; border-left:3px solid #2563eb; padding:12px 16px; margin:14px 0; white-space:pre-wrap; }
    .resolution-box { background:#ecfdf5; border-left:3px solid #059669; padding:12px 16px; margin:14px 0; white-space:pre-wrap; }
    .meta { font-size:13px; color:#6b7280; margin-top:14px; }
    .footer { padding:14px; font-size:12px; color:#6b7280; text-align:center; border-top:1px solid #eee; }
</style>
</head>
<body>
<div class="container">
    <div class="header header-{{ $ticket->status }}">
        <h2 style="margin:0;">Status Update — {{ $ticket->statusLabel() }}</h2>
        <p style="margin:4px 0 0;font-family:'Courier New',monospace;">{{ $ticket->ticket_ref }}</p>
    </div>

    <div class="content">
        <p>Hello {{ $ticket->filer_name }},</p>
        <p>Your ticket <strong>{{ $ticket->ticket_ref }}</strong> has been updated to <strong>{{ $ticket->statusLabel() }}</strong>.</p>

        @if($log->note)
        <p><strong>Update from our team:</strong></p>
        <div class="note-box">{{ $log->note }}</div>
        @endif

        @if($ticket->status === 'resolved' && $ticket->resolution_summary)
        <p><strong>Resolution:</strong></p>
        <div class="resolution-box">{{ $ticket->resolution_summary }}</div>
        <p>
            If the issue has not been fully addressed, reply to this email or call us within the next 7 days
            and we'll reopen the ticket.
        </p>
        @endif

        @if($ticket->status === 'closed')
        <p>This ticket is now closed. Thank you for using Technician World — we hope the service met your expectations.</p>
        @endif

        <div class="meta">
            Subject: {{ $ticket->subject }}<br>
            Updated: {{ $log->created_at->format('d M Y H:i') }}
        </div>
    </div>

    <div class="footer">
        Technician World &middot; You are receiving this because you filed this ticket.
    </div>
</div>
</body>
</html>
