<?php

namespace App\Console\Commands;

use App\Models\JobAuthorisation;
use App\Models\User;
use App\Notifications\JobAuthorisationExpiring;
use App\Notifications\JobAuthorisationLapsed;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Tell somebody before an advance authorisation runs out, and again when it
 * has.
 *
 * Expiry is evaluated at the moment of asking, so the gate closes on its own
 * with or without this command — nothing here enforces anything. What it
 * changes is who finds out and when. Without it the first person to learn that
 * an authorisation lapsed is a technician standing outside a locked site, and
 * a job left running past its cover is not noticed by anyone at all.
 */
class SweepJobAuthorisations extends Command
{
    protected $signature = 'authorisations:sweep
                            {--dry-run : List what would be sent without sending anything}
                            {--warn-hours=48 : How long before expiry to send the warning}';

    protected $description = 'Warn on advance authorisations nearing expiry, and report those that have lapsed.';

    public function handle(BillingService $billing): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $warnHours = max(1, (int) $this->option('warn-hours'));

        $warned = $this->sweepExpiring($warnHours, $dryRun);
        $lapsed = $this->sweepLapsed($billing, $dryRun);

        $this->newLine();
        $this->info($dryRun
            ? "Dry run — would warn on {$warned} and report {$lapsed} lapsed."
            : "Warned on {$warned}, reported {$lapsed} lapsed.");

        return self::SUCCESS;
    }

    /** Authorisations inside the warning window that have not been warned about. */
    private function sweepExpiring(int $warnHours, bool $dryRun): int
    {
        $due = JobAuthorisation::with(['serviceRequest.user', 'authoriser'])
            ->whereNull('revoked_at')
            ->whereNull('expiry_warning_sent_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addHours($warnHours))
            ->get();

        $this->info("{$due->count()} authorisation(s) lapsing within {$warnHours}h.");

        foreach ($due as $authorisation) {
            $job = $authorisation->serviceRequest;
            if (!$job) {
                continue;
            }

            $this->line("  → {$job->request_id} — {$authorisation->label()}, lapses "
                . $authorisation->expires_at->format('d M H:i'));

            if ($dryRun) {
                continue;
            }

            $this->notify($authorisation, new JobAuthorisationExpiring($authorisation));
            $authorisation->update(['expiry_warning_sent_at' => now()]);
        }

        return $due->count();
    }

    /**
     * Authorisations that have run out without anyone being told.
     *
     * Revoked ones are excluded: somebody withdrew it deliberately and already
     * knows. Only a silent lapse is news.
     */
    private function sweepLapsed(BillingService $billing, bool $dryRun): int
    {
        $lapsed = JobAuthorisation::with(['serviceRequest.user', 'authoriser'])
            ->whereNull('revoked_at')
            ->whereNull('lapse_notified_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $this->info("{$lapsed->count()} authorisation(s) newly lapsed.");

        foreach ($lapsed as $authorisation) {
            $job = $authorisation->serviceRequest;
            if (!$job) {
                continue;
            }

            // Work under way with no deposit and nothing else covering it is
            // the case worth shouting about: the job did not stop, so nothing
            // else will surface it.
            $stillCovered = JobAuthorisation::where('service_request_id', $job->id)
                ->where('id', '!=', $authorisation->id)
                ->live()
                ->exists();

            $runningUncovered = $job->started_at !== null
                && !$stillCovered
                && $billing->grossSettled($job) <= 0;

            $this->line("  → {$job->request_id} — lapsed "
                . $authorisation->expires_at->format('d M H:i')
                . ($runningUncovered ? '  [RUNNING UNCOVERED]' : ''));

            if ($dryRun) {
                continue;
            }

            $this->notify($authorisation, new JobAuthorisationLapsed($authorisation, $runningUncovered));
            $authorisation->update(['lapse_notified_at' => now()]);
        }

        return $lapsed->count();
    }

    /**
     * Whoever has to act: the admin who granted it, and the PM carrying the
     * job. Not the whole admin table — a notice everybody gets is a notice
     * nobody owns.
     */
    private function notify(JobAuthorisation $authorisation, $notification): void
    {
        $recipients = new Collection([
            $authorisation->authoriser,
            $authorisation->serviceRequest?->assigned_pm_id
                ? User::find($authorisation->serviceRequest->assigned_pm_id)
                : null,
        ]);

        $recipients = $recipients->filter()->unique('id');

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify($notification);
            } catch (\Throwable $e) {
                // One undeliverable address must not stop the rest of the
                // sweep, and the mark is still set — a bounced warning is not
                // a reason to re-send the same one hourly forever.
                Log::warning('Job authorisation notification failed', [
                    'job_authorisation_id' => $authorisation->id,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
