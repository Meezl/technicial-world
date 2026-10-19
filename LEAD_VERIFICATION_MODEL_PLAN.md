# Lead verification model — separating work from the reporting line

## The change in one sentence

Every unit of work on a job becomes a row that carries a percentage — the
lead's own work included — and the lead role becomes an authority over
verification that owns no work at all.

## Why

`service_sub_tasks` currently means two things at once:

1. a unit of work that carries a `progress_percentage`, and
2. work belonging to somebody who is not the lead.

Those are independent, and fusing them is what produces every symptom we have
chased this week. A lead's own work has no row, so it has no percentage
anywhere; its only carrier is his whole-job `ProgressReport`. That is why the
job once read "100% — work is fully complete" above two tasks at 0%: the only
way his own progress could reach the headline was by overriding everything
else at once.

It is also why, today, **the only way to make a lead's own work count toward
the job is to take the lead role off him** — at which point ops cuts his work
into a sub-task and it starts counting. The figure changes because the role
changed, which is exactly backwards.

Separate the two concepts and the problems dissolve rather than get patched.
The percentage cannot move when the role moves, because the role was never a
term in it.

## Invariants

These are the acceptance criteria. Each one is a test, not a convention.

1. **Changing the lead never moves any percentage** — not a sub-task's, not the
   job's. *(Locked: `LeadChangeProgressInvarianceTest`.)*
   <!-- Phases 2 and the first half of 4 are done; 1, 3 and the rest of 4
        remain. Phase 1 is still waiting on the four decisions below. -->
2. **The job's headline is derived, never typed.** No screen offers a field
   that sets it.
3. **Nobody verifies their own work.** The crew's rows settle on the lead's
   word; the lead's own row settles on the office's.
4. **A percentage belongs to the work, not the person.** A technician leaving a
   task leaves its percentage where it is.
5. **Returning a claim takes the row to its last settled value, never to zero.**
   Bouncing a claim must not make the job appear to go backwards.

## Phases

### Phase 0 — lock the invariant *(done)*

`tests/Feature/LeadChangeProgressInvarianceTest.php` drives the real endpoints —
assign-lead, promote a task holder, hand the lead back, a lead declining — and
compares a full before/after snapshot of every percentage on the job. Plus
`JobProgressRollupTest::test_changing_the_lead_does_not_move_the_jobs_percentage`.

The audit behind it: no path that writes `lead_technician_id`
(`assignLeadTechnician`, the `assignSubTaskTechnician` backfill,
`unassignSubTaskTechnician`'s clear, `AssignmentResponseService::decline`)
touches any `progress_percentage`. The invariant holds today; these tests stop
it being lost.

### Phase 1 — the lead's work becomes a row

The structural change. A lead who is carrying work gets a row for it like
anybody else, created when the job is split rather than when he is stood down.

- Ops states, when splitting a job, whether the lead is carrying work or
  supervising only. Supervising-only is a legitimate state and must stay
  available — this is not "always create a row for the lead".
- Where he is carrying work, the row is created with it. The existing
  `absorbBareCrewPlace` machinery already folds his bare place into the row,
  carrying his fee, role and dates across (verified in
  `LeadReassignmentIntegrityTest::test_a_stood_down_lead_can_be_given_his_work_back_as_a_sub_task`).
- "His task becomes a sub-task if he vacates" then stops being a conversion.
  It already is one. Nothing migrates, nothing resets, the percentage is
  invariant by construction rather than by discipline.

**The backfill is the risky part.** Adding a row at 0% changes the denominator
and drags the job down — 45% and 63% average to 54%, and a third row at zero
takes it to 36%. This is demonstrated deliberately in
`test_cutting_new_work_into_a_sub_task_is_what_moves_the_figure`. So a
backfilled row must open at the percentage that work had actually reached, not
at zero. The only record of that is the lead's last validated whole-job report,
which is an estimate — so it is proposed, not applied, and ops confirms per job
before it goes live. Same dry-run shape as `jobs:recalculate-progress`.

### Phase 2 — give the lead his third verb *(done)*

He had two: approve as claimed, or send back with a reason. He cannot say
"it is less than that". `approveSubTaskReport` hard-codes the figure:

```php
['validated_percent' => $progressReport->percent_complete],   // TechnicianController:1640
```

`ProgressService::validate()` already took `validated_percent` from its
caller, so this was a field on the form plus a guard: **he may lower, never
raise**. Raising somebody's claim on their behalf is a different act and
stays with the office, where it already carries an override trail. Lowering
carries the obligation a rejection does — say what you saw — while an
approval left as filed still needs no note. The existing refusal on his own
row is unchanged and covered by a test. See `LeadSettlesBelowClaimTest`.

### Phase 3 — the verification sheet replaces the typed overall

The lead's whole-job number currently changes nothing. A field that silently
does nothing is worse than the bug it replaced, so it goes.

In its place: a sheet listing every work row on the job with its claimed
figure, **Confirm / Lower / Return** on each, and the average shown as a
derived, read-only result. His own row appears as a claim he files and the
office settles.

**The hard part, and the one most likely to be got wrong:** the average must
take the last *settled* value for his own row, never his pending claim.
Otherwise he moves the headline unilaterally, which is this morning's bug
wearing a different hat.

Sign-off then falls out for free: 100% means every row verified at 100%, and
the extra guard bolted onto `hasLeadSignOff` becomes redundant.

### Phase 4 — two latent defects this exposes

- ~~**Rejection does not walk the number back.**~~ *(done)* `reject()` now
  re-syncs the row and recomputes the job, falling back to the last settled
  value rather than to zero, with billing untouched. Found on the way: the
  shared recompute helper reverted a job on `STATUS_COMPLETED` but not on
  `COMPLETED_PENDING_CONFIRMATION`, so a sent-back sign-off left the job on
  the office's confirmation queue with nothing behind it — removal and
  restoration were carrying the same gap. The helper is now
  `recomputeFromCountingReports`, which is what all three callers do. See
  `RejectedReportProgressTest`.
- **Rows are last-wins, the job is highest-wins.** Under a verification model
  this resolves itself — a row's value *is* the lead's most recent
  verification — so last-wins becomes correct by definition and the asymmetry
  disappears rather than needing a patch.

### Phase 5 — separate decision, not part of this change

Fee-weighted averaging. 45% on a KSH 8,500 task and 63% on a KSH 12,500 task
averaging to 54% counts tasks, not value, and the billing milestones key off
that number. Weighting each row by its agreed fee is nearly a one-line change,
but it moves every live job's percentage and shifts milestone timing. Equal
votes is a choice currently being made by accident; it should be made
deliberately, either way.

## Decisions needed before Phase 1 starts

1. Is "lead supervising only, carrying no work" a state we support? (Assumed
   yes.)
2. What percentage does a backfilled lead row open at, and who confirms it?
3. Client-visible percentages will move on every job where a lead was carrying
   untracked work. Who tells the client, and do we hold the backfill until
   affected jobs close?
4. Weighted or unweighted (Phase 5).

## Explicitly not doing

- **Not** putting a `progress_percentage` on the lead's `JobAssignment`. It
  reads as the obvious fix and it breaks invariant 1 outright: the figure would
  move when the role moved.
- **Not** relying on a process rule that ops must remember to cut the lead a
  sub-task. That gets the structure right but enforces it by discipline, which
  is what we are relying on now.
