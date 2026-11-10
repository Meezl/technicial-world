<?php

namespace App\Console\Commands;

use App\Models\MpesaTransaction;
use App\Services\MpesaReconciliationService;
use Illuminate\Console\Command;

/**
 * Safety net for paybill money still waiting on a bill.
 *
 * Bills raised normally settle waiting receipts the moment they are created.
 * This sweep catches whatever that missed — a bill created by a raw update, a
 * hook that failed, a REQ created after the client paid against its number.
 */
class RematchMpesaReceipts extends Command
{
    protected $signature = 'mpesa:rematch {--days=90 : Only look at receipts received in this many days}';

    protected $description = 'Re-try matching unmatched or part-applied paybill receipts to their REQ bills';

    public function handle(MpesaReconciliationService $reconciliation): int
    {
        $matched = 0;

        MpesaTransaction::openPaybill()
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->each(function (MpesaTransaction $txn) use ($reconciliation, &$matched) {
                if ($txn->unapplied_amount <= 0) {
                    return;
                }

                if ($reconciliation->markIfAlreadySettled($txn)) {
                    $matched++;
                    return;
                }

                $before = (float) $txn->allocated_amount;
                $reconciliation->autoMatch($txn);

                if ((float) $txn->fresh()->allocated_amount > $before) {
                    $matched++;
                }
            });

        $this->info("Applied {$matched} waiting receipt(s).");

        return self::SUCCESS;
    }
}
