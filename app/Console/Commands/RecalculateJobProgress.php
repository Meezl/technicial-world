<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Services\ProgressService;
use Illuminate\Console\Command;

/**
 * Re-read every job's percentage and status from its validated reports.
 *
 * The rollup has been wrong in both directions. It took the most recently
 * validated report rather than the highest; then it let a lead's whole-job
 * report stand above the sub-tasks it covered, so a job could read "100% —
 * work is fully complete" above two tasks that had not been started. Jobs
 * already stored the wrong numbers each time the rule changed. Fixing the
 * rule does not fix those rows; this does — on a split job the figure comes
 * back to the sub-task average, and a sign-off that no longer holds takes the
 * job back to in progress.
 *
 * Expect this to lower some jobs. A job that was reading its lead's figure is
 * being brought back to what its tasks actually say, which is the point.
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
