# Tasks under a variation

Adding work to a job after the quotation has been agreed — including on a job
that has finished — by hanging the work item off the variation that authorised
it, and letting only an admin approve it.

Status: **plan, not built.** Written 7 Oct 2026. Target branch:
`feat/corporate-segment-foundations`.

---

## 1. The questions asked, answered

**Can we raise a zero-rated variation with tasks, or a normal priced one with
tasks?**

Yes, both, and the distinction already exists. `VariationOrder::origin` has
three values:

| origin | Money | Who agrees to it |
| --- | --- | --- |
| `client` | Client pays | Client approves in their portal |
| `tw` | Client pays | Client approves in their portal |
| `zero_income` | Nothing billed | Office approves internally (`approve-internal`) |

A `zero_income` variation is forced to `is_client_visible = false` at creation
and never reaches the client. So "zero-rated with tasks" and "priced with
tasks" differ only in who signs the commercial side. The task machinery below
is identical for both.

**Can a variation card carry tasks?**

A variation *card* is the corporate client's ask — a caretaker raises it, their
senior manager agrees it, and only then does the office price it into a
variation order. The card holds no money and no work; it is the request. Tasks
therefore hang off the **variation order**, and a card that has been priced
into one inherits them through `variation_orders.variation_card_id`, which
already exists.

**Can they all be seen on the REQ/Job board?**

Yes, with work. Today the board shows `sub_tasks.length` as one number and the
job page shows sub-tasks and variations in two unrelated panels. Variation
tasks must be visible in both without being confused for original scope — see
§4.

**Combined, but each one unique?**

One list, grouped by what authorised it. A task belongs to exactly one
variation, or to the original scope, and never both — enforced by a nullable
`variation_order_id` on the task. The combined view is a presentation; the
ownership is a column.

**Only an admin approves the tasks under a variation?**

Yes, and it needs a new gate. Variation routes are currently
`role:admin,project_manager`, and even `approve-internal` admits a PM. Task
approval will be admin-only, enforced on the server in the same way
`overrideLead` and the fee-waiver classifications already are — not by hiding a
button.

---

## 2. Decisions taken

Both settled by the office on 7 Oct 2026: **separate track**, and **office-raised
variations are allowed on closed jobs** while client-raised cards stay refused.
The reasoning each way is kept below, since the alternatives will be asked about
again.

**2.1 Does variation work move the job's headline percentage? — No. Separate track.**

`headlinePercent()` is `max(highest validated whole-job report, average across
sub-tasks)`. If variation tasks join that average, a job finished at 100% drops
when a variation task is added and climbs back as it is done.

- **Chosen — separate track.** Original scope keeps its figure; variation
  tasks are reported and completed against their variation. The board reads
  "Closed · 100% · VO-02 in progress". Nothing already billed moves, and the
  client's record of what they verified stays intact.

  Consequence for the build: `headlinePercent()` and `aggregateSubTaskProgress()`
  must exclude tasks with a `variation_order_id`, and the variation needs a
  percentage of its own, computed from its tasks. `raiseDueMilestones()` keeps
  being handed the original-scope figure, so milestone billing on work already
  invoiced cannot move.
- **Alternative — enlarged scope.** The average includes variation tasks. Less
  code, but `raiseDueMilestones()` keys billing off `progress_percentage`, so
  this moves the number that drives billing on a job already billed. Would need
  tracing before it could be recommended.

**2.2 May a variation be raised on a closed job? — Yes, office-raised only.**

`VariationCardService::raise()` refuses today, deliberately:

> *"This job is closed. Additional work on it needs a new request rather than a
> variation."*

That is a documented policy decision (`PROPERTY_MANAGEMENT_MODULE_PLAN.md` §6
Phase 5), not an oversight. Allowing tasks on closed jobs reverses it. The
office-raised variation path (`VariationOrderService::create`) has no such
guard and already bills in full on approval for a finished job, so the two
paths disagree today.

**Chosen:** the card refusal stays — a caretaker asking for more work on a
finished job is still told to raise a new request, which is the promise the
module made to those accounts. The office-raised path
(`VariationOrderService::create`) is allowed on a closed job, deliberately, as
the escape hatch for work the office knows has to happen. That removes the
disagreement between the two paths by making it explicit rather than accidental.

