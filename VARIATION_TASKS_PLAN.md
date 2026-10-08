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

### Phase 3 — Proposing and approving the task — **DONE**

`Admin\VariationTaskController`, with three routes:

- `POST /variations/{variationOrder}/tasks` — propose. Admin or PM, since
  drafting is not deciding. Refused under a **declined or void** variation
  (nothing was bought); allowed under a draft or pending-client one, because
  organising the work while the client decides is the normal case.
- `POST /variation-tasks/{serviceSubTask}/approve` — **admin only**. The reply
  says whether the task is live or still waiting on the variation, so nobody
  discovers that at the assign step.
- `POST /variation-tasks/{serviceSubTask}/decline` — admin only, reason
  required, and refused on a task already staffed.

Admin-only is checked in the controller rather than on the route, so a PM gets
an explanation instead of a 403. Original scope cannot be approved or declined
at all — there is no second consent to give.

Declining **keeps the row**, via `declined_by` / `declined_at` /
`decline_reason` (migration `2026_10_07_000001`). A PM who proposed work and
found the row silently gone would propose it again, and whether the job needed
that task is worth keeping. Approving a declined task clears the refusal.

Guards added where work becomes real:

- `assignSubTaskTechnician` and `updateSubTaskCompensation` refuse a task that
  is not live, quoting `blockedReason()`.
- `updateSubTaskProgress` refuses it too — checked **before** the closed-job
  rule, so a technician is told the approval is missing rather than that the job
  is closed.
- A **live variation task is exempt from the closed-job rule**: the point of
  buying work on a finished job is that somebody then does it. Original scope on
  a closed job is still refused.

Covered by `VariationTaskApprovalTest` — fifteen cases, including the PM who
cannot approve their own proposal, the staffed task that cannot be declined from
under its technician, and reporting on a closed job for a live variation task
but not for original scope.

### Phase 4 — Funding the work — **DONE**

`VariationOrderService::extendBudgetForVariation()`, called from `approve()`
inside the same transaction as the status change — a variation approved without
its budget following would bill the client for work nobody could be assigned to.
Both approval paths, the client's and the internal one, funnel through
`approve()`, so there is one hook rather than two.

Decisions made in the building:

- **All three categories move, not only labour.** Labour is what blocks
  staffing, but a variation that buys materials and leaves the materials budget
  untouched reports the job as overspent for the rest of its life.
- **A zero-income variation moves the budget too**, which is the point of
  recording one: no revenue, but a real cost the office has taken on.
- **A variation opens a budget where there was none.** It is the only money
  anybody has sanctioned on that job, and without a budget row the work cannot
  be staffed at all.
- **Deductions reduce the budget, floored at what is already committed.**
  Descoping work somebody is staffed on is a conversation about unassigning
  them; silently cutting the budget under a live assignment would make the job
  unpayable. The floor is written into the audit entry so the discrepancy is
  visible rather than inferred. `committedLabour()` uses the same arithmetic as
  `getLaborAllocationSummary()`, so the two cannot disagree.

Covered by `VariationBudgetTest` — nine cases, including the end-to-end one:
labour fully committed to the quoted work, a variation approved, its task
admitted, and the technician then assignable within the raised budget.

### Phase 5 — Letting the work be done on a finished job — **DONE**

`JobService::reopenForVariationWork()`, through `transitionState()` so the
reopen lands in the job's state history and the audit log with the variation
that caused it. Called from **both** moments a task can become live — the
variation being approved, and the task being admitted — since either can be the
last consent.

Decisions made in the building:

- **Only live, unfinished work reopens a job.** A variation that moves money
  alone leaves it closed, which is the original polished-concrete case: the work
  was already done and the client simply owed for it.
- **Cancelled and archived jobs are left alone.** Cancelled means the work never
  happened; reviving it through a variation is the wrong instrument.
- **No new client notification.** A priced variation is approved by the client
  themselves in their portal, so they already know the job is going back to
  work; a zero-income one never reaches them by design.
- **Unfinished variation work holds the job open.** A reopened job still carries
  the lead's old 100% whole-job report, so without this the next recompute would
  declare it finished again while the new work was still being done — the reopen
  and the rollup fighting each other. Once the variation work reaches 100% the
  job returns to `completed_pending_confirmation` for the office, which is the
  right destination.
- **The reopen that already existed now says so.** A recompute with no whole-job
  sign-off behind it still flips a completed job back to `in_progress` — the
  alternative is a job claiming to be finished on arithmetic that no longer
  supports it — but it writes an audit entry explaining itself instead of
  happening silently.

