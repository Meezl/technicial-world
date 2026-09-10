<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\RateItem;
use App\Models\RateSchedule;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The button that turns a list of things into a quotation.
 *
 * "The request will be posted to us and we shall just click one button to
 * auto-populate costs / Rates. The rates will fill in, auto-multiply with the
 * quantities and give us a quotation in thirty seconds."
 *
 * Everything expensive about quoting a small job is the typing, and the typing
 * is what this removes. What it does not remove is judgement: the office still
 * adds the permits, the night-shift allowance and the dates, and still decides
 * whether the figures are right before signing them.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 7.
 */
class QuotationComposerService
{
    /**
     * Fill in the rates for everything the client picked.
     *
     * Rates are copied onto the line, not looked up when the quotation is read.
     * A quote sent in March has to keep saying March's figures when the shop
     * price of tiles moves in April — the same reason an invoice stores its
     * tax.
     *
     * Re-runnable: a line already priced is re-priced from the current
     * schedule, which is what the office wants when a rate review lands
     * mid-quotation. Ancillary lines are left alone, because they were typed by
     * hand and there is nothing to look them up in.
     */
    public function autoPopulate(ServiceRequest $request, ?User $user = null): array
    {
        $schedule = RateSchedule::forOrganisation($request->organisation);

        if (!$schedule) {
            throw new RuntimeException(
                'There is no active rate schedule for this client, and no house list to fall back on.'
            );
        }

        $priced = 0;
        $unmatched = [];

        DB::transaction(function () use ($request, $schedule, &$priced, &$unmatched) {
            foreach ($request->items()->where('kind', ServiceRequestItem::KIND_CATALOGUE)->get() as $line) {
                $rateItem = $line->rate_item_id
                    ? RateItem::find($line->rate_item_id)
                    : $schedule->activeItems()->where('code', $line->code)->first();

                // A line whose catalogue item has since been retired is left
                // unpriced and named, rather than quietly priced at zero.
                if (!$rateItem) {
                    $unmatched[] = $line->description;
                    continue;
                }

                $line->fill([
                    'rate_item_id' => $rateItem->id,
                    'code' => $rateItem->code,
                    'unit' => $rateItem->unit,
                    'material_rate' => $rateItem->material_rate,
                    'labour_rate' => $rateItem->labour_rate,
                    'transport_rate' => $rateItem->transport_rate,
                    'consumable_rate' => $rateItem->consumable_rate,
                    'overhead_rate' => $rateItem->overhead_rate,
                    'margin_rate' => $rateItem->margin_rate,
                    'is_priced' => true,
                ])->save();

                $priced++;
            }

            $request->update(['rate_schedule_id' => $schedule->id]);
        });

        $this->recalculate($request->fresh());

        AuditLog::log('quotation.auto_populated', $request, null, [
            'schedule' => $schedule->name . ' v' . $schedule->version,
            'priced' => $priced,
            'unmatched' => count($unmatched),
        ]);

        return ['priced' => $priced, 'unmatched' => $unmatched, 'totals' => $this->totals($request->fresh())];
    }

    /**
     * Add something the catalogue does not have.
     *
     * "I will then study the quotation and add any other ancillary costs at the
     * bottom e.g. approval permits, Night Shift Allowances." Priced straight
     * onto the margin component so the line has a rate without pretending it
     * has a material cost.
     */
    public function addAncillary(ServiceRequest $request, array $data): ServiceRequestItem
    {
        $line = $request->items()->create([
            'kind' => ServiceRequestItem::KIND_ANCILLARY,
            'description' => $data['description'],
            'unit' => $data['unit'] ?? RateItem::UNIT_LOT,
            'quantity' => $data['quantity'] ?? 1,
            'margin_rate' => $data['unit_price'] ?? 0,
            'location_detail' => $data['location_detail'] ?? null,
            'is_priced' => true,
            'sort_order' => ((int) $request->items()->max('sort_order')) + 1,
        ]);

        $this->recalculate($request->fresh());

        return $line;
    }

    /**
     * What the quotation comes to.
     *
     * Lines are VAT-exclusive and VAT is added at the bottom — "VAT will keep
     * adjusting until I am done" — so adding an ancillary line moves the tax
     * without anybody recalculating it.
     *
     * The VAT here is derived by subtraction from a rounded gross for the same
     * reason invoicing does it: so ex-VAT plus VAT is always exactly the gross
     * rather than a cent adrift.
     */
    public function totals(ServiceRequest $request): array
    {
        $lines = $request->items()->get();

        $exVat = round($lines->sum(fn($l) => (float) $l->line_total), 2);
        $vatRate = $this->vatRateFor($request);
        $gross = round($exVat * (1 + $vatRate / 100), 2);

        return [
            'subtotal_ex_vat' => $exVat,
            'vat_rate' => $vatRate,
            'vat_amount' => round($gross - $exVat, 2),
            'total_inc_vat' => $gross,
            'line_count' => $lines->count(),
        ];
    }

