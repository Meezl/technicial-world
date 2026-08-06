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
        if (!$progressReport->trashed()) {
            return back()->withErrors(['report' => 'That report has not been removed.']);
        }

        $this->progress->restoreReport($progressReport, $request->user()->id);

        return back()->with('success', 'Report restored and progress recalculated.');
    }
}
