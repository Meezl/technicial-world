<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ServiceSubTask;
use App\Models\User;
use App\Models\VariationOrder;
use Illuminate\Http\Request;

/**
 * The work a variation bought, proposed and then admitted to the job.
 *
 * A variation has always been able to raise the contract and bill for extra
 * work; what it could not do was create the work item. Its own lines are money
 * — material, labour, transport — and nothing turned them into something a
 * technician could be given, report on and be paid for.
 *
 * Two steps on purpose. A PM who can see what the site needs proposes the
 * task; only an admin admits it to the job. That split is the office's, not a
 * technical one: a task carries a fee against the labour budget and a place in
 * the payment sheet, and the person authorising that should be the person
 * answerable for the job's money.
 *
 * See VARIATION_TASKS_PLAN.md §5 Phase 3.
 */
class VariationTaskController extends Controller
{
    /**
     * Propose a task under a variation. Admin or PM — drafting is not
     * deciding.
     */
    public function store(Request $request, VariationOrder $variationOrder)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        // A void variation bought nothing. An approved one is immutable as a
        // commercial document, but the work it authorised is still being
        // organised — that is the whole point of approving it — so tasks may
        // be proposed against it.
        if ($variationOrder->status === VariationOrder::STATUS_VOID) {
            return back()->with('error',
                "{$variationOrder->vo_number} was withdrawn. Raise a new variation for work that still needs doing.");
        }

        if ($variationOrder->status === VariationOrder::STATUS_DECLINED) {
            return back()->with('error',
                "The client declined {$variationOrder->vo_number}. Raise a new variation rather than adding work to a refused one.");
        }

        $serviceRequest = $variationOrder->serviceRequest;

        $task = ServiceSubTask::create([
            'service_request_id' => $serviceRequest->id,
            'variation_order_id' => $variationOrder->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => ServiceSubTask::STATUS_PENDING,
            // Ordered after everything already on the job, original scope
            // included, so the job page reads in the order work was added.
            'order' => ($serviceRequest->subTasks()->max('order') ?? 0) + 1,
        ]);

        AuditLog::log(AuditLog::ACTION_CREATED, $task, null, [
            'variation_order' => $variationOrder->vo_number,
            'proposed_by' => $request->user()->id,
        ]);

        return back()->with('success',
            "Task proposed under {$variationOrder->vo_number}. An admin approves it before it can be staffed.");
    }

    /**
     * Admit the task to the job. Admin only.
     *
     * Checked here rather than on the route so the refusal can say why, and so
     * a PM following a link gets an explanation instead of a 403.
     */
    public function approve(Request $request, ServiceSubTask $serviceSubTask)
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        if ($refusal = $this->mustBeAdminOnAVariationTask($request->user(), $serviceSubTask, 'approve')) {
            return $refusal;
        }

        if ($serviceSubTask->isApproved()) {
            return back()->with('error', 'That task has already been approved.');
        }

        $serviceSubTask->forceFill([
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            // Approving answers an earlier refusal: the task is back on the
            // job, and leaving the decline stamps would keep it blocked.
            'declined_by' => null,
            'declined_at' => null,
            'decline_reason' => null,
        ])->save();

        AuditLog::log(AuditLog::ACTION_APPROVAL, $serviceSubTask, null, [
            'variation_order' => $serviceSubTask->variationOrder?->vo_number,
            'note' => $data['note'] ?? null,
        ]);

        $blocked = $serviceSubTask->fresh()->blockedReason();

        return back()->with('success', $blocked
            // Approved, but the variation itself is not settled — say so now
            // rather than letting somebody discover it at the assign step.
            ? "Task approved. It cannot be staffed yet: {$blocked}"
            : 'Task approved. It can now be staffed and reported on.');
    }

    /**
     * Turn the task down, with a reason the proposer can read. Admin only.
     *
     * The row is kept rather than deleted. A PM who proposed work and found it
     * silently gone would propose it again, and whether the job needed that
     * task is worth keeping on the record.
     */
    public function decline(Request $request, ServiceSubTask $serviceSubTask)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ], [
            'reason.required' => 'Say why, so whoever proposed it knows where it stands.',
            'reason.min' => 'Give them something to act on — a few words at least.',
        ]);

        if ($refusal = $this->mustBeAdminOnAVariationTask($request->user(), $serviceSubTask, 'decline')) {
            return $refusal;
        }

        if ($serviceSubTask->technician_id) {
            return back()->with('error',
                'That task is already staffed. Unassign the technician before turning the work down.');
        }

        $serviceSubTask->forceFill([
            'declined_by' => $request->user()->id,
            'declined_at' => now(),
            'decline_reason' => $data['reason'],
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        AuditLog::log(AuditLog::ACTION_UPDATED, $serviceSubTask, null, [
            'declined' => true,
            'variation_order' => $serviceSubTask->variationOrder?->vo_number,
            'reason' => $data['reason'],
        ]);

        return back()->with('success', 'Task turned down. The reason is on the record.');
    }

    /**
     * Only an admin, and only on a task a variation bought.
     *
     * Original scope has no approval step — the quotation the client signed is
     * its authority, and inventing a sign-off for it would block work that is
     * already agreed.
     */
    private function mustBeAdminOnAVariationTask(User $actor, ServiceSubTask $task, string $verb)
    {
        if (!$task->isVariationTask()) {
            return back()->with('error',
                'That task is part of the original quotation, so there is nothing to ' . $verb . '.');
        }

        if ($actor->role !== User::ROLE_ADMIN) {
            return back()->with('error',
                'Only an admin may ' . $verb . ' work added under a variation.');
        }

        return null;
    }
}
