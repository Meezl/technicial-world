<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'submittedRequest' => fn () => $request->session()->get('submittedRequest'),
                'verification_grace_remaining' => fn () => $request->session()->get('verification_grace_remaining'),
                'verification_denied_reason' => fn () => $request->session()->get('verification_denied_reason'),
            ],
            // Whether the Property Management & Corporate module is switched
            // on, and — for a client acting for a management company — what
            // they may do inside it.
            //
            // Shared rather than fetched per page so navigation can hide what
            // it must not offer without every controller passing a flag. A
            // closure so the membership lookup only runs when a page actually
            // reads it, and only for client accounts: an admin or a technician
            // has no membership and should not pay for the query.
            'corporate' => fn () => [
                'enabled' => \App\Support\CorporateModule::enabled(),
                'membership' => $this->corporateMembership($request),
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }

    /**
     * What this client may do inside their management company, if anything.
     *
     * Null for every retail client and every member of staff, which is what the
     * navigation keys off — a retail portal must not grow corporate menu items
     * because the module happens to be switched on for somebody else.
     *
     * The capability flags mirror the server rules rather than restating them
     * loosely: a caretaker raises work, a verifier and an approver decide it,
     * and the money screens belong to the positions that see the whole account.
     * A menu that offers something the controller then refuses is worse than no
     * menu at all.
     */
    private function corporateMembership(Request $request): ?array
    {
        $user = $request->user();

        if (!$user || !$user->isClient() || !\App\Support\CorporateModule::enabled()) {
            return null;
        }

        $member = $user->organisationMembership()
            ->with('organisation:id,name')
            ->where('is_active', true)
            ->first();

        if (!$member || !$member->organisation) {
            return null;
        }

        $organisationWide = in_array(
            $member->position,
            \App\Models\ServiceRequest::ORGANISATION_WIDE_POSITIONS,
            true
        );

        return [
            'position' => $member->position,
            'position_label' => \App\Models\OrganisationMember::POSITIONS[$member->position] ?? $member->position,
            'organisation' => $member->organisation->name,
            'can_raise' => $member->isRequester(),
            'can_decide' => $member->isVerifier() || $member->isApprover(),
            'sees_whole_account' => $organisationWide,
        ];
    }
}
