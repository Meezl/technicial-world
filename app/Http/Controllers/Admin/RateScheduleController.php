<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientOrganisation;
use App\Models\RateItem;
use App\Models\RateSchedule;
use App\Services\RateScheduleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * The office keeping the catalogue.
 *
 * Four thousand items is not something anybody maintains a row at a time, so
 * the import is the primary way in and the item editor is for the handful that
 * move between reviews.
 */
class RateScheduleController extends Controller
{
    public function __construct(private RateScheduleService $rates)
    {
    }

    public function index()
    {
        return Inertia::render('Admin/Rates/Index', [
            'schedules' => RateSchedule::with('organisation:id,name')
                ->withCount('items')
                ->orderByDesc('status')
                ->orderBy('name')
                ->get(),
            'organisations' => ClientOrganisation::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => RateSchedule::STATUS_LABELS,
        ]);
    }

    public function show(Request $request, RateSchedule $schedule)
    {
        return Inertia::render('Admin/Rates/Schedule', [
            'schedule' => $schedule->load('organisation:id,name'),
            'items' => $schedule->items()
                ->matching($request->input('search'))
                ->orderBy('description')
                ->paginate(50)
                ->withQueryString(),
            'filters' => ['search' => $request->input('search', '')],
            'units' => RateItem::UNITS,
            'components' => RateItem::COMPONENTS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:190',
            'client_organisation_id' => 'nullable|exists:client_organisations,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $schedule = RateSchedule::create(array_merge($data, [
            'created_by' => $request->user()->id,
            'status' => RateSchedule::STATUS_DRAFT,
        ]));

        return redirect()
            ->route('admin.rates.show', $schedule)
            ->with('success', 'Draft schedule created. Import the rates next.');
    }

    public function activate(Request $request, RateSchedule $schedule)
    {
        try {
            $this->rates->activate($schedule, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Schedule activated. Quotations will price from it.');
    }

    public function draftNext(Request $request, RateSchedule $schedule)
    {
        $next = $this->rates->draftNextVersion($schedule, $request->user());

        return redirect()
            ->route('admin.rates.show', $next)
            ->with('success', "Version {$next->version} drafted from the live rates. Adjust and activate when ready.");
    }

    /**
     * Load a schedule from a spreadsheet.
     *
     * CSV rather than xlsx: it is what every spreadsheet exports, it needs no
     * library, and a 4,000-row import that streams is one that finishes.
     */
    public function import(Request $request, RateSchedule $schedule)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:20480',
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if (!$header) {
            fclose($handle);

            return back()->with('error', 'That file has no header row.');
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            // Ragged rows are the norm in exported spreadsheets; pad rather
            // than refuse the whole file over a trailing comma.
            $line = array_pad(array_slice($line, 0, count($header)), count($header), null);
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);

        $result = $this->rates->import($schedule, $rows, $request->user());

        $message = sprintf(
            '%d added, %d updated%s.',
            $result['created'],
            $result['updated'],
            $result['skipped'] ? ', ' . count($result['skipped']) . ' skipped' : ''
        );

        return back()
            ->with($result['skipped'] ? 'warning' : 'success', $message)
            ->with('importSkipped', array_slice($result['skipped'], 0, 25));
    }

    public function updateItem(Request $request, RateSchedule $schedule, RateItem $item)
    {
        abort_unless($item->rate_schedule_id === $schedule->id, 404);

        $rules = [
            'description' => 'required|string|max:255',
            'code' => 'nullable|string|max:60',
            'category' => 'nullable|string|max:80',
            'search_terms' => 'nullable|string|max:500',
            'unit' => ['required', Rule::in(array_keys(RateItem::UNITS))],
            'is_active' => 'nullable|boolean',
            'reason' => 'nullable|string|max:255',
        ];

        foreach (array_keys(RateItem::COMPONENTS) as $component) {
            $rules[$component] = 'required|numeric|min:0|max:99999999';
        }

        $data = $request->validate($rules);
        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        $data['is_active'] = $request->boolean('is_active', true);

        $this->rates->updateItem($item, $data, $request->user(), $reason);

        return back()->with('success', "{$item->description} updated.");
    }

    public function storeItem(Request $request, RateSchedule $schedule)
    {
        $rules = [
            'description' => 'required|string|max:255',
            'code' => 'nullable|string|max:60',
            'category' => 'nullable|string|max:80',
            'search_terms' => 'nullable|string|max:500',
            'unit' => ['required', Rule::in(array_keys(RateItem::UNITS))],
        ];

        foreach (array_keys(RateItem::COMPONENTS) as $component) {
            $rules[$component] = 'nullable|numeric|min:0|max:99999999';
        }

        $schedule->items()->create($request->validate($rules));

        return back()->with('success', 'Item added.');
    }

    /** The revision trail behind one rate. */
    public function itemHistory(RateSchedule $schedule, RateItem $item)
    {
        abort_unless($item->rate_schedule_id === $schedule->id, 404);

        return response()->json([
            'item' => $item->only(['id', 'description', 'composite_rate']),
            'breakdown' => $item->componentBreakdown(),
            'revisions' => $item->revisions()->with('changedBy:id,name')->limit(50)->get()
                ->map(fn($r) => [
                    'at' => $r->created_at,
                    'by' => $r->changedBy?->name,
                    'reason' => $r->reason,
                    'delta' => (float) $r->composite_delta,
                    'movements' => $r->movements(),
                ]),
        ]);
    }
}
