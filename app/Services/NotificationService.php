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
     * Notify admins and PMs about a new RFQ.
     */
    public function notifyNewRfq(ServiceRequest $serviceRequest): void
    {
        $admins = User::where('role', User::ROLE_ADMIN)->get();
        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\NewServiceRequestNotification($serviceRequest));
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
}
