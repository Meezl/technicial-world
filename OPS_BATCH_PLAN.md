# Ops batch — plan

Five requests from the product owner, 2026-09-07. Ordered by a mix of how much
daily friction each removes and how much of it already exists.

Two of these are much smaller than they look, because the machinery is already
in the codebase and pointed at the wrong thing.

---

## 5. Auto-generated passwords on user creation — ~0.5 day

**Now:** `AdminDashboardController::storeUser` takes `password` + `password_confirmation`
from the admin, so a human invents it, types it twice, and then has to convey it
to the user by some channel that is not the system.

**Change:** drop both fields from the form and the validator. Generate a
password server-side, mail it to the new user, BCC `info@technicianworld.co.ke`.
Force a change on first sign-in — a password that has travelled by email is
already shared, and treating it as permanent is the actual risk here.

- Remove `password` from the create form and its validation.
- `Str::password()` for generation.
- New `AccountCreatedNotification` carrying the credentials, replacing the
  contentless `WelcomeNotification` for this path.
- `must_change_password` on users, enforced by middleware.

**Note:** emailing a password is a compromise, not a best practice. The
alternative — mailing a signed set-password link and never generating a
password at all — is the same amount of work and strictly safer. Flagged for a
decision; building the requested version unless told otherwise.

---

## 2. Quotation drafts — ~1 day

**Now:** the quotation modal on the RFQ page holds everything in local
component state (`quotationForm`) and only persists on submit. Closing it, or
navigating anywhere, loses the lot — which is why it feels like a trap.

**Change:** one draft per service request, saved server-side, resumable.

- `quotation_drafts` table: `service_request_id` (unique), `payload` JSON,
  `saved_by`, timestamps.
- Save on demand, and autosave on a debounce so a lost tab is not a lost hour.
- Modal opens onto the draft if one exists, showing when it was last saved and
  by whom.
- Draft is discarded when the quote is actually sent.

Deliberately storing the form payload rather than reusing `Quotation` +
`QuotationLineItem`: the modal's shape (materials array, labour, transport,
down payment, milestones, duration) does not map onto line items without
reshaping both ends, and a half-finished draft is not a quotation.

---

## 3. Technician roster on a job, and the notification — ~1.5 days

**Now:** `JobAssignment` already carries `expected_start`, `expected_end` and
the technician per job. Missing: the technician's ID number, and their role on
that particular job.

**Change:** reproduce the attached email as a first-class part of the job.

- `national_id` on technicians (the "ID No." column).
- `role_on_job` on `job_assignments` — free text, because "Roof Installation
  Gang Member", "Solar Handling, Servicing & Re-installation" and "Driver" are
  not a fixed vocabulary.
- **Admin** sees and edits the roster on the job page.
- **Client** sees it read-only on their request-status page: who is coming and
  when. This is the half the client currently has to ask for by email.
- A "Send attendance notice" action mailing the client the same table.

**Privacy note:** the attached email lists technicians' national ID numbers to
the client. That is normal for site access in Kenya and I will build it as
asked, but it is personal data leaving the business, so: the ID column is
included in the client email and the client view, and nowhere else.

---

## 4. Material requests — ~2 days

**Now:** two near-misses.

- `Requisition` + `RequisitionItem` is a complete 9-state flow with
  procurement, accounts and delivery acknowledgement — but it hangs off
  `project_id`, and most jobs never become projects. No technician can reach it.
- `ToolRequest` is exactly the shape asked for — technician-owned, per-job,
  per-item approval — but for equipment, not consumables.

**Change:** a `MaterialRequest` modelled on `ToolRequest` (technician → admin →
procurement), attached to the **service request**, not a project.

- Technician submits: description, quantity, unit (buckets, running feet,
  lengths), urgency, why it was not foreseen.
- Admin approves or rejects per line, with a reason.
- Approved lines go to procurement, reusing the existing procurement role.
- The record persists against the job and feeds the billing story: an approved
  material line is exactly the evidence a variation order needs, so it links to
  `VariationOrder` rather than inventing a second billing path.

**Decision to confirm:** extend `Requisition` to accept a `service_request_id`
instead, and give technicians an entry point? Reuses the state machine and the
procurement screens, but that flow is heavier than this ask — nine states and
four roles for a request for two buckets of paint. Proposing the lighter
`MaterialRequest` and keeping requisitions for planned project procurement.

---

## 1. Archive for closed jobs and cancelled requests — ~1.5 days

**Now:** everything lives in RFQ Management forever. 114 jobs, of which the
great majority are finished, sitting in the same list as live work.

**Change:** an Archive area, out of the working list.

- Closed, completed and cancelled requests leave the RFQ list automatically.
- Archive browsable by **year → month**, and filterable by outcome
  (completed / cancelled / closed) and by client.
- Full-text search across archived requests.
- Nothing is deleted and nothing moves table — an archive is a view, not a
  migration. Restoring is one action.

The existing `STATUS_ARCHIVED` and `scopeNeedsAdminAction` already do part of
this; the list page simply does not use them.

---

## Sequencing

5 → 2 → 3 → 4 → 1. Smallest and least risky first, then the daily-friction
items, then the two builds. Each ships on its own branch and is independently
testable.