    /**
     * Write the composed figures onto the request.
     *
     * The corporate approval chain, the float and the invoice all read the flat
     * quote_* columns, so a quotation composed from the catalogue has to land
     * in the same place a hand-typed one does. Anything else would mean two
     * kinds of quotation the rest of the system has to tell apart.
     */
    public function recalculate(ServiceRequest $request): array
    {
        $totals = $this->totals($request);
        $lines = $request->items()->get();

        $request->update([
            'quote_amount' => $totals['total_inc_vat'],
            'quote_labor_cost' => round($lines->sum(fn($l) => (float) $l->labour_rate * (float) $l->quantity), 2),
            'quote_transport_cost' => round($lines->sum(fn($l) => (float) $l->transport_rate * (float) $l->quantity), 2),
        ]);

        return $totals;
    }

    /**
     * Sign the quotation before it goes out.
     *
     * "I will then put click a button that will enable me to put a digital
     * signature on the quote and post it to the Verifier / Approver."
     */
    public function sign(ServiceRequest $request, User $user, ?string $signaturePath = null): ServiceRequest
    {
        if ($request->items()->where('is_priced', false)->exists()) {
            throw new RuntimeException('Every line has to be priced before the quotation can be signed.');
        }

        $request->update([
            'quote_signed_by' => $user->name,
            'quote_signature_path' => $signaturePath,
            'quote_signed_at' => now(),
        ]);

        AuditLog::log('quotation.signed', $request, null, [
            'signed_by' => $user->name,
            'amount' => $request->quote_amount,
        ]);

        return $request->fresh();
    }

    // ==================== Projections ====================

    /**
     * One quotation, four audiences.
     *
     * The brief asks for this in three separate places and they are the same
     * mechanism: a field mask plus a line filter over one canonical document.
     * Building three exports instead would guarantee they eventually disagree
     * about what the job is.
     *
     *   office     — everything
     *   client     — dates, locations, quantities; rates only if opened
     *   security   — items and dates, never money
     *   technician — only their own lines, and only once opened to them
     */
    public function project(ServiceRequest $request, string $audience, ?int $technicianId = null): array
    {
        $lines = $request->items()->with('rateItem:id,description')->get();

        $showMoney = match ($audience) {
            'office' => true,
            'client' => (bool) $request->prices_visible_to_requester,
            default => false,
        };

        if ($audience === 'technician') {
            // Their lines, and only the ones opened to them. A technician
            // seeing the whole job would be reading scope somebody else is
            // being paid for.
            $lines = $lines->filter(fn($l) => $l->isOpenTo($technicianId))->values();
        }

        return [
            'audience' => $audience,
            'reference' => $request->quote_reference,
            'property' => $request->property?->label,
            'shows_money' => $showMoney,
            'signed_by' => $request->quote_signed_by,
            'signed_at' => $request->quote_signed_at,
            'lines' => $lines->map(fn($l) => array_filter([
                'description' => $l->description,
                'code' => $l->code,
                'unit' => $l->unit_label,
                'quantity' => (float) $l->quantity,
                'urgency' => $l->urgency,
                'location' => $l->location_detail,
                'planned_start' => $l->planned_start?->toDateString(),
                'planned_end' => $l->planned_end?->toDateString(),
                // array_filter drops nulls, which is how a masked field
                // disappears rather than showing as an empty column.
                'composite_rate' => $showMoney ? (float) $l->composite_rate : null,
                'line_total' => $showMoney ? (float) $l->line_total : null,
            ], fn($v) => $v !== null))->values()->all(),
            'totals' => $showMoney ? $this->totals($request) : null,
        ];
    }

    /** Open a line to the technician who is about to do it. */
    public function releaseToTechnician(ServiceRequestItem $line, int $technicianId): ServiceRequestItem
    {
        $line->update([
            'assigned_technician_id' => $technicianId,
            'released_to_technician_at' => now(),
        ]);

        return $line->fresh();
    }

    /** Close it again — the brief's "closing others and so on". */
    public function withdrawFromTechnician(ServiceRequestItem $line): ServiceRequestItem
    {
        $line->update(['released_to_technician_at' => null]);

        return $line->fresh();
    }

    private function vatRateFor(ServiceRequest $request): float
    {
        return $request->organisation?->vat_rate !== null
            ? (float) $request->organisation->vat_rate
            : (float) config('corporate.tax.vat_rate', 16);
    }
}
