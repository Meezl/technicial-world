<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Services\NotificationService;
use App\Services\ResponseTargetService;
use App\Support\OfficeHours;
use Illuminate\Console\Command;

/**
 * Remind admins, PMs and the office inbox about requests nobody has answered.
 *
 * This ran every two hours round the clock, which between midnight and dawn
 * is seven emails nobody can act on. What that teaches a team is that the
 * channel can be ignored, and a reminder people ignore is worse than none —
 * it costs the same attention and buys nothing. The screenshot that prompted
 * this was reminder number eight on a low-urgency job.
 *
 * So reminders now keep office hours, run at a pace set by the urgency the
 * client chose, and stop. Repetition without escalation is noise: after
 * `escalate_after` the wording stops nudging and says plainly that the
 * promise has been missed, and after `max_reminders` the emails stop
 * altogether. The request stays on every queue and dashboard that shows
 * unactioned work — not being emailed about is not the same as forgotten.
 *
 * Intervals are counted from the original alert rather than the last
 * reminder, so a sweep that runs late does not push every later one back with
 * it. Reminders stop on their own once the request is acted on; see
 * ServiceRequest::scopeAwaitingOfficeAction().
 */
class RemindUnactionedRequests extends Command
{
    protected $signature = 'rfq:remind-unactioned
                            {--dry-run : List what would be sent without sending anything}';

    protected $description = 'Remind the office, during working hours, about new client requests nobody has acted on.';

    public function handle(NotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targets = ResponseTargetService::make();
        $hours = OfficeHours::fromConfig();
        $now = now();

        // Nothing goes out while the office is shut. The sweep still runs —
        // it is cheap, and leaving it running means the first reminder of the
        // morning lands on time rather than whenever somebody restarts it.
        if (!$hours->isOpen($now)) {
            $this->info('Office is closed — no reminders sent.');

            return self::SUCCESS;
        }

        $cap = (int) config('service_levels.max_reminders', 6);

        $due = ServiceRequest::awaitingOfficeAction()
            ->with(['serviceCategory', 'user'])
            ->get()
            ->filter(function (ServiceRequest $sr) use ($targets, $hours, $now, $cap) {
                // Nagged enough. It is still on the queues; it is no longer
                // worth an email.
                if ($sr->office_reminder_count >= $cap) {
                    return false;
                }

                $interval = $targets->reminderIntervalFor($sr->urgency);

                // The first reminder is not due until the office has actually
                // missed what the client was told. Reminding somebody they are
                // late before they are is how the old wording ended up
                // accusing the team of breaking a promise nobody had made.
                $firstDueAt = $sr->response_due_at
                    ?? $hours->addWorkingHours($sr->office_alerted_at, $targets->allowanceFor($sr->urgency));

                $dueAt = $sr->office_reminder_count === 0
                    ? $firstDueAt
                    : $hours->addWorkingHours($firstDueAt, $interval * $sr->office_reminder_count);

                return $dueAt->lte($now);
            });

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
