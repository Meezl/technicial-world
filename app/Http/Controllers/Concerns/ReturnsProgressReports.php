<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ProgressReport;
use App\Models\Technician;

/**
 * Deciding who a returned report is addressed to, for the two office desks
 * that send them back.
 *
 * Shared because admin and PM return reports by the same rules and had
 * already drifted once: the two validation messages differed, and a rule
 * written twice is a rule that will differ again.
 */
trait ReturnsProgressReports
{
    /**
     * The technician an office return is being addressed to, or null for the
     * lead — which stays the default, because routing a query through the
     * person who runs the site is usually right.
     *
     * Only somebody actually on the job can be named. A technician who has
     * left the job cannot answer for it, and a report addressed to them would
     * sit returned with nobody able to clear it.
     */
    private function returnAddresseeFor(ProgressReport $report, ?int $technicianId): ?Technician
    {
        if (!$technicianId) {
            return null;
        }

        $serviceRequest = $report->serviceRequest;

        if (!$serviceRequest || !$serviceRequest->hasTechnician($technicianId)) {
            abort(422, 'That technician is not on this job, so they cannot answer this report.');
        }

        // Naming the lead is the same thing as not naming anybody: the report
        // goes to the lead either way, and storing it both ways would give the
        // same state two readings.
        if ($serviceRequest->isLeadTechnician($technicianId)) {
            return null;
        }

        return Technician::with('user:id,name')->find($technicianId);
    }
}
