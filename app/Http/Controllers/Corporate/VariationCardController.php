<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\OrganisationMember;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationCard;
use App\Services\VariationCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use RuntimeException;

/**
 * The client's side of asking for more work.
 *
 * A caretaker raises a card because they can see the problem; their senior
 * manager decides whether it is worth spending on. Only after that does the
 * office price it — see VariationCardService.
 */
class VariationCardController extends Controller
{
    public function __construct(private VariationCardService $cards)
    {
    }

    /** Cards across the account, and what each is waiting on. */
    public function index(Request $request)
    {
        $user = $request->user();
        $member = $this->member($user);

        $cards = VariationCard::query()
            ->whereHas('serviceRequest', function ($q) use ($user) {
                $q->corporate()->visibleToClient($user);
            })
            ->with([
                'serviceRequest:id,request_id,description,property_id',
                'serviceRequest.property:id,name,code',
                'raisedByMember.user:id,name',
                'decidedByMember:id,display_name',
                'variationOrder:id,vo_number,status,net_amount',
            ])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Client/Corporate/VariationCards', [
            'cards' => $cards,
            'membership' => [
                'position' => $member->position,
                'position_label' => OrganisationMember::POSITIONS[$member->position] ?? $member->position,
                'organisation' => $member->organisation->name,
                'can_raise' => $member->isRequester(),
                'can_decide' => $member->isApprover(),
            ],
            // Only live jobs: extra scope on finished work is a new request.
            'openJobs' => ServiceRequest::corporate()
                ->visibleToClient($user)
                ->whereNotIn('status', [
                    ServiceRequest::STATUS_CLOSED,
                    ServiceRequest::STATUS_ARCHIVED,
                    ServiceRequest::STATUS_CANCELLED,
                ])
                ->with('property:id,name,code')
                ->get(['id', 'request_id', 'description', 'property_id']),
        ]);
    }

    public function store(Request $request)
    {
        $member = $this->member($request->user());

        $data = $request->validate([
            'service_request_id' => 'required|exists:service_requests,id',
            'scope_description' => 'required|string|min:10|max:2000',
            'justification' => 'required|string|min:10|max:2000',
        ]);

        $serviceRequest = ServiceRequest::findOrFail($data['service_request_id']);

        abort_unless($serviceRequest->isVisibleToClient($request->user()), 403);

        try {
            $card = $this->cards->raise($serviceRequest, $member, $data);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->notifyApprovers($card);

        return back()->with('success', "{$card->card_number} raised. Your manager will decide on it.");
    }

    /** The senior manager saying yes or no, with comments. */
    public function decide(Request $request, VariationCard $card)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,decline',
            'comments' => 'nullable|string|max:1000',
        ]);

        abort_unless($card->serviceRequest?->isVisibleToClient($request->user()), 403);

        try {
            $card = $this->cards->decide(
                $card,
                $request->user(),
                $data['decision'] === 'approve',
                $data['comments'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($card->status === VariationCard::STATUS_APPROVED) {
            $this->notifyOffice($card);
        }

        return back()->with('success', $card->status === VariationCard::STATUS_APPROVED
            ? "{$card->card_number} approved. Technician World will price the additional scope."
            : "{$card->card_number} declined and the requester told why.");
    }

    /**
     * Tell the people who have to act.
     *
     * A card sitting unseen in a list is the failure mode this whole step
     * exists to avoid — the caretaker raised it because something is wrong now.
     */
    private function notifyApprovers(VariationCard $card): void
    {
        $approvers = OrganisationMember::where('client_organisation_id', $card->serviceRequest->client_organisation_id)
            ->where('position', OrganisationMember::POSITION_APPROVER)
            ->where('is_active', true)
            ->with('user')
            ->get();

        foreach ($approvers as $approver) {
            $this->send($approver->user?->email, $card, true);
        }
    }

    private function notifyOffice(VariationCard $card): void
    {
        foreach (User::where('role', User::ROLE_ADMIN)->where('is_active', true)->get() as $admin) {
            $this->send($admin->email, $card, false);
        }

        if ($pm = User::find($card->serviceRequest->assigned_pm_id)) {
            $this->send($pm->email, $card, false);
        }
    }

    private function send(?string $email, VariationCard $card, bool $toApprover): void
    {
        if (!$email) {
            return;
        }

        try {
            Mail::to($email)->send(new \App\Mail\VariationCardRaised($card->fresh([
                'serviceRequest.property', 'raisedByMember.user', 'decidedByMember',
            ]), $toApprover));
        } catch (\Throwable $e) {
            Log::warning('Variation card notification failed', [
                'card' => $card->card_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function member(User $user): OrganisationMember
    {
        $member = $user->organisationMembership()->with('organisation')->where('is_active', true)->first();

        abort_unless($member, 403, 'Your account is not active for any management company.');

        return $member;
    }
}
