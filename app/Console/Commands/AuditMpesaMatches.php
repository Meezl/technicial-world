<?php

namespace App\Console\Commands;

use App\Models\MpesaTransaction;
use App\Models\User;
use App\Services\MpesaMatchAuditService;
use App\Services\MpesaReconciliationService;
use Illuminate\Console\Command;

/**
 * Lists M-Pesa receipts whose bills do not agree with the money received —
 * wrong amount, wrong bill type, or a different REQ from the account number —
 * and, with --fix, corrects the ones that can be corrected automatically.
 *
 * Dry run by default. --fix reverses each fixable match and re-applies the
 * money to the same REQ: deposit first, part payments split, excess held.
 */
class AuditMpesaMatches extends Command
{
    protected $signature = 'mpesa:audit-matches
                            {--receipt= : Check a single M-Pesa receipt}
                            {--fix : Correct every fixable finding}
                            {--as= : Email of the admin the corrections are recorded under (required with --fix)}';

    protected $description = 'Check that each M-Pesa receipt settled the right REQ, the right bill type and exactly the amount received';

    public function handle(MpesaMatchAuditService $audit, MpesaReconciliationService $reconciliation): int
    {
        $findings = $audit->findings($this->option('receipt'));

        if ($findings->isEmpty()) {
            $this->info('Every M-Pesa receipt matches what it settled.');
            return self::SUCCESS;
        }

        $this->table(
            ['Receipt', 'REQ', 'Received', 'Marked paid', 'Bills', 'Issues', 'Fixable'],
            $findings->map(fn($f) => [
                $f['receipt'],
                $f['service_request']['request_id'] ?? '—',
                number_format($f['received'], 2),
                number_format($f['settled'], 2),
                collect($f['bills'])->map(fn($b) => "{$b['label']} {$b['code']}")->implode(', '),
                collect($f['issues'])->pluck('message')->implode("\n"),
                $f['fixable'] ? 'yes' : ($f['blocker'] ?? 'review by hand'),
            ])->all()
        );

        if (!$this->option('fix')) {
            $this->line('Dry run. Re-run with --fix --as=<admin email> to correct the fixable ones.');
            return self::SUCCESS;
        }

        $admin = User::where('email', $this->option('as'))->first();
        if (!$admin) {
            $this->error('Pass --as=<email> of the admin these corrections should be recorded under.');
            return self::FAILURE;
        }

        $fixable = $findings->where('fixable', true);
        if (!$this->confirm("Correct {$fixable->count()} receipt(s)?")) {
            return self::SUCCESS;
        }

        foreach ($fixable as $f) {
            try {
                $txn = $reconciliation->correct(MpesaTransaction::findOrFail($f['transaction_id']), $admin);
                $this->info("{$f['receipt']}: {$txn->match_note}");
            } catch (\Throwable $e) {
                $this->error("{$f['receipt']}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
