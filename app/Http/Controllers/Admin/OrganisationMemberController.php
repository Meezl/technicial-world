<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\OrganisationMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Who acts for a management company, and in what capacity.
 *
 * Position is a fact about the relationship, not the login — see
 * OrganisationMember. An admin attaches an existing client account here; the
 * account itself is created on the Users screen, so there is one place that
 * issues credentials rather than two.
 */
class OrganisationMemberController extends Controller
{
    public function store(Request $request, ClientOrganisation $organisation)
    {
        $data = $this->validated($request);

        $user = User::findOrFail($data['user_id']);
        $this->assertAssignable($user);

        $member = $organisation->members()->create($data);
        $member->load('user:id,name,email');

        AuditLog::log('organisation_member.added', $member, null, [
            'organisation' => $organisation->name,
            'user' => $user->email,
            'position' => $member->position,
        ]);

        return back()->with('success', "{$member->name_on_documents} added as {$member->position}.");
    }

    public function update(Request $request, ClientOrganisation $organisation, OrganisationMember $member)
    {
        $this->assertBelongsTo($organisation, $member);

        $data = $this->validated($request, $member);
        // The person is not editable here — a membership is a link between one
        // account and one company. Pointing it at somebody else would silently
        // reassign everything they had raised.
        unset($data['user_id']);

        $tracked = ['position', 'display_name', 'can_approve_up_to', 'is_active'];
        $before = $member->only($tracked);

        $member->update($data);

        AuditLog::log('organisation_member.updated', $member, $before, $member->only($tracked));

        return back()->with('success', 'Member updated.');
    }

    public function destroy(ClientOrganisation $organisation, OrganisationMember $member)
    {
        $this->assertBelongsTo($organisation, $member);

        // Requests carry the membership so they can still name who asked, in
        // what capacity, after the person has moved on. Removing it would blank
        // the requester on their history — which the invoice has to print.
        if ($member->raisedRequests()->exists()) {
            return back()->with('error',
                "Cannot remove {$member->name_on_documents}: they raised "
                . $member->raisedRequests()->count()
                . ' request(s). Deactivate them instead — they will lose access '
                . 'but stay named on their own history.'
            );
        }

        $name = $member->name_on_documents;

        AuditLog::log('organisation_member.removed', $member, $member->only([
            'position', 'display_name',
        ]), null);

        $member->delete();

        return back()->with('success', "{$name} removed.");
    }

    private function validated(Request $request, ?OrganisationMember $member = null): array
    {
        $rules = [
            'position' => ['required', Rule::in(array_keys(OrganisationMember::POSITIONS))],
            'display_name' => 'nullable|string|max:190',
            // Null means no ceiling, which is what most approvers have. An
            // empty string from the form must land as null rather than zero —
            // a zero ceiling would block every approval they attempted.
            'can_approve_up_to' => 'nullable|numeric|min:0|max:99999999.99',
            'is_active' => 'nullable|boolean',
        ];

        if (!$member) {
            $rules['user_id'] = [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                // One membership per account. See the migration.
                Rule::unique('organisation_members', 'user_id'),
            ];
        }

        $data = $request->validate($rules);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['can_approve_up_to'] = $data['can_approve_up_to'] ?? null;

        return $data;
    }

    /**
     * Only a client account can act for a client.
     *
     * Attaching a technician or a PM here would give somebody inside TW a seat
     * on the client's side of an approval, which is the one boundary this
     * module exists to keep straight.
     */
    private function assertAssignable(User $user): void
    {
        if ($user->role !== User::ROLE_CLIENT) {
            throw ValidationException::withMessages([
                'user_id' => 'Only client accounts can act for a management company. '
                    . "{$user->name} is a {$user->role}.",
            ]);
        }
    }

    private function assertBelongsTo(ClientOrganisation $organisation, OrganisationMember $member): void
    {
        abort_unless($member->client_organisation_id === $organisation->id, 404);
    }
}
