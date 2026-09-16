<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Remind admins, PMs and the office inbox, every two hours, about new client
 * requests nobody has acted on.
 *
 * The client is told their request will be acted on within 2 hours, so the
 * first reminder lands exactly when that promise is broken. Reminders are due
 * at alert + 2h, alert + 4h, … — counted from the original alert rather than
 * the last reminder, so a sweep that runs late does not push every later
 * reminder back with it.
 *
 * Reminders stop on their own once the request is acted on; see
 * ServiceRequest::scopeAwaitingOfficeAction().
 */
class RemindUnactionedRequests extends Command
{
    protected $signature = 'rfq:remind-unactioned
                            {--dry-run : List what would be sent without sending anything}';

    protected $description = 'Remind the office every two hours about new client requests nobody has acted on.';

    private const INTERVAL_HOURS = 2;

    public function handle(NotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $due = ServiceRequest::awaitingOfficeAction()
            ->with(['serviceCategory', 'user'])
            ->get()
            ->filter(fn (ServiceRequest $sr) => $sr->office_alerted_at
                ->copy()
                ->addHours(self::INTERVAL_HOURS * ($sr->office_reminder_count + 1))
                ->lte(now()));

        $this->info("{$due->count()} unactioned request(s) due a reminder.");

        foreach ($due as $sr) {
            $reminder = $sr->office_reminder_count + 1;
            $this->line("  → {$sr->request_id} — reminder #{$reminder}");

            if ($dryRun) {
                continue;
            }

            // Mark first: a failed send is logged inside the service, and must
            // not turn into the same reminder going out every sweep.
            $sr->forceFill(['office_reminder_count' => $reminder])->saveQuietly();
            $notifications->alertOfficeAboutRfq($sr, $reminder);
        }

        return self::SUCCESS;
    }
}
