<?php

namespace App\Console\Commands;

use App\Services\CorporateDigestService;
use App\Support\CorporateModule;
use Illuminate\Console\Command;

/**
 * The one daily report each management company gets.
 *
 * Runs hourly and sends to the accounts whose own chosen hour has come, so a
 * client whose day ends at four is not sent yesterday's picture at six. An
 * account that has already had its digest today is skipped, and one with
 * nothing to report is not sent an empty email — a daily report that arrives
 * blank every weekend teaches people to ignore it.
 */
class SendCorporateDailyReports extends Command
{
    protected $signature = 'corporate:daily-reports
                            {--organisation= : Send for one organisation only}
                            {--force : Ignore the configured hour}';

    protected $description = 'Send each management company its one consolidated daily progress report';

    public function handle(CorporateDigestService $digests): int
    {
        if (!CorporateModule::enabled()) {
            $this->info('Corporate module is off — nothing to do.');

            return self::SUCCESS;
        }

        $organisations = $this->option('organisation')
            ? \App\Models\ClientOrganisation::where('id', $this->option('organisation'))->get()
            : ($this->option('force')
                ? \App\Models\ClientOrganisation::active()->get()
                : $digests->dueNow());

        if ($organisations->isEmpty()) {
            $this->info('No accounts due this hour.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($organisations as $organisation) {
            // One account's failure must not stop the rest: the whole point of
            // a scheduled digest is that it goes out without anybody watching.
            try {
                $digest = $digests->send($organisation);
            } catch (\Throwable $e) {
                $this->error("{$organisation->name}: {$e->getMessage()}");
                \Illuminate\Support\Facades\Log::error('Corporate daily report failed', [
                    'organisation' => $organisation->name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$digest) {
                $this->line("{$organisation->name}: nothing to report.");
                continue;
            }

            $sent++;
            $this->info(sprintf(
                '%s: %s — %d job(s), %d update(s)%s',
                $organisation->name,
                $digest->reference,
                $digest->job_count,
                $digest->report_count,
                $digest->wasSent() ? '' : ' (SEND FAILED)',
            ));
        }

        $this->info("Sent {$sent} daily report(s).");

        return self::SUCCESS;
    }
}
