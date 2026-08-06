<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProgressReport;
use App\Services\ProgressService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Taking a progress report out of circulation, and putting it back.
 *
 * This is housekeeping, not adjudication. Sending a report back to the
 * technician — the argument about what was actually done — is a different
 * action and lives on the validate/return endpoints.
 */
class ProgressReportController extends Controller
{
    public function __construct(private ProgressService $progress)
    {
    }

    public function destroy(Request $request, ProgressReport $progressReport)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $this->progress->deleteReport($progressReport, $request->user()->id, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return back()->with('success', 'Report removed. Progress has been recalculated from the remaining reports.');
    }

    public function restore(Request $request, ProgressReport $progressReport)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if (!$progressReport->trashed()) {
            return back()->withErrors(['report' => 'That report has not been removed.']);
        }

        try {
            $this->progress->restoreReport($progressReport, $request->user()->id, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return back()->with('success', 'Report restored and progress recalculated.');
    }

    /**
     * Overrule a lead technician's sign-off. Admin only — a PM who disagrees
     * with a lead should send the report back and have the conversation.
     */
    public function overrideLead(Request $request, ProgressReport $progressReport)
    {
        $data = $request->validate([
            'validated_percent'    => 'required|integer|min:0|max:100',
            'reason'               => 'required|string|max:1000',
            'validation_notes'     => 'nullable|string|max:2000',
            'client_visible_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $this->progress->overrideLeadSignoff(
                $progressReport,
                $request->user(),
                (int) $data['validated_percent'],
                $data['reason'],
                collect($data)->only(['validation_notes', 'client_visible_notes'])->all()
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return back()->with('success', "Lead sign-off overruled and set to {$data['validated_percent']}%.");
    }
}
