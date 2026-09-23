<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ServiceRequest;
use App\Models\TechnicianLead;
use App\Models\User;
use App\Notifications\NewTechnicianLeadNotification;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    /**
     * A client has just raised a request: confirm it to them, and alert the
     * admins, the PMs and the office inbox.
     *
     * Stamps office_alerted_at, which is what makes the request eligible for
     * the two-hourly reminders in `rfq:remind-unactioned`.
     */
    public function notifyNewRfq(ServiceRequest $serviceRequest): void
    {
        $serviceRequest->loadMissing(['serviceCategory', 'user']);

        if ($serviceRequest->user?->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($serviceRequest->user->email)
                    ->send(new \App\Mail\ServiceRequestReceived($serviceRequest));
            } catch (\Throwable $e) {
                // The office still needs to hear about it even if the client's
                // confirmation bounced.
                \Illuminate\Support\Facades\Log::warning('ServiceRequestReceived email failed', [
                    'service_request_id' => $serviceRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->alertOfficeAboutRfq($serviceRequest, 0);

        $serviceRequest->forceFill(['office_alerted_at' => now()])->saveQuietly();
    }

    /**
     * Send the new-request alert (or the Nth reminder of it) to every active
     * admin and PM, plus the office inbox.
     */
    public function alertOfficeAboutRfq(ServiceRequest $serviceRequest, int $reminder): void
    {
        $notification = new \App\Notifications\NewServiceRequestNotification($serviceRequest, $reminder);

        $staff = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER])
            ->where('is_active', true)
            ->get();

        foreach ($staff as $user) {
            try {
                $user->notify($notification);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('New RFQ alert failed', [
                    'service_request_id' => $serviceRequest->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $inbox = trim((string) config('mail.new_request_inbox'));

        // Skip the inbox if it already belongs to one of the staff above, so it
        // does not get the same mail twice.
        if ($inbox !== '' && !$staff->contains(fn ($u) => strcasecmp((string) $u->email, $inbox) === 0)) {
            try {
                Notification::route('mail', $inbox)->notify($notification);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('New RFQ inbox alert failed', [
                    'service_request_id' => $serviceRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify client about quotation sent.
     */
    public function notifyQuotationSent(ServiceRequest $serviceRequest): void
    {
        $client = $serviceRequest->user;
        if ($client) {
            // Use existing mail
            \Illuminate\Support\Facades\Mail::to($client->email)
                ->send(new \App\Mail\QuotationSent($serviceRequest));
        }
    }

    /**
     * Notify admins about new technician lead.
     */
    public function notifyNewTechnicianLead(TechnicianLead $lead): void
    {
        $admins = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER])->get();
        foreach ($admins as $admin) {
            $admin->notify(new NewTechnicianLeadNotification($lead));
        }
    }

    /**
     * Notify about payment status change.
     */
    public function notifyPaymentStatusChange(ServiceRequest $serviceRequest, string $paymentStatus): void
    {
        $client = $serviceRequest->user;
        $pm = $serviceRequest->assignedPm;

        // Both client and PM should see payment status changes
        // Implementation uses database notifications
        $users = collect([$client, $pm])->filter();

        foreach ($users as $user) {
            $user->notifications()->create([
                'id' => \Illuminate\Support\Str::uuid(),
                'type' => 'App\Notifications\PaymentStatusNotification',
                'data' => [
                    'service_request_id' => $serviceRequest->id,
                    'job_reference' => $serviceRequest->job_reference,
                    'payment_status' => $paymentStatus,
                    'message' => "Payment status updated to {$paymentStatus} for Job {$serviceRequest->job_reference}",
                ],
            ]);
        }
    }

    /**
     * Notify about job assignment.
     */
    public function notifyJobAssignment(ServiceRequest $serviceRequest): void
    {
        // Notify technician
        $technician = $serviceRequest->technician;
        if ($technician && $technician->user) {
            \Illuminate\Support\Facades\Mail::to($technician->user->email)
                ->send(new \App\Mail\JobAssigned($serviceRequest));
        }

        // Notify client
        $client = $serviceRequest->user;
        if ($client && $technician) {
            \Illuminate\Support\Facades\Mail::to($client->email)
                ->send(new \App\Mail\TechnicianAssigned($serviceRequest, $technician));
        }
    }

    /**
     * Notify about job state change.
     */
    public function notifyStateChange(ServiceRequest $serviceRequest, string $oldState, string $newState): void
    {
        $statusLabels = ServiceRequest::allStatuses();
        $newLabel = $statusLabels[$newState] ?? $newState;

        $users = collect([
            $serviceRequest->user,
            $serviceRequest->assignedPm,
        ])->filter();

        // Also notify technician if assigned
        if ($serviceRequest->technician && $serviceRequest->technician->user) {
            $users->push($serviceRequest->technician->user);
        }

        foreach ($users->unique('id') as $user) {
            $user->notifications()->create([
                'id' => \Illuminate\Support\Str::uuid(),
                'type' => 'App\Notifications\JobStateChangeNotification',
                'data' => [
                    'service_request_id' => $serviceRequest->id,
                    'job_reference' => $serviceRequest->job_reference,
                    'old_state' => $oldState,
                    'new_state' => $newState,
                    'message' => "Job {$serviceRequest->job_reference} status changed to {$newLabel}",
                ],
            ]);
        }
    }

    /**
     * A technician submitted a tool / PPE request — tell the admins there is
     * something waiting to act on. Mail failures never break the request.
     */
    public function notifyToolRequestSubmitted(\App\Models\ToolRequest $toolRequest): void
    {
        $toolRequest->loadMissing(['items.tool', 'technician.user']);

        $admins = User::where('role', User::ROLE_ADMIN)->get();
        if ($admins->isEmpty()) {
            return;
        }

        try {
            Notification::send($admins, new \App\Notifications\ToolRequestSubmittedNotification($toolRequest));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Tool request submitted notify failed', [
                'tool_request_id' => $toolRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The office acted on a requested item — accepted, assigned (issued) or
     * rejected. Tell the technician who asked for it.
     */
    public function notifyToolRequestDecision(
        \App\Models\ToolRequestItem $item,
        string $action,
        ?string $issuedSummary = null,
    ): void {
        $item->loadMissing(['tool', 'toolRequest.technician.user']);

        $technicianUser = $item->toolRequest?->technician?->user;
        if (!$technicianUser) {
            return;
        }

        try {
            $technicianUser->notify(
                new \App\Notifications\ToolRequestDecisionNotification($item, $action, $issuedSummary)
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Tool request decision notify failed', [
                'tool_request_item_id' => $item->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PPE return handshake. A requested return goes to the admins to confirm; a
     * confirmed or rejected one goes back to the technician who asked.
     */
    public function notifyToolReturn(
        \App\Models\ToolIssuance $issuance,
        string $action,
        int $quantity,
    ): void {
        $issuance->loadMissing(['tool', 'technician.user']);

        $notification = new \App\Notifications\ToolReturnNotification($issuance, $action, $quantity);

        try {
            if ($action === \App\Notifications\ToolReturnNotification::ACTION_REQUESTED) {
                $admins = User::where('role', User::ROLE_ADMIN)->get();
                if ($admins->isNotEmpty()) {
                    Notification::send($admins, $notification);
                }
            } else {
                $technicianUser = $issuance->technician?->user;
                $technicianUser?->notify($notification);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Tool return notify failed', [
                'tool_issuance_id' => $issuance->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
