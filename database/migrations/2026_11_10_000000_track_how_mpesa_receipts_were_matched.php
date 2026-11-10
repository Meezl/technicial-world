<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record how each M-Pesa receipt was matched, and how much of it was applied.
 *
 * A single `reconciled` flag could not say "KES 2,000 of this went to the
 * deposit and nothing else was due yet", so part payments either sat in the
 * unmatched queue or were matched against a bill they did not cover. These
 * columns let one receipt be applied across the bills it actually pays and
 * keep whatever is left visible until a bill is raised for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            // unmatched | matched | needs_review | duplicate | dismissed.
            // Null on STK push rows, which are matched by checkout id.
            $table->string('match_status', 20)->nullable()->after('reconciled')->index();
            // The REQ the bill reference resolved to, even when nothing on it
            // could be paid yet.
            $table->foreignId('service_request_id')->nullable()->after('payment_request_id')->index();
            $table->decimal('allocated_amount', 12, 2)->default(0)->after('amount');
            // [{payment_request_id, code, label, amount, part}]
            $table->json('allocations')->nullable()->after('match_status');
            $table->string('match_method', 20)->nullable()->after('allocations'); // auto | manual
            $table->text('match_note')->nullable()->after('match_method');
            $table->foreignId('matched_by')->nullable()->after('match_note');
            $table->timestamp('matched_at')->nullable()->after('matched_by');
        });

        // Paybill rows already reconciled were applied whole to one bill.
        DB::table('mpesa_transactions')
            ->where('source', 'c2b')
            ->where('reconciled', true)
            ->update([
                'match_status' => 'matched',
                'allocated_amount' => DB::raw('COALESCE(amount, 0)'),
                'match_method' => 'auto',
            ]);

        DB::table('mpesa_transactions')
            ->where('source', 'c2b')
            ->where('reconciled', false)
            ->update(['match_status' => 'unmatched']);

        // Carry the REQ across for rows already linked to a bill.
        $links = DB::table('mpesa_transactions')
            ->join('payment_requests', 'payment_requests.id', '=', 'mpesa_transactions.payment_request_id')
            ->whereNull('mpesa_transactions.service_request_id')
            ->select('mpesa_transactions.id', 'payment_requests.service_request_id')
            ->get();

        foreach ($links as $link) {
            DB::table('mpesa_transactions')
                ->where('id', $link->id)
                ->update(['service_request_id' => $link->service_request_id]);
        }
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'match_status', 'service_request_id', 'allocated_amount', 'allocations',
                'match_method', 'match_note', 'matched_by', 'matched_at',
            ]);
        });
    }
};
