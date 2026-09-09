<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The buildings in one management company's portfolio.
 *
 * Admin-managed, the same way service categories are, because the brief asks
 * for exactly that: pre-set per client so the caretaker picks from a dropdown
 * rather than typing a building name that will not match the last one.
 */
class PropertyController extends Controller
{
    public function store(Request $request, ClientOrganisation $organisation)
    {
        $property = $organisation->properties()->create($this->validated($request, $organisation));

        AuditLog::log('property.created', $property, null, $property->only([
            'name', 'code', 'owner_name', 'owner_kra_pin', 'is_active',
        ]));

        return back()->with('success', "{$property->label} added.");
    }

    public function update(Request $request, ClientOrganisation $organisation, Property $property)
    {
        $this->assertBelongsTo($organisation, $property);

        $tracked = ['name', 'code', 'address', 'owner_name', 'owner_kra_pin', 'is_active', 'sort_order'];
        $before = $property->only($tracked);

        $property->update($this->validated($request, $organisation, $property));

        AuditLog::log('property.updated', $property, $before, $property->only($tracked));

        return back()->with('success', 'Property updated.');
    }

    public function destroy(ClientOrganisation $organisation, Property $property)
    {
        $this->assertBelongsTo($organisation, $property);

        // A property is stamped on every document raised against it. Deleting
        // one out from under a request would leave an invoice unable to say
        // which building the work was done in — which is the one thing the
        // brief insists an invoice must say.
        if ($property->serviceRequests()->exists()) {
            return back()->with('error',
                "Cannot delete {$property->label}: "
                . $property->serviceRequests()->count()
                . ' request(s) were raised against it. Deactivate it instead — '
                . 'it will stop appearing in the picker but stay on its history.'
            );
        }

        $label = $property->label;

        AuditLog::log('property.deleted', $property, $property->only(['name', 'code']), null);

        $property->delete();

        return back()->with('success', "{$label} deleted.");
    }

    private function validated(Request $request, ClientOrganisation $organisation, ?Property $property = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:190',
                // Scoped to the company: two management firms may perfectly
                // well each look after a building called "Riverside Court".
                Rule::unique('properties', 'name')
                    ->where('client_organisation_id', $organisation->id)
                    ->ignore($property?->id),
            ],
            'code' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:500',
            'owner_name' => 'nullable|string|max:190',
            'owner_kra_pin' => 'nullable|string|max:30',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    /**
     * Routes are nested, so the ids arrive independently and a mismatched pair
     * would otherwise edit another company's building.
     */
    private function assertBelongsTo(ClientOrganisation $organisation, Property $property): void
    {
        abort_unless($property->client_organisation_id === $organisation->id, 404);
    }
}