`updateSubTaskProgress()` already exempted live variation tasks from the
closed-job rule in Phase 3.

Covered by `VariationReopenTest` — eight cases, including both approval orders,
the money-only variation that changes nothing, the cancelled job that stays
cancelled, and the recompute that must not re-close a job with work outstanding.

### Phase 6 — Seeing it — **DONE**

- **Job page:** one list, grouped by what authorised the work — "Original
  scope", then "Added by REQ-x/VO-01" with the variation's reason, its status,
  its charge (or "Internal — no client charge"), and its own percentage. A task
  that is not live is drawn dashed and carries a panel saying what it waits on,
  with the admin's Approve / Turn down buttons.
- **Job board:** the quoted work and the variation work are counted apart —
  "4 sub-tasks" and "+2 under variation". Shown whether or not the job is split,
  since a single-technician job that later gained variation work would otherwise
  say nothing about it.
- **Variations panel:** a "Work added" block under each variation's money lines,
  listing its tasks with their state, its own percentage, and a one-field form
  to propose another.

`task_state` is appended to each sub-task by the model, so the rules about which
consents a task needs are not re-derived in JavaScript. `variationProgress` is
passed as its own prop, keyed by variation id.

Three things the screen taught that the plan had not:

- **Offer only the decision that is outstanding.** A task already admitted and
  waiting on the variation was being shown an "Approve task" button, which does
  nothing. It now reads "Admitted by X. It starts once the variation itself is
  settled."
- **Hide Assign on work that is not live.** The server refuses it, but a control
  that cannot work is worse than the panel that explains why.
- **"Awaiting approval" was wrong on an admitted task** in the variations panel —
  it is the variation holding it up, not a missing sign-off, and the old wording
  sent the reader to the wrong decision. Now "Waiting on this variation".

### Phase 7 — Corporate invoicing after closure — **DONE**

**Chosen: a second invoice, not void-and-reissue.** Voiding would rewrite an
invoice the client may already have paid, filed in their own accounts or had
certified for withholding. The un-invoiced part goes out as its own invoice
instead, carrying only the lines it covers.

`raiseHeldInvoice()` no longer returns early whenever an invoice exists. It asks
what has not been billed and raises an invoice for that:

- `whatIsNotYetInvoiced()` decides from the **lines already written**, not by
  comparing totals. A line carries the variation it bills, so "has VO-02 been
  invoiced" has an exact answer where arithmetic on totals would guess.
- The quotation line goes on the first invoice only.
- Nothing outstanding means the existing invoice is returned, as before — so a
  job closed twice still bills once.

Two further decisions:

- **A post-closure deduction raises no invoice.** A negative invoice is not a
  document we issue; descoping work already paid for leaves the client in
  credit, which `RefundService::jobsInUnhandledCredit()` surfaces and a refund
  settles. Consistent with how `approve()` already treats a descope.
- **A supplementary invoice spends its own float.** `DepositService::consume()`
  was one-per-job, which is what stops a job closed twice from being charged
  twice. A supplementary invoice is genuinely more money owed, so it passes its
  invoice number as a reference and is told apart by it — the float drives
  dispatch decisions, and leaving it out would report more of the client's money
  available than they have left. Deliberately narrow: an entry written before
  references were used is still matched by the default path, so nothing already
  on the ledger can be spent a second time.

Where the invoice is raised depends on what the variation bought. One that only
moves money leaves the job closed and is invoiced at approval. One that buys
work reopens the job (Phase 5) and is invoiced when the job closes again, with
everything else outstanding. Failure to raise is logged, not thrown — the same
reasoning as closure itself, where an invoice problem must not unwind a decision
the client has already given.

Covered by `PostClosureVariationInvoiceTest` — ten cases, including the two
invoices summing to the contract value, re-closing raising nothing, the float
spent once per invoice, the deduction that raises nothing, and retail jobs
raising no invoice at all.

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

---

## 8. All seven phases are built

What is not done, and known:

- **Approving an internal variation goes through a `confirm()` dialog**, which
  could not be clicked in the test browser. Verified through the service
  instead; it is the one path never exercised by a real click.
- **No technician-app view of a variation task.** A technician sees it in their
  sub-task list like any other work, which is correct, but nothing tells them it
  was added later or which variation bought it.
- **The client sees variation work only as the variation they approved.** There
  is no per-task progress on their portal, by the separate-track decision — the
  job's percentage is still the quoted work.
- **`multi_slot_audit.sql` has not been run against production.** It lists the
  technicians underpaid by the Phase 1 bug, whose next sheet will be larger by
  the arrears.
