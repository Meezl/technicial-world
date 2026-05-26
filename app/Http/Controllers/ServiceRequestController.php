<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ServiceRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Notifications\NewServiceRequestNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class ServiceRequestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $serviceCategories = ServiceCategory::where('is_active', true)->get();

        return Inertia::render('Client/NewRequest', [
            'serviceCategories' => $serviceCategories
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_category_id' => 'required|exists:service_categories,id',
            'description' => 'required|string|min:10|max:1000',
            'location' => 'required|string|max:255',
            'urgency' => 'required|in:low,medium,high',
            'files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx|max:10240', // Max 10MB per file
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(Str::random(6)),
            'user_id' => auth()->id(),
            'service_category_id' => $validated['service_category_id'],
            'description' => $validated['description'],
            'location' => $validated['location'],
            'urgency' => $validated['urgency'],
            'status' => 'pending',
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_CLIENT_SELF,
        ]);

        // Load relationships for notification
        $serviceRequest->load(['serviceCategory', 'user']);

        // Handle file uploads
        if ($request->hasFile('files')) {
            $uploadedFiles = [];
            foreach ($request->file('files') as $file) {
                $path = $file->store('service-requests/' . $serviceRequest->request_id, 'public');
                $uploadedFiles[] = [
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                ];
            }
            $serviceRequest->update(['files' => $uploadedFiles]);
        }

        // Send notification to all admin users
        $adminUsers = User::where('role', 'admin')->get();
        Notification::send($adminUsers, new NewServiceRequestNotification($serviceRequest));

        return redirect()->route('client.dashboard')
            ->with('success', 'Service request submitted successfully.')
            ->with('submittedRequest', [
                'id' => $serviceRequest->id,
                'request_id' => $serviceRequest->request_id,
                'service_category' => $serviceRequest->serviceCategory?->name,
            ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceRequest $serviceRequest)
    {
        // Ensure user can only view their own requests
        if ($serviceRequest->user_id !== auth()->id()) {
            abort(403);
        }

        $serviceRequest->load(['serviceCategory', 'technician.user', 'paymentRequests', 'payments']);

        return Inertia::render('Client/RequestStatus', [
            'serviceRequest' => $serviceRequest
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
