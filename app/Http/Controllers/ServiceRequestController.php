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
    public function create($categoryId = null, $stage = null)
    {
        $serviceCategories = ServiceCategory::where('is_active', true)->get();

        // The wizard drives step selection from the URL so back-button works
        // and each step is bookmarkable. Steps are:
        //   1 = category picker         (no categoryId in URL)
        //   2 = details for that category
        //   3 = review + submit         (stage === 'review')
        // Anything with a non-existent category id resets to step 1.
        $preselectedCategory = null;
        if ($categoryId !== null) {
            $preselectedCategory = $serviceCategories->firstWhere('id', (int) $categoryId);
        }
        $initialStep = 1;
        if ($preselectedCategory) {
            $initialStep = ($stage === 'review') ? 3 : 2;
        }

        return Inertia::render('Client/NewRequest', [
            'serviceCategories'   => $serviceCategories,
            'preselectedCategory' => $preselectedCategory,
            'initialStep'         => $initialStep,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Mobile uploads on 4G can take time. Give the request room to
        // finish even if the user is uploading several photos.
        @set_time_limit(120);
        @ini_set('upload_max_filesize', '20M');
        @ini_set('post_max_size', '60M');
        @ini_set('memory_limit', '256M');

        $validated = $request->validate([
            'service_category_id' => 'required|exists:service_categories,id',
            'description' => 'required|string|min:10|max:1000',
            'location' => 'required|string|max:255',
            'urgency' => 'required|in:low,medium,high',
            'files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx,heic,heif,webp|max:10240', // Max 10MB per file
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

        // Defer admin notifications to AFTER the response is sent so the
        // user doesn't sit waiting on SMTP roundtrips. This eliminates the
        // 30s timeout that happened when several admins / PMs needed
        // notifying serially.
        $srId = $serviceRequest->id;
        app()->terminating(function () use ($srId) {
            try {
                $sr = ServiceRequest::with(['serviceCategory', 'user'])->find($srId);
                if (!$sr) return;
                $adminUsers = User::where('role', 'admin')->get();
                Notification::send($adminUsers, new NewServiceRequestNotification($sr));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('NewServiceRequest notify failed', [
                    'service_request_id' => $srId,
                    'error' => $e->getMessage(),
                ]);
            }
        });

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

        $serviceRequest->load([
            'serviceCategory',
            'technician.user',
            'leadTechnician.user',
            // A project split into sub-tasks reads as one opaque job to the
            // client without this — they see a single percentage and no idea
            // which trade is where. Daily reports are filed per sub-task, so
            // the breakdown is what makes them legible.
            'subTasks.technician.user:id,name',
            'paymentRequests',
            'payments',
            // Only validated reports get photos eager-loaded; client never
            // sees unvalidated drafts. We also exclude photos the PM removed.
            'progressReports' => function ($q) {
                $q->orderBy('report_date', 'desc');
            },
            'progressReports.photos' => function ($q) {
                $q->where('removed_by_pm', false);
            },
            'progressReports.technician.user:id,name',
            'progressReports.subTask:id,title',
            // Photos on the job itself — the client's own evidence plus
            // anything the team deliberately shared with them.
            'photos' => function ($q) {
                $q->clientVisible();
            },
            // Only documents deliberately shared. Case analyses, margin
            // thinking and internal notes stay internal — the flag defaults
            // to false precisely so nothing leaks by omission.
            'documents' => function ($q) {
                $q->clientVisible();
            },
            // Site visits and samples the client was charged for, so an
            // attendance fee on their statement has something to point at.
            'tickets' => function ($q) {
                $q->where('type', \App\Models\Ticket::TYPE_CALLOUT);
            },
            // Variations. Internal ones are excluded at the query, not in the
            // view — a zero-income card must never reach the client, and
            // filtering in Blade is one forgotten condition away from a leak.
            'variationOrders' => function ($q) {
                $q->where('is_client_visible', true)
                  ->whereIn('status', [
                      \App\Models\VariationOrder::STATUS_PENDING_CLIENT,
                      \App\Models\VariationOrder::STATUS_APPROVED,
                      \App\Models\VariationOrder::STATUS_DECLINED,
                  ]);
            },
            'variationOrders.items',
        ]);

        return Inertia::render('Client/RequestStatus', [
            'serviceRequest' => $serviceRequest,
            // Quote, then every variation, then the value after each — so the
            // client can see how the job got to its current figure instead of
            // being handed a new total with no history.
            'variationLedger' => app(\App\Services\VariationOrderService::class)->ledger($serviceRequest),
            // Bank details for the Bank Deposit payment method — client sees
            // where to send the money without having to hunt through emails.
            // Config-driven so we edit once (config/services.php) not per view.
            'bank' => config('services.bank'),
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
