<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\RateItem;
use App\Models\RateItemRevision;
use App\Models\RateSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The catalogue: four thousand priced things, and how they change.
 *
 * See the rate_schedules and rate_items migrations for why a schedule is a
 * version and why a rate is six figures rather than one.
 */
class RateScheduleService
{
    /**
     * Put a draft into service.
     *
     * The one it replaces becomes superseded rather than being deleted:
     * quotations raised against it cite it, and a schedule that vanished would
     * take the answer to "what were we charging in March" with it.
     */
    public function activate(RateSchedule $schedule, User $user): RateSchedule
    {
        if ($schedule->isActive()) {
            return $schedule;
        }

        if (!$schedule->items()->exists()) {
            throw new RuntimeException('A schedule with no items cannot be activated.');
        }

        return DB::transaction(function () use ($schedule, $user) {
            RateSchedule::query()
                ->where('client_organisation_id', $schedule->client_organisation_id)
                ->where('id', '!=', $schedule->id)
                ->where('status', RateSchedule::STATUS_ACTIVE)
                ->update(['status' => RateSchedule::STATUS_SUPERSEDED, 'updated_at' => now()]);

            $schedule->update([
                'status' => RateSchedule::STATUS_ACTIVE,
                'approved_by' => $user->id,
                'activated_at' => now(),
                'effective_from' => $schedule->effective_from ?? now()->toDateString(),
            ]);

            AuditLog::log('rate_schedule.activated', $schedule, null, [
                'name' => $schedule->name,
                'version' => $schedule->version,
                'items' => $schedule->items()->count(),
                'organisation' => $schedule->organisation?->name ?? 'house list',
            ]);

            return $schedule->fresh();
        });
    }

    /**
     * Start the next version of a live schedule.
     *
     * Copies the items so a rate review begins from what is in force rather
     * than a blank page — nobody is retyping four thousand rows to change six.
     */
    public function draftNextVersion(RateSchedule $schedule, User $user): RateSchedule
    {
        return DB::transaction(function () use ($schedule, $user) {
            $next = RateSchedule::create([
                'client_organisation_id' => $schedule->client_organisation_id,
                'name' => $schedule->name,
                'version' => (int) $schedule->version + 1,
                'status' => RateSchedule::STATUS_DRAFT,
                'notes' => $schedule->notes,
                'created_by' => $user->id,
            ]);

            foreach ($schedule->items()->cursor() as $item) {
                $copy = $item->replicate(['id', 'created_at', 'updated_at']);
                $copy->rate_schedule_id = $next->id;
                $copy->save();
            }

            return $next->fresh();
        });
    }

    /**
     * Change a rate, and record what moved.
     *
     * The revision is written here rather than by a model hook so the reason
     * can be carried with it — "shop price of granito up 500" is worth more
     * than a diff, and a hook has nowhere to put it.
     */
    public function updateItem(RateItem $item, array $attributes, ?User $user = null, ?string $reason = null): RateItem
    {
        $tracked = array_merge(array_keys(RateItem::COMPONENTS), ['description', 'unit', 'code', 'is_active']);
        $before = $item->only(array_merge($tracked, ['composite_rate']));

        $item->update($attributes);
        $item->refresh();

        $after = $item->only(array_merge($tracked, ['composite_rate']));

        if ($before != $after) {
            RateItemRevision::create([
                'rate_item_id' => $item->id,
                'changed_by' => $user?->id,
                'before' => $before,
                'after' => $after,
                'composite_delta' => round((float) $after['composite_rate'] - (float) $before['composite_rate'], 2),
                'reason' => $reason,
            ]);
        }

        return $item;
    }

    /**
     * Load a schedule from a spreadsheet export.
     *
     * Four thousand items is not something anybody types in. Columns are
     * matched by header name rather than position, because the list will come
     * out of whatever the office already keeps it in and the column order is
     * not ours to dictate.
     *
     * Returns what happened rather than throwing on the first bad row: an
     * import that dies on row 900 of 4,000 has told you nothing useful about
     * the other 3,100.
     */
    public function import(RateSchedule $schedule, array $rows, ?User $user = null): array
    {
        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            $row = $this->normaliseKeys($row);
            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '') {
                $skipped[] = ['row' => $index + 2, 'reason' => 'No description.'];
                continue;
            }

