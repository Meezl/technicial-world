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

## 2. Two decisions needed before coding

**2.1 Does variation work move the job's headline percentage?**

`headlinePercent()` is `max(highest validated whole-job report, average across
sub-tasks)`. If variation tasks join that average, a job finished at 100% drops
when a variation task is added and climbs back as it is done.

- **Recommended — separate track.** Original scope keeps its figure; variation
  tasks are reported and completed against their variation. The board reads
  "Closed · 100% · VO-02 in progress". Nothing already billed moves, and the
  client's record of what they verified stays intact.
- **Alternative — enlarged scope.** The average includes variation tasks. Less
  code, but `raiseDueMilestones()` keys billing off `progress_percentage`, so
  this moves the number that drives billing on a job already billed. Would need
  tracing before it could be recommended.

**2.2 May a variation be raised on a closed job?**

`VariationCardService::raise()` refuses today, deliberately:

> *"This job is closed. Additional work on it needs a new request rather than a
> variation."*

That is a documented policy decision (`PROPERTY_MANAGEMENT_MODULE_PLAN.md` §6
Phase 5), not an oversight. Allowing tasks on closed jobs reverses it. The
office-raised variation path (`VariationOrderService::create`) has no such
guard and already bills in full on approval for a finished job, so the two
paths disagree today.

Options: keep the card refusal and allow only office-raised variations on
closed jobs (recommended — the client-facing promise stays, ops gets the
escape hatch); or lift it for both; or keep both closed and require a new REQ.

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

### Phase 1 — Pay a technician for every task they hold

`resolveApprovedAmount()` returns the fee for **one** assignment. Make it sum
the live assignments for that technician on that job, keyed per sub-task, while
keeping the re-assignment double-count it was written to avoid: sum the latest
live assignment **per sub-task**, plus the job-level one where there is no
sub-task. Regression tests for: one tech one task; one tech two tasks; a
reassignment superseded; a crew member paid through their lead (still zero).

No migration. Touches the payment sheet and the Pay Technicians screen, so it
needs the existing payment tests green plus new ones.

### Phase 2 — The task knows its variation

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
