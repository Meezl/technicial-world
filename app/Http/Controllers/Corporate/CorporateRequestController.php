<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OrganisationMember;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\UploadRuntime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The caretaker's side of a management company.
 *
 * A corporate request differs from a retail one in what it must carry — the
 * building it is in, and which of the company's people raised it — and in who
 * may see it afterwards. It differs in nothing else, which is why it goes into
 * the same pipeline rather than a parallel one.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 2.
 */
class CorporateRequestController extends Controller
{
    /**
     * Everything this person may see, which is not the same for everyone.
     *
     * A requester sees what they raised. A verifier, approver or accounts
     * sees the whole company's work. Both come from one scope so the list and
     * the per-record check cannot disagree.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $member = $this->member($user);

        $requests = ServiceRequest::query()
            ->corporate()
            ->visibleToClient($user)
            ->with([
                'serviceCategory:id,name',
                'property:id,name,code',
                'raisedByMember.user:id,name',
                'corporateApprovals',
            ])
            ->forProperty($request->input('property'))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return \Inertia\Inertia::render('Client/Corporate/Requests', [
            'requests' => $requests,
            'membership' => $this->membershipPayload($member),
            'properties' => $member->organisation->activeProperties()->get(['id', 'name', 'code']),
            'filters' => ['property' => $request->input('property')],
        ]);
    }

    /** The form a caretaker raises work from. */
    public function create(Request $request)
    {
        $member = $this->member($request->user());

        return \Inertia\Inertia::render('Client/Corporate/NewRequest', [
            'membership' => $this->membershipPayload($member),
            'properties' => $member->organisation->activeProperties()->get(['id', 'name', 'code', 'address']),
            'categories' => ServiceCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        UploadRuntime::prepare('corporate.service-request');

        $user = $request->user();
        $member = $this->member($user);

        // Only a requester raises work. An approver who needs something done
        // asks one of their people — otherwise they would end up approving
        // their own request, which is the one thing a two-stage workflow
        // exists to prevent.
        abort_unless($member->isRequester(), 403, 'Only a requester can raise a job for this account.');

        $validated = $request->validate([
            'property_id' => [
                'required',
                // Scoped to their own portfolio: an id from another company
                // would otherwise be accepted here and rejected much later by
                // the model invariant, as a 500 rather than a form error.
                Rule::exists('properties', 'id')
                    ->where('client_organisation_id', $member->client_organisation_id)
                    ->where('is_active', true),
            ],
            'service_category_id' => 'required|exists:service_categories,id',
            'description' => 'required|string|min:10|max:1000',
            'location' => 'required|string|max:255',
            'urgency' => 'required|in:low,medium,high',
            'files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx,heic,heif,webp|max:10240',
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(Str::random(6)),
            'user_id' => $user->id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $member->client_organisation_id,
            'property_id' => $validated['property_id'],
            'raised_by_member_id' => $member->id,
            'service_category_id' => $validated['service_category_id'],
            'description' => $validated['description'],
            'location' => $validated['location'],
            'urgency' => $validated['urgency'],
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_CLIENT_SELF,
        ]);

        if ($request->hasFile('files')) {
            $uploaded = [];
            foreach ($request->file('files') as $file) {
                $uploaded[] = [
                    'path' => $file->store('service-requests/' . $serviceRequest->request_id, 'public'),
                    'name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                ];
            }
            $serviceRequest->update(['files' => $uploaded]);
        }

        AuditLog::log('corporate_request.raised', $serviceRequest, null, [
            'organisation' => $member->organisation->name,
            'property' => $serviceRequest->property?->label,
            'raised_by' => $member->name_on_documents,
        ]);

        // Notifying the office is deferred for the same reason the retail path
        // defers it: a caretaker with a leaking pipe should not be waiting on
        // SMTP round-trips.
        $id = $serviceRequest->id;
        app()->terminating(function () use ($id) {
            try {
                $sr = ServiceRequest::with(['serviceCategory', 'user', 'property', 'organisation'])->find($id);
                if (!$sr) return;
                \Illuminate\Support\Facades\Notification::send(
                    User::where('role', User::ROLE_ADMIN)->get(),
                    new \App\Notifications\NewServiceRequestNotification($sr)
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Corporate RFQ notify failed', [
                    'service_request_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return redirect()
            ->route('corporate.requests.index')
            ->with('success', "{$serviceRequest->request_id} raised for {$serviceRequest->property?->label}.");
    }

    /**
     * Hand a request to a different caretaker.
     *
     * The brief asks for this because of absenteeism and staff turnover: the
     * senior manager needs a job to land on somebody who is actually at work.
     * Both the membership and the account move together — the model refuses
     * them out of step, since a request filed under one person and credited to
     * another is worse than either.
     */
    public function reassign(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();
        $member = $this->member($user);

        abort_unless($serviceRequest->isCorporate(), 404);
        abort_unless($member->isApprover(), 403, 'Only the senior manager can reassign a request.');
        abort_unless(
            $member->client_organisation_id === $serviceRequest->client_organisation_id,
            403,
            'This request belongs to another organisation.'
        );

        $validated = $request->validate([
            'member_id' => [
                'required',
                Rule::exists('organisation_members', 'id')
                    ->where('client_organisation_id', $member->client_organisation_id)
                    ->where('position', OrganisationMember::POSITION_REQUESTER)
                    ->where('is_active', true),
            ],
            'reason' => 'nullable|string|max:500',
        ]);

        $target = OrganisationMember::findOrFail($validated['member_id']);
        $from = $serviceRequest->raisedByMember?->name_on_documents ?? 'unassigned';

        $serviceRequest->update([
            'raised_by_member_id' => $target->id,
            'user_id' => $target->user_id,
        ]);

        AuditLog::log('corporate_request.reassigned', $serviceRequest, ['requester' => $from], [
            'requester' => $target->name_on_documents,
            'reason' => $validated['reason'] ?? null,
            'by' => $member->name_on_documents,
        ]);

        return back()->with('success', "{$serviceRequest->request_id} reassigned to {$target->name_on_documents}.");
    }

    /** The caller's active membership, or a 403 if they have none. */
    private function member(User $user): OrganisationMember
    {
        $member = $user->organisationMembership()->with('organisation')->where('is_active', true)->first();

        abort_unless($member, 403, 'Your account is not active for any management company.');

        return $member;
    }

    private function membershipPayload(OrganisationMember $member): array
    {
        return [
            'id' => $member->id,
            'position' => $member->position,
            'position_label' => OrganisationMember::POSITIONS[$member->position] ?? $member->position,
            'name_on_documents' => $member->name_on_documents,
            'organisation' => [
                'id' => $member->organisation->id,
                'name' => $member->organisation->name,
                'approval_workflow' => $member->organisation->approval_workflow,
            ],
            'can_raise' => $member->isRequester(),
            'can_reassign' => $member->isApprover(),
        ];
    }
}
