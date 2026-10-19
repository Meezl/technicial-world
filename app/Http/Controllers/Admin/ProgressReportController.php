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

    /**
     * Tidy a settled report — its notes and its photographs — without
     * re-opening the argument about how far along the work is.
     */
    public function amend(Request $request, ProgressReport $progressReport)
    {
        $data = $request->validate([
            'client_visible_notes' => 'nullable|string|max:2000',
            'validation_notes' => 'nullable|string|max:2000',
            'remove_photo_ids' => 'nullable|array',
            'remove_photo_ids.*' => 'integer',
            'photos' => 'nullable|array|max:6',
            // Same rule as a technician's own upload: no `image`, because it
            // uses getimagesize() and that cannot read HEIC.
            'photos.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
        ]);

        try {
            $this->progress->amendReport(
                $progressReport,
                $request->user(),
                $data,
                $request->file('photos', [])
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return back()->with('success', $progressReport->fresh()->released_to_client_at
            ? 'Report updated. The client was sent the earlier version, so tell them if this changes what they were told.'
            : 'Report updated.');
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