Consequence for the build: the office path needs no new guard, but it does need
the audited reopen in Phase 5, and the closed-job case has to be visible on the
job page so nobody wonders why a closed job has live work on it.

---

## 3. What is already in place

- `variation_orders.service_request_id` — the tie to the REQ exists.
- `contractValue()` = quote + approved variations, so a priced variation already
  raises what may be billed.
- `VariationOrderService::approve()` already bills a finished job's variation in
  full on approval.
- `CompensationAmendment` already links a variation to a **change** in an
  existing technician's fee, with its own approval.
- `additional_days` on the variation already extends the programme.
- Sub-task assignment already creates a `JobAssignment` carrying the fee, which
  the payment sheet reads.

## 4. What is missing

1. **No work item under a variation.** `variation_order_items` are money lines
   (material / labour / transport, quantity × unit price). They are not tasks.
2. **The labour budget does not move with the variation.**
   `ensureLaborBudgetCapacity()` reads `service_request_budgets.labor_budget`,
   set by hand, with no relation to `contractValue()`. A variation with a
   KES 80,000 labour delta raises the contract and bills the client, but
   staffing its task is still refused against the old labour budget.
3. **Technicians cannot report on a finished job.**
   `updateSubTaskProgress()` refuses on `TERMINAL_STATUSES`, so a task added to
   a closed job can never be progressed, completed or paid.
4. **An accidental reopen already exists.** In `updateServiceRequestProgress()`,
   a job at `completed` or `completed_pending_confirmation` with no lead
   sign-off is flipped back to `in_progress` whenever progress is recomputed —
   silently, with no audit entry and no client notice. `closed` is not in that
   list, so a closed job instead keeps its status and shows a lower percentage.
   Both are wrong, in opposite directions.
5. **One technician holding two tasks is paid for one.**
   `resolveApprovedAmount()` takes `orderByDesc('id')->first()` — the newest
   assignment for that technician on that job. The payment sheet groups by
   (technician, job), so there is no second row to catch it. Variation tasks
   make this common rather than rare.
6. **The board cannot tell the two kinds apart.** `sub_tasks.length` would
   silently include variation tasks.
7. **The corporate invoice is idempotent per job.** `raiseHeldInvoice()` returns
   the existing invoice, so a variation on a closed-and-invoiced corporate job
   needs its own invoice or a void-and-reissue.

---

## 5. Build order

Each phase ships and is testable on its own. Phase 1 is a prerequisite for the
rest because variation tasks make its bug routine.

### Phase 1 — Pay a technician for every task they hold — **DONE**

`resolveApprovedAmount()` now reads one slot at a time. A slot is a sub-task, or
the job itself for a technician staffed directly. Within a slot only the newest
assignment counts, which is what stops a re-assignment being paid twice; across
slots they are summed, which is what stops a technician holding two tasks being
paid for one.

A row left at `reassigned` still counts in its slot — somebody taken off work
part-way is owed for what they did, and their arrears are settled from the same
figure.

This aligns the payout with the labour budget, which has always committed the
full amount: `getLaborAllocationSummary()` sums the job-level assignments and
every sub-task fee.

No migration. Covered by `TechnicianMultiTaskPayoutTest` — ten cases including
the two-task sum, the single-task and single-technician cases unchanged, a
re-assignment not paid twice, a reassigned technician keeping their claim, and a
crew member paid through their lead still owed nothing.

**Before deploying:** run `multi_slot_audit.sql` against production to see which
technician–job pairs hold more than one slot. Those are the people who have been
underpaid, and the figure will rise for them on the next sheet. `old_figure` in
that query is roughly what the previous code would have returned, so the
difference is the arrears.

### Phase 2 — The task knows its variation — **DONE**

Migration on `service_sub_tasks`:

- `variation_order_id` — nullable FK, `nullOnDelete`. Null means original scope,
  which is every existing row, so nothing changes for work already on the books.
- `approved_by` / `approved_at` — who admitted this task to the job, and when.
  Null on a variation task means proposed; null on an original-scope task means
  nothing, since those need no approval.

`ServiceSubTask`: `variationOrder()`, `isVariationTask()`, `isApproved()`, and
`isLive()` — approved **and** its variation approved. Scopes `originalScope()`
and `underVariation()`.

A task is live, and therefore staffable and reportable, only when **both** hold:

- its variation is approved — by the client for a priced one, internally for a
  zero-income one; and
- an **admin** has approved the task itself.

