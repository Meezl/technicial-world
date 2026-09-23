<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\CorporateReportDigest;
use App\Models\OrganisationMember;
use App\Models\ProgressReport;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One report a day, covering everything.
 *
 * A management company with a dozen buildings under way gets a dozen separate
 * progress emails a day under the retail behaviour, and the practical effect
 * is that none of them get read. The brief asks for the opposite: each job
 * still validated on its own, then all of them combined into a single update.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 6.
 */
class CorporateDigestService
{
    /**
     * Reports released to this client that no digest has carried yet.
     *
     * Selected on the digest mark rather than on a time window. A window would
     * mean a report released while the send was running is either counted
     * twice or dropped, and the client would never know which.
     */
    public function pendingReports(ClientOrganisation $organisation)
    {
        return ProgressReport::query()
            ->whereNull('corporate_digest_id')
            ->whereNotNull('released_to_client_at')
            ->whereHas('serviceRequest', fn($q) => $q
                ->where('segment', ServiceRequest::SEGMENT_CORPORATE)
                ->where('client_organisation_id', $organisation->id))
            ->with([
                'serviceRequest:id,request_id,description,property_id,status,progress_percentage',
                'serviceRequest.property:id,name,code',
                'subTask:id,title',
                'technician.user:id,name',
            ])
            ->orderBy('report_date');
    }

    /**
     * Everything waiting, grouped the way the client reads it: by job.
     *
     * The brief calls these segments — one section per job inside the one
     * report — which is what makes a dozen buildings legible in a single
     * email rather than a wall of updates.
     */
    public function segmentsFor(ClientOrganisation $organisation): array
    {
        return $this->pendingReports($organisation)
            ->get()
            ->groupBy('service_request_id')
            ->map(fn($reports) => [
                'request' => $reports->first()->serviceRequest,
                'reports' => $this->inOrder($reports),
                'progress' => $this->latestProgress($reports),
            ])
            ->values()
            ->all();
    }

    /**
     * Compile and send today's report, if there is anything to say.
     *
     * Returns null when there is nothing — a daily report that arrives empty
     * every weekend teaches people to ignore it.
     */
    public function send(ClientOrganisation $organisation, ?string $periodDate = null): ?CorporateReportDigest
    {
        $segments = $this->segmentsFor($organisation);

        if (empty($segments)) {
            return null;
        }

        $recipients = $this->recipientsFor($organisation);

        if (empty($recipients)) {
            Log::warning('Corporate daily report has nobody to go to', [
                'organisation' => $organisation->name,
            ]);

            return null;
        }

        $digest = DB::transaction(function () use ($organisation, $segments, $periodDate, $recipients) {
            $reportIds = collect($segments)->flatMap(fn($s) => $s['reports']->pluck('id'))->all();

            $digest = CorporateReportDigest::create([
                'reference' => CorporateReportDigest::nextReferenceFor($organisation),
                'client_organisation_id' => $organisation->id,
                'period_date' => $periodDate ?? now()->toDateString(),
                'job_count' => count($segments),
                'report_count' => count($reportIds),
                'sent_to' => implode(', ', $recipients),
            ]);

            // Claimed before the send, not after. If the mail fails we would
            // rather investigate one unsent digest than discover the next run
            // has reported the same week's work again.
            ProgressReport::whereIn('id', $reportIds)->update(['corporate_digest_id' => $digest->id]);

            return $digest;
        });

        try {
            foreach ($recipients as $email) {
                Mail::to($email)->send(new \App\Mail\CorporateDailyReport(
                    $organisation,
                    $digest,
                    $this->segmentsForDigest($digest),
                ));
            }

            $digest->update(['sent_at' => now()]);
        } catch (\Throwable $e) {
            // Recorded on the digest rather than swallowed: an unsent daily
            // report is exactly the sort of silence nobody notices.
            $digest->update(['send_error' => $e->getMessage()]);

            Log::error('Corporate daily report failed to send', [
                'digest' => $digest->reference,
                'error' => $e->getMessage(),
            ]);
        }

        AuditLog::log('corporate_digest.sent', $organisation, null, [
            'reference' => $digest->reference,
            'jobs' => $digest->job_count,
            'reports' => $digest->report_count,
            'sent' => $digest->wasSent(),
        ]);

        return $digest->fresh();
    }

    /** The segments as stored on a digest, for the email and for re-reading. */
    public function segmentsForDigest(CorporateReportDigest $digest): array
    {
        return $digest->reports()
            ->with([
                'serviceRequest:id,request_id,description,property_id,status,progress_percentage',
                'serviceRequest.property:id,name,code',
                'subTask:id,title',
                'technician.user:id,name',
            ])
            ->orderBy('report_date')
            ->get()
            ->groupBy('service_request_id')
            ->map(fn($reports) => [
                'request' => $reports->first()->serviceRequest,
                'reports' => $this->inOrder($reports),
                'progress' => $this->latestProgress($reports),
            ])
            ->values()
            ->all();
    }

    /**
     * Reports oldest first, with the day's filings in the order they were made.
     *
     * report_date alone is a date, not a time, so two reports filed on the same
     * day tie — and a tie broken arbitrarily reads as the crew going backwards.
     */
    private function inOrder($reports)
    {
        return $reports->sortBy([['report_date', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * The figure somebody scanning the email actually wants: the latest one.
     *
     * Same tie to break. Sorting on report_date alone picked whichever row the
     * database happened to return first, so a job reported twice in a day could
     * head its segment with the earlier percentage — which is exactly the thing
     * a progress report is read for.
     */
    private function latestProgress($reports): int
    {
        $latest = $this->inOrder($reports)->last();

        return (int) ($latest->validated_percent ?? $latest->percent_complete);
    }

    /**
     * Who gets the daily picture.
     *
     * Everyone who signs work off, plus the billing address. Deliberately not
     * the requesters: a caretaker wants their own job, and they already see it
     * on their dashboard — sending them the whole portfolio every evening
     * recreates the noise this exists to remove.
     */
    public function recipientsFor(ClientOrganisation $organisation): array
    {
        $emails = $organisation->members()
            ->where('is_active', true)
            ->whereIn('position', [
                OrganisationMember::POSITION_VERIFIER,
                OrganisationMember::POSITION_APPROVER,
            ])
            ->with('user:id,email')
            ->get()
            ->pluck('user.email')
            ->filter()
            ->all();

        if ($organisation->billing_email) {
            $emails[] = $organisation->billing_email;
        }

        return array_values(array_unique($emails));
    }

    /**
     * Companies whose report is due this hour and has not gone yet.
     *
     * The command runs hourly and each account picks its own hour, so a client
     * whose day ends at four is not sent yesterday's picture at six.
     */
    public function dueNow(?int $hour = null)
    {
        $hour ??= (int) now()->format('G');

        return ClientOrganisation::active()
            ->get()
            ->filter(fn(ClientOrganisation $org) => $org->dailyReportHour() === $hour)
            ->reject(fn(ClientOrganisation $org) => $org->reportDigests()
                ->whereDate('period_date', now()->toDateString())
                ->exists())
            ->values();
    }
}
