<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Services\ProgressService;
use Illuminate\Console\Command;

/**
 * Re-read every job's percentage and status from its validated reports.
 *
 * The rollup used to take the most recently validated report rather than the
 * highest, and averaged sub-tasks while ignoring the lead's whole-job report.
 * Jobs already stored the wrong numbers when that was fixed — a job reading
 * 20% with an 85% report validated against it, or showing "Completed"
 * because one sub-technician finished their slice. Fixing the rule does not
 * fix those rows; this does.
 *
 * Billing is deliberately left alone. Milestones that already raised a
 * payment request keep it, and nothing re-fires.
 */
class RecalculateJobProgress extends Command
{
    protected $signature = 'jobs:recalculate-progress
                            {--job= : Limit to one service request id}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Recompute job progress and completion from validated progress reports';

    public function handle(ProgressService $progress): int
    {
        $query = ServiceRequest::query()->whereHas('progressReports', fn ($q) => $q->where('is_validated', true));

        if ($jobId = $this->option('job')) {
            $query->where('id', $jobId);
        }

        $dryRun = (bool) $this->option('dry-run');
        $changed = [];

        $query->orderBy('id')->chunkById(100, function ($jobs) use ($progress, $dryRun, &$changed) {
            foreach ($jobs as $job) {
                $before = [
                    'progress' => (int) $job->progress_percentage,
                    'status'   => $job->status,
                ];

                $progress->recalculate($job);
                $job->refresh();

                $after = [
                    'progress' => (int) $job->progress_percentage,
                    'status'   => $job->status,
                ];

                if ($before === $after) {
                    continue;
                }

                $changed[] = [
                    $job->request_id,
                    $before['progress'] . '%',
                    $after['progress'] . '%',
                    $before['status'],
                    $after['status'],
                ];

                // A dry run still had to run the recalculation to know the
                // outcome, so put the old values back.
                if ($dryRun) {
                    $job->update([
                        'progress_percentage' => $before['progress'],
                        'status'              => $before['status'],
                    ]);
                }
            }
        });

        if (empty($changed)) {
            $this->info('Every job already agrees with its validated reports.');
            return self::SUCCESS;
        }

        $this->table(['Job', 'Progress was', 'now', 'Status was', 'now'], $changed);
        $this->info(($dryRun ? 'Would correct ' : 'Corrected ') . count($changed) . ' job(s).');

        if ($dryRun) {
            $this->comment('Dry run — nothing was saved. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
