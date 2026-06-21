<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailLogController extends Controller
{
    public function index(Request $request)
    {
        $query = EmailLog::query()->with('user:id,name');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('mailable_class', 'like', "%{$search}%")
                  ->orWhereRaw('JSON_SEARCH(LOWER(`to`), "one", LOWER(?)) IS NOT NULL', ["%{$search}%"]);
            });
        }

        if ($related = $request->input('related_type')) {
            $query->where('related_type', $related);
        }

        if ($from = $request->input('from')) {
            $query->where('sent_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('sent_at', '<=', $to);
        }

        $emails = $query->orderByDesc('sent_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/EmailLogs/Index', [
            'emails' => $emails,
            'filters' => $request->only(['search', 'related_type', 'from', 'to']),
        ]);
    }

    public function show(EmailLog $emailLog)
    {
        $emailLog->load('user:id,name,email');
        return Inertia::render('Admin/EmailLogs/Show', [
            'email' => $emailLog,
        ]);
    }
}
