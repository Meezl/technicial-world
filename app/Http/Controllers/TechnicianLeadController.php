<?php

namespace App\Http\Controllers;

use App\Models\TechnicianLead;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TechnicianLeadController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

    /**
     * Show the public technician interest form.
     */
    public function create()
    {
        return Inertia::render('Public/TechnicianInterest', [
            'trades' => \App\Models\Technician::trades(),
        ]);
    }

    /**
     * Store a new technician interest submission.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'trade' => 'required|string|max:100',
            'experience' => 'nullable|string|max:2000',
            'location' => 'nullable|string|max:255',
        ]);

        // Rate limiting: max 3 submissions per email per day
        $existingToday = TechnicianLead::where('email', $request->email)
            ->whereDate('created_at', today())
            ->count();

        if ($existingToday >= 3) {
            return redirect()->back()->with('error', 'Too many submissions. Please try again tomorrow.');
        }

        $lead = TechnicianLead::create($request->only([
            'name', 'email', 'phone', 'trade', 'experience', 'location',
        ]));

        // Notify admins and PMs
        $this->notificationService->notifyNewTechnicianLead($lead);

        return redirect()->back()->with('success',
            'Thank you for your interest! Our team will contact you shortly.');
    }
}
