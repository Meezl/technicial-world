<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\OrganisationMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin setup for property management companies.
 *
 * The account has to exist, with its buildings and its people, before anyone
 * can raise a corporate request — a caretaker picking a building from a
 * dropdown is picking from this. See PROPERTY_MANAGEMENT_MODULE_PLAN.md.
 */
class ClientOrganisationController extends Controller
{
    public function index()
    {
        $organisations = ClientOrganisation::query()
            ->withCount(['properties', 'members', 'serviceRequests'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Organisations/Index', [
            'organisations' => $organisations,
            'workflows' => ClientOrganisation::WORKFLOWS,
        ]);
    }

    public function show(ClientOrganisation $organisation)
    {
        $organisation->load([
            'properties' => fn($q) => $q->withCount('serviceRequests'),
            'members.user:id,name,email,phone',
            'creator:id,name',
        ]);

        return Inertia::render('Admin/Organisations/Show', [
            'organisation' => $organisation,
            'workflows' => ClientOrganisation::WORKFLOWS,
            'positions' => OrganisationMember::POSITIONS,
            // Client accounts not already spoken for. A user belongs to one
            // organisation, so offering somebody who is already a member of
            // another would produce a picker whose every third choice fails
            // validation.
            'assignableUsers' => User::query()
                ->where('role', User::ROLE_CLIENT)
                ->whereDoesntHave('organisationMembership')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            // What the account still needs before it can take work. Surfaced
            // as data rather than left for the admin to notice: an
            // organisation with no approver can raise requests that nobody is
            // able to say yes to.
            'readiness' => $this->readiness($organisation),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        $organisation = ClientOrganisation::create($data);

        AuditLog::log('client_organisation.created', $organisation, null, $organisation->only([
            'name', 'kra_pin', 'billing_email', 'approval_workflow', 'is_active',
        ]));

        return redirect()
            ->route('admin.organisations.show', $organisation)
            ->with('success', "{$organisation->name} created. Add its properties and people next.");
    }

    public function update(Request $request, ClientOrganisation $organisation)
    {
        $tracked = ['name', 'kra_pin', 'billing_email', 'approval_workflow', 'is_active'];
        $before = $organisation->only($tracked);

        $organisation->update($this->validated($request, $organisation));

        // The workflow setting decides how many of their people have to sign
        // off, so a change to it changes who is asked on every future
        // quotation. Worth being able to point at afterwards.
        AuditLog::log('client_organisation.updated', $organisation, $before, $organisation->only($tracked));

        return back()->with('success', 'Account updated.');
    }

    public function destroy(ClientOrganisation $organisation)
    {
        // Properties and members cascade, which is right — they mean nothing
        // without the company. Requests do not: they carry quotations,
        // variations and invoices, and deleting the account under them would
        // strand every one of those. Deactivating keeps the history readable.
        if ($organisation->serviceRequests()->exists()) {
            return back()->with('error',
                "Cannot delete {$organisation->name}: it has "
                . $organisation->serviceRequests()->count()
                . ' service request(s) on record. Deactivate the account instead.'
            );
        }

        $name = $organisation->name;

        // Logged before the delete: afterwards there is no model to hang the
        // entry on.
        AuditLog::log('client_organisation.deleted', $organisation, $organisation->only([
            'name', 'kra_pin', 'billing_email', 'approval_workflow',
        ]), null);

        $organisation->delete();

        return redirect()
            ->route('admin.organisations.index')
            ->with('success', "{$name} deleted.");
    }

    private function validated(Request $request, ?ClientOrganisation $organisation = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:190',
                Rule::unique('client_organisations', 'name')->ignore($organisation?->id),
            ],
            'kra_pin' => 'nullable|string|max:30',
            'billing_email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:500',
            'approval_workflow' => ['required', Rule::in(array_keys(ClientOrganisation::WORKFLOWS))],
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /**
     * Whether the account can actually take work yet.
     *
     * Three things have to be true: somewhere to do the work, somebody to ask
     * for it, and somebody who can say yes. The last one depends on the
     * workflow — a two-stage company needs a verifier as well, and finding
     * that out when the first quotation stalls is too late.
     */
    private function readiness(ClientOrganisation $organisation): array
    {
        $active = $organisation->members->where('is_active', true);

        $checks = [
            'has_property' => $organisation->properties->where('is_active', true)->isNotEmpty(),
            'has_requester' => $active->where('position', OrganisationMember::POSITION_REQUESTER)->isNotEmpty(),
            'has_approver' => $active->where('position', OrganisationMember::POSITION_APPROVER)->isNotEmpty(),
        ];

        if ($organisation->requiresTwoStageApproval()) {
            $checks['has_verifier'] = $active->where('position', OrganisationMember::POSITION_VERIFIER)->isNotEmpty();
        }

        return [
            'checks' => $checks,
            'ready' => !in_array(false, $checks, true),
        ];
    }
}