            $unit = $this->normaliseUnit($row['unit'] ?? null);

            if ($unit === null) {
                $skipped[] = ['row' => $index + 2, 'reason' => 'Unrecognised unit "' . ($row['unit'] ?? '') . '".'];
                continue;
            }

            $attributes = [
                'description' => $description,
                'code' => trim((string) ($row['code'] ?? '')) ?: null,
                'category' => trim((string) ($row['category'] ?? '')) ?: null,
                'search_terms' => trim((string) ($row['search_terms'] ?? '')) ?: null,
                'unit' => $unit,
            ];

            foreach (RateItem::COMPONENTS as $column => $label) {
                // Accept "material_rate" or plain "material" — an office
                // spreadsheet is unlikely to use our column names.
                $short = str_replace('_rate', '', $column);
                $attributes[$column] = $this->number($row[$column] ?? $row[$short] ?? 0);
            }

            $existing = $attributes['code']
                ? $schedule->items()->where('code', $attributes['code'])->first()
                : $schedule->items()->where('description', $description)->first();

            if ($existing) {
                $this->updateItem($existing, $attributes, $user, 'Bulk import');
                $updated++;
            } else {
                $schedule->items()->create($attributes);
                $created++;
            }
        }

        AuditLog::log('rate_schedule.imported', $schedule, null, [
            'created' => $created,
            'updated' => $updated,
            'skipped' => count($skipped),
        ]);

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** The catalogue a caretaker searches, for one client. */
    public function search(?ClientOrganisation $organisation, ?string $term, int $limit = 20)
    {
        $schedule = RateSchedule::forOrganisation($organisation);

        if (!$schedule) {
            return collect();
        }

        return $schedule->activeItems()
            ->matching($term)
            ->orderBy('description')
            ->limit($limit)
            ->get(['id', 'code', 'description', 'unit', 'category', 'composite_rate']);
    }

    /** Header names vary; ours do not. */
    private function normaliseKeys(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $clean = strtolower(trim((string) $key));
            $clean = preg_replace('/[^a-z0-9]+/', '_', $clean);
            $out[trim($clean, '_')] = $value;
        }

        return $out;
    }

    /**
     * "Sq.M", "sqm", "SQ M" and "square metres" are all the same unit.
     *
     * Returns null rather than guessing when it cannot tell: an item silently
     * imported as "No." when it meant cubic metres would price concrete by the
     * lump.
     */
    private function normaliseUnit(?string $unit): ?string
    {
        $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $unit));

        if ($clean === '') {
            return RateItem::UNIT_NUMBER;
        }

        return match (true) {
            in_array($clean, ['no', 'nr', 'number', 'each', 'ea', 'pcs', 'pc', 'item'], true) => RateItem::UNIT_NUMBER,
            in_array($clean, ['sqm', 'm2', 'sm', 'squaremetre', 'squaremetres', 'squaremeter'], true) => RateItem::UNIT_SQM,
            in_array($clean, ['cum', 'm3', 'cm', 'cubicmetre', 'cubicmetres'], true) => RateItem::UNIT_CUM,
            in_array($clean, ['lm', 'm', 'metre', 'metres', 'linearmetre'], true) => RateItem::UNIT_LM,
            in_array($clean, ['kg', 'kgs', 'kilo', 'kilos'], true) => RateItem::UNIT_KG,
            in_array($clean, ['hr', 'hrs', 'hour', 'hours'], true) => RateItem::UNIT_HOUR,
            in_array($clean, ['lot', 'lumpsum', 'ls', 'sum'], true) => RateItem::UNIT_LOT,
            default => null,
        };
    }

    private function number($value): float
    {
        // Spreadsheets export "4,500.00" and sometimes "KES 4,500".
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }
}
