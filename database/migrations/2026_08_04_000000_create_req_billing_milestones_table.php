<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote the `service_requests.billing_milestones` JSON blob to a real table.
 *
 * The blob carried a `triggered` boolean and nothing else. Revising a quote
 * rewrote the blob with `triggered => false` on every entry, so approving the
 * revision re-raised a payment request for every milestone the job's progress
 * had already passed — including ones the client had paid. A client who had
 * settled KES 72,000 was billed the full 79,500 again after a 7,500 variation.
 *
 * Each milestone now owns the payment request it raised. "Has this been
 * billed?" is answered by a foreign key that survives any number of revisions,
 * so the double-billing is impossible by construction rather than by
 * remembering to reset a flag correctly.
 *
 * NOTE: deliberately NOT named `billing_milestones` — a `payment_milestones`
 * table already exists and means something different (releasing labour money
 * to technicians). Keeping the client-billing concept clearly separate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('req_billing_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->decimal('progress_pct', 5, 2);
            $table->decimal('amount', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);

            // The bill this milestone raised. NULL = not yet billed. Once set,
            // the milestone is settled business and can never bill again.
            $table->foreignId('payment_request_id')->nullable()
                ->constrained('payment_requests')->nullOnDelete();
            $table->timestamp('triggered_at')->nullable();

            $table->timestamps();

            $table->index(['service_request_id', 'progress_pct']);
        });

        $this->backfillFromJson();

        // Keep the old data rather than dropping it — cheap insurance, and it
        // frees the `billing_milestones` name so the model can expose an
        // accessor of that name for the frontend without colliding with a
        // real column.
        if (Schema::hasColumn('service_requests', 'billing_milestones')) {
            Schema::table('service_requests', function (Blueprint $table) {
                $table->renameColumn('billing_milestones', 'billing_milestones_legacy');
            });
        }
    }

    /**
     * Copy every milestone out of the JSON blob. Where a milestone was already
     * triggered we try to find the payment request it produced so paid history
     * survives the move; the auto-generated notes carry the milestone label,
     * which is the only link the old schema left us.
     */
    private function backfillFromJson(): void
    {
        if (!Schema::hasColumn('service_requests', 'billing_milestones')) {
            return;
        }

        DB::table('service_requests')
            ->select('id', 'billing_milestones')
            ->whereNotNull('billing_milestones')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $milestones = json_decode($row->billing_milestones, true);
                    if (!is_array($milestones)) {
                        continue;
                    }

                    foreach (array_values($milestones) as $i => $m) {
                        if (!isset($m['label'], $m['progress_pct'], $m['amount'])) {
                            continue;
                        }

                        $paymentRequestId = null;
                        $triggeredAt = null;

                        if (!empty($m['triggered'])) {
                            $match = DB::table('payment_requests')
                                ->where('service_request_id', $row->id)
                                ->where('amount', $m['amount'])
                                ->where('notes', 'like', '%"' . $m['label'] . '"%')
                                ->orderBy('id')
                                ->first();

                            $paymentRequestId = $match->id ?? null;
                            $triggeredAt = $match->created_at ?? now();
                        }

                        DB::table('req_billing_milestones')->insert([
                            'service_request_id' => $row->id,
                            'label'              => $m['label'],
                            'progress_pct'       => $m['progress_pct'],
                            'amount'             => $m['amount'],
                            'sort_order'         => $i,
                            'payment_request_id' => $paymentRequestId,
                            'triggered_at'       => $triggeredAt,
                            'created_at'         => now(),
                            'updated_at'         => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_requests', 'billing_milestones_legacy')) {
            Schema::table('service_requests', function (Blueprint $table) {
                $table->renameColumn('billing_milestones_legacy', 'billing_milestones');
            });
        }

        Schema::dropIfExists('req_billing_milestones');
    }
};