Original-scope tasks keep today's behaviour exactly: no variation, no approval
step.

**Built.** `variation_order_id`, `approved_by` and `approved_at` on
`service_sub_tasks`; `variationOrder()`, `approver()`, `originalScope()`,
`underVariation()`, `isVariationTask()`, `isApproved()`, `isLive()` and
`blockedReason()` on `ServiceSubTask`; `subTasks()` on `VariationOrder`.

The separate track went in with it, because without it the first variation task
would have corrupted the job's percentage:

- `aggregateSubTaskProgress()` averages **original scope only**.
- `isSplitIntoSubTasks()` and `recalculateProgress()` read original scope, so a
  variation task cannot flip a single-technician job into crew presentation on
  the board.
- `ProgressService::variationPercent()` gives a variation its own figure,
  counting only admitted tasks — a task still waiting on an admin is not work in
  progress at 0%.

`blockedReason()` names which consent is missing, and distinguishes a
zero-income variation (waiting on the office) from a priced one (waiting on the
client), so the endpoints in Phase 3 have something to say.

Covered by `VariationTaskModelTest` — nine cases including both consents in
either order, the 100% job that stays at 100%, and the single-technician job
that stays single.

### Phase 3 — Proposing and approving the task

- `POST /variations/{variationOrder}/tasks` — propose a task. Admin or PM, since
  drafting is not deciding. Refused once the variation is locked.
- `POST /sub-tasks/{serviceSubTask}/approve-task` — **admin only**, enforced in
  the controller, with a reason recorded in the audit log.
- `POST /sub-tasks/{serviceSubTask}/decline-task` — admin only, needs a reason.
- Assignment, fee and progress endpoints refuse a task that is not live, with a
  message naming what is missing: the client's approval, the internal approval,
  or an admin's sign-off on the task.

### Phase 4 — Funding the work

On variation approval, raise `service_request_budgets.labor_budget` by the
variation's `labor_delta`, in the same transaction, audited. Without this,
Phase 3 produces tasks nobody can be assigned to. A zero-income variation with
a labour delta is the interesting case: no client revenue, but a real cost — it
still raises the labour budget, and that is the point of recording it.

### Phase 5 — Letting the work be done on a finished job

- An explicit, audited reopen when a variation with live tasks is approved on a
  terminal job: status to `in_progress`, a note on the job, the client told if
  the variation is client-visible.
- Tighten the accidental reopen in `updateServiceRequestProgress()` so status
  only ever changes deliberately.
- `updateSubTaskProgress()` permits progress on a live variation task even where
  the job is otherwise finished.

### Phase 6 — Seeing it

- **Job page:** one sub-task list, grouped "Original scope" and then per
  variation with its VO number, each variation group showing its status and
  whether the tasks are approved. Proposed tasks are visibly not live.
- **Job board:** split the count — "4 sub-tasks · 2 under VO-02" — so a
  variation task is never mistaken for original scope.
- **Variations panel:** each variation lists the tasks it bought and their
  progress.

### Phase 7 — Corporate invoicing after closure

Decide and implement: a second invoice for the variation, or void-and-reissue.
Only reachable once §2.2 is settled in favour of allowing it.

---

## 6. Risks

- **Billing already raised.** A variation on a job the client has paid for
  changes the contract value. Phase 4 moves the labour budget; §2.1 decides
  whether it moves the percentage that drives milestone billing. Getting that
  wrong re-bills or under-bills a finished job.
- **The reopen is visible to the client.** A job that read "Closed" on their
  portal going back to "In progress" needs to be explained by the variation
  they approved, not appear as a mistake.
- **Two approval axes.** Commercial (client or internal) and task (admin) can
  disagree: an approved variation with a declined task, or an approved task on a
  declined variation. `isLive()` has to be the only thing anything consults.
- **Lead pipeline.** A variation task assigned to a crew member on a lead-run
  job enters the lead's ratify-and-post flow. On a job whose lead has gone, the
  office must be able to pull those reports in — which it now can.

## 7. Testing

Feature tests per phase, plus end to end: raise a zero-income variation on a
closed job → propose two tasks → PM cannot approve them → admin approves one
and declines one → the approved task is staffable within the raised labour
budget → the technician reports progress on the reopened job → the payment sheet
pays them for it alongside their original task.

Everything lands on `feat/corporate-segment-foundations` for testing before
main.
