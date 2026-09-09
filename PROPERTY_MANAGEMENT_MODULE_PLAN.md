# Property Management & Corporate Module — Analysis & Implementation Plan

> Source brief: *"Technician World Property Management & Corporate Level Module — Brief"*, LNI → WEBPIN, dated 28.08.2026 (8 pages).
> Plan drafted: 2026-09-09. Status: **Phases 0–1 delivered** on `feat/corporate-segment-foundations`; Phases 2–7 not started.
> Companion docs: `FEATURE_TRACKER.md`, `REQUISITION_MODULE_DOCUMENTATION.md`, `ADMIN_ASSISTED_RFQ_PLAN.md`.

---

## 1. Executive summary

The brief asks for a **second client segment** alongside the existing retail module. A property
management company is not a retail client with more jobs; four things are structurally different:

| # | Structural difference | Retail today | Corporate required |
|---|---|---|---|
| 1 | **Who the client is** | One `User` with `role=client` | An organisation with many properties, many staff, and an internal approval hierarchy |
| 2 | **What unlocks work** | A per-job deposit is paid before assignment | A standing **float** (e.g. KES 500,000) held by TW; work is unlocked by *remaining float*, not per-job payment |
| 3 | **When money is asked for** | Milestone payment requests clear silently | Invoices accumulate in an **in-tray** and are dispatched in a batch when the float drops through a threshold; hard-copy tax invoice + eTIMS required |
| 4 | **How a job is priced** | Free-text scope, hand-priced quote | Pre-negotiated **rate schedule** (~4,000 items), client picks items + quantities, quote auto-populates |

Everything else — assignment, progress reports, variations, technician payments, tools — is
reused. **The recommendation is therefore to extend the existing `service_requests` pipeline
with a segment discriminator plus corporate-only satellite tables, not to build a parallel job
pipeline.** A second pipeline would fork assignment, progress reporting, technician
compensation and tooling — four subsystems that are already correct — and double the
maintenance surface for no gain.

Estimated size: **7 phases, roughly 16–22 weeks** of one focused developer, with Phase 7 (rate
schedule) parallelisable from Phase 3 onward.

---

## 2. Requirement decomposition

Every requirement below is traceable to the brief. IDs are used by the phase plan in §6.

### 2.1 Corporate account structure

| ID | Requirement | Brief ref |
|---|---|---|
| CA-1 | A corporate client is an **organisation**, not a user. It has many properties/branches/stations. | p1 §1a, p2 |
| CA-2 | Properties are **pre-set by admin per client**, the same way service categories are managed today. | p2 |
| CA-3 | Every REQ selects a property from a dropdown; the property is **stamped on every downstream document** (quote, VO, invoice, report). | p1–p2 |
| CA-4 | Jobs must be **filterable by property name**. | p2 |
| CA-5 | The float **covers all properties**, which belong to different owners. | p1 |
| CA-6 | Organisation members hold distinct roles: **requester (junior)**, **verifier**, **approver (senior manager)**. | p2 §1c |
| CA-7 | Per-organisation **approval workflow choice**: 1-stage (approver only) or 2-stage (verifier → approver). | p2 §1c |
| CA-8 | **Visibility scoping**: a junior sees only their own REQs; the senior manager sees all; the senior manager can **reassign** a REQ to another junior (absenteeism, turnover). | p2 §1c |
| CA-9 | A pre-set list of **authorised approver names** for signature blocks. | p3 §1e |
| CA-10 | Corporate requests arrive in a **separate corporate queue**, not lumped with retail. | p4 §2 |
| CA-11 | Cross-cutting UX directive: **prefer dropdowns over free text** throughout. | p2 (emphasised) |

### 2.2 Deposit / float ledger

| ID | Requirement | Brief ref |
|---|---|---|
| DP-1 | Admin books a received deposit: **amount, date received**, per organisation. | p2 §1b |
| DP-2 | Admin sets the **top-up / invoicing threshold** (e.g. invoice when float falls to 50%, or an absolute KES 300,000). | p2 §1b, p4 |
| DP-3 | Technician **assignment is gated on remaining float**, not on a per-job deposit. | p1 §1a |
| DP-4 | Below threshold, requests are still **accepted** but **cannot be worked on**. | p4 §1 |
| DP-5 | An **admin-only override switch** lifts the gate (e.g. temporarily lowering the threshold). | p4 §1 |
| DP-6 | Corporate REQs have **no down-payment request** at all — the float is the money. | p4 (Note) |
| DP-7 | Float **decreases** when a job closes, by the closed job's amount. | p5 §8 |
| DP-8 | Float **tops back up** when payment is acknowledged, and again when withholding-tax certificates are validated. | p5 §10 |
| DP-9 | Top-up is **capped at the agreed float ceiling** — excess does not push the balance above it. | p6 |

### 2.3 REQ lifecycle & approvals

| ID | Requirement | Brief ref |
|---|---|---|
| RQ-1 | Junior raises REQ → corporate queue → **admin assigns a PM** → PM produces quote → **admin validates or returns to PM with comments**. | p4 §2–3 |
| RQ-2 | Validated quote goes to the client's verifier and/or approver → **approve or decline with comments**. | p4 §4 |
| RQ-3 | On decline, the quote returns to **both admin and PM**; either can action; admin can comment and post to the PM. Cycle repeats. | p4 §5 |
| RQ-4 | On approval, the job runs through the **existing** assignment / reporting / management flow. | p4 §6 |
| RQ-5 | **Approval transition page** captures: LPO number + LPO copy attachment; **landlord's/property owner's PIN** (the landlord pays, not the manager); approver's **name (from preset list) and digital signature**. | p3 §1e |
| RQ-6 | Approved quote sits in an **in-tray** until the job is reported complete, confirmed by the junior, then validated by the approver. | p2 §1b |
| RQ-7 | Completion flows: TW reports complete → **initiator checks on the ground and approves** → escalates to verifier → approver, with closing report and photos → approver validates → job moves to in-tray for invoicing. | p5 §8 |
| RQ-8 | **Reference numbering**: a revision of a declined quote keeps the same reference with `R1`, `R2`… suffixes. A new variation moves `/V.O 01` → `/V.O 02`, each of which may itself carry `R01…R05`. All retained for the paper trail. | p3 §1d |
| RQ-9 | **Colour coding**: declined items red, approved green/blue, declined still accessible. | p3 §1d |

### 2.4 Variation cards

| ID | Requirement | Brief ref |
|---|---|---|
| VC-1 | The **junior raises a variation card** requesting additional scope, with justification. | p2 §1c |
| VC-2 | The senior manager **approves or declines with comments** (e.g. "additional scope exceeds the original REQ — raise a new REQ"). | p2–p3 |
| VC-3 | On approval, TW sees it and **quotes for the additional scope**; the senior again approves or returns with comments. | p3 §1d |
| VC-4 | The **original quote remains**; +/− variations stack on top, as in the retail module. | p3 §1d |
| VC-5 | **All** variation orders — approved and revised — remain in the system; **only approved ones** move the revised quotation amount. | p3 §1d |

### 2.5 Invoicing, tax & settlement

| ID | Requirement | Brief ref |
|---|---|---|
| IN-1 | Invoices for closed jobs are **generated and held internally** (the in-tray). | p1 §1a |
| IN-2 | When the float drops below the threshold, held invoices are **dispatched as proforma invoices** to the client's account and email. | p1 §1a, p5 §9 |
| IN-3 | Simultaneously TW gets an **internal alert to raise the hard-copy tax invoice** (retail milestones clear silently — this does not). | p1 §1a |
| IN-4 | TW can **download and print PDFs** of all invoices at the trigger point, and attach an **eTIMS receipt** to each. | p1 §1a |
| IN-5 | Two output modes: **one consolidated invoice** covering all REQs, or **separate invoice forms** per REQ — both carrying full detail. | p5 (a)/(b) |
| IN-6 | Invoice must carry: TW **PIN**, **bank details**, **logo**, **REQ numbers**, **requester name/nickname**, **validator/approver name/nickname**, **property/branch name**, and **all associated variation orders with their requester and approver**. Plus job dates and amounts. | p6, p5(a) |
| IN-7 | Client pays net of **2% WHVAT** and **3% WHT**, both computed on the VAT-exclusive value. | p5 §9 |
| IN-8 | Accountant **validates/acknowledges the payment**; the job stays alive until withholding certificates arrive. | p5 §9 |
| IN-9 | Client later **attaches WHT/WHVAT certificates** to the specific job; accountant validates and **closes the job as fully paid**. | p5 §9 |
| IN-10 | Client-side **batch settlement**: attach one bank transfer POP, tick the jobs it covers, post to TW. TW verifies the transfer matches the final quotes and validates; job cards close. | p4 §1f |
| IN-11 | **Cheque handling**: scan cheques and attach each to the relevant REQ, together with the client's remittance statement mapping cheque → job → invoice. | p3 §1f |
| IN-12 | TW must also be able to do the validation **directly from its own end** (client emails POPs instead of using the portal). | p6 |
| IN-13 | **360° job view**: one screen showing the REQ with variation cards, payments (including cheque images), reports, invoices, POPs and tax certificates. | p4 |

### 2.6 Reporting & site access

| ID | Requirement | Brief ref |
|---|---|---|
| RP-1 | **One consolidated daily report per client**, segmented by job — not 15 separate notifications. TW still validates each job's report individually first. | p5 §7 |
| RP-2 | On assignment, the client receives a **neat, printable roster**: technician full names, ID numbers, passport photos — issuable to security in hard or soft copy. | p8 |
| RP-3 | **Future**: a security portal where the requester approves proposed workers and schedule, and security receives a pre-populated form. | p8 |

### 2.7 SLA rate schedule & intelligent quoting

| ID | Requirement | Brief ref |
|---|---|---|
| SL-1 | Per-client **negotiated rate schedule** for labour and materials, supporting revisions, additions and updates over time. | p6 |
| SL-2 | An item's rate **decomposes into components**: material, labour, transport, consumables, overheads, TW margin — summing to a composite unit rate (worked example: granito tile at KES 6,000/Sq.M). | p6–p7 |
| SL-3 | Changing one component **recomputes the composite rate**. | p7 |
| SL-4 | Scale: **~4,000 items**, with per-item **unit of measurement** (No., Sq.M, Cu.M, Lot) pre-set by item nature. | p7 |
| SL-5 | Client request form: **searchable typeahead** — typing "tile" lists all tile variants, typing "toilet" lists close-couple / wall-hung / Asian with reference numbers; client supplies quantity. | p7 |
| SL-6 | **Per-item urgency level** within the larger request. | p7 |
| SL-7 | Every request carries **building/branch name and requester name**. | p7 |
| SL-8 | TW clicks **one button to auto-populate rates**, which multiply by quantities → a quote in thirty seconds. | p7 |
| SL-9 | TW appends **ancillary costs** (approval permits, night-shift allowances) below the auto-populated lines. | p7 |
| SL-10 | Quote lines are **VAT-exclusive**; **VAT auto-calculates at the bottom and re-adjusts live** as lines are added. | p7 |
| SL-11 | Per-line **start and end dates**, so the schedule of access is known by the end of quoting. | p7 |
| SL-12 | The approver can **hide prices** and share items + dates only — e.g. with the security team. | p8 |
| SL-13 | TW applies a **digital signature** to the quote before posting it to the verifier/approver. | p8 |
| SL-14 | The requester **cannot see rates** unless TW opens a toggle. | p8 |
| SL-15 | Quote lines carry **location within the property** (e.g. "14th floor gents toilets, cubicle 1"), shared to the requester. | p8 |
| SL-16 | A **technician projection** of the quote: description, quantity, UoM, location — with per-technician item filtering and open/close visibility windows, so scope is never retyped. | p8 |

---

## 3. Gap analysis against the current system

### 3.1 What already exists and is directly reusable

| Existing capability | Where | Serves |
|---|---|---|
| REQ pipeline with an 18-state machine | `service_requests`, `JobService` | RQ-4 |
| PM assignment, quote generation, admin validation, revision counter | `PMDashboardController`, `AdminDashboardController::submitQuote`, `quote_revision_count` | RQ-1, RQ-8 |
| Quotation + line items (`material`/`labor`/`transport`, qty × unit price) | `Quotation`, `QuotationLineItem` | SL-8 skeleton |
| Draft quotes with an allow-listed payload | `QuotationDraft` | SL-9 |
| Variation orders: signed deltas, `REQ-XXXX/VO-01` numbering, approve/decline with reason, client-visibility flag, immutable once approved | `variation_orders`, `VariationOrderService` | VC-3, VC-4, VC-5, RQ-8 (VO half) |
| Client approve/decline with comments and evidence capture | `ClientController`, `client_approval_evidence` | RQ-2 |
| Progress reports with an office pipeline and **batch release to client** | `ProgressReport`, `releaseReportsToClient` | **RP-1 is ~70% built** |
| Attendance roster: technician `national_id` + `profile_photo_path`, `role_on_job`, `attendance_dates`, attendance notice email | `job_assignments`, `sendAttendanceNotice` | **RP-2 is ~70% built** |
| Pre-approval / pre-deposit authorisation with reason, expiry, exposure cap, revocation | `JobAuthorisation` | **DP-5 override has a proven pattern to copy** |
| Admin-managed lookup CRUD | `ServiceCategoryController` | **CA-2 has a proven pattern to copy** |
| PDF generation | `barryvdh/laravel-dompdf`, `resources/views/pdf` | IN-4, IN-5 |
| Payment proofs, offline payment confirmation, refunds | `PaymentProof`, `PaymentRequest`, `RefundService` | IN-10, IN-11 partial |
| Audit logging, email logging, notification service | `AuditLog`, `EmailLog`, `NotificationService` | cross-cutting |

### 3.2 What does not exist at all

| Gap | Consequence |
|---|---|
| **Client organisations** — a client is one `User` row; there is no org, no properties, no multi-user hierarchy, no configurable approval workflow | Blocks CA-1…CA-10, RQ-1…RQ-3, VC-1 |
| **Deposit / float ledger** | Blocks DP-1…DP-9 — the commercial heart of the module |
| **Invoices as a first-class entity** — today there are payment *requests* and *milestones*, no invoice document, no proforma, no tax invoice, no eTIMS, no WHT/WHVAT | Blocks IN-1…IN-12 |
| **Rate schedule catalogue** | Blocks SL-1…SL-10 |
| **Item-composed requests** — a REQ is free text + one category | Blocks SL-5…SL-7 |
| **LPO / landlord PIN / digital signature capture** | Blocks RQ-5, SL-13 |
| **Field-level visibility projections** on a quote | Blocks SL-12, SL-14, SL-16 |
| **Quote revision references** (`/R1`, `/R2`) — VOs are numbered, quote revisions only counted | Blocks the quote half of RQ-8 |
| **Client-raised variation *card*** — VOs support `ORIGIN_CLIENT` but there is no client-side request-and-approve flow preceding the TW quote | Blocks VC-1, VC-2 |

---

## 4. Architectural decisions

**AD-1 — Extend, do not fork the pipeline.**
Add `service_requests.segment ∈ {retail, corporate}` (default `retail`) and hang corporate-only
data off satellite tables. Queues, dashboards and policies branch on `segment`; assignment,
progress reporting, technician payment and tooling do not change. Rationale in §1.

**AD-2 — The organisation, not the user, is the client of record.**
Introduce `client_organisations`. The existing `users.role = client` stays as the login identity;
membership and client-side role live in `organisation_members`. A retail client simply has no
membership row. This avoids widening the `users.role` enum with `requester` / `verifier` /
`approver`, which are *positions within one client*, not platform roles.

**AD-3 — The float is a ledger, never a mutable balance column.**
`deposit_accounts` holds the terms (ceiling, threshold, currency); `deposit_ledger_entries` holds
every movement (booking, consumption, top-up, adjustment) with its cause. The balance is
`SUM(amount)`, cached with a derived column for querying. Money that can only be reconstructed
from a running total is money that cannot be audited, and this is the one subsystem where a
silent arithmetic bug is a commercial dispute.

**AD-4 — Track *committed* separately from *consumed*.**
See open question OQ-1. Recommendation: gate assignment on
`available = balance − committed`, where `committed` is the sum of approved-but-not-yet-invoiced
quotes. The brief only reduces the float at job closure, which would allow ten 100k jobs to be
approved against a 500k float.

**AD-5 — Rates are versioned; quotes snapshot them.**
A quote line stores the resolved component amounts, not a foreign key to a live rate. When the
tile price moves from 4,500 to 5,000, historical quotes must not silently re-price.

**AD-6 — Visibility is a projection, not a second document.**
SL-12/SL-14/SL-16 are the same mechanism at three audiences: a per-audience field mask plus a
per-line audience filter over one canonical quote. Build one projection service with three
presets (client, security, technician), not three exports.

---

## 5. Proposed data model

New tables, grouped by phase. Column lists are indicative, not final.

```
── Corporate accounts (Phase 1) ──────────────────────────────────
client_organisations      id, name, kra_pin, billing_email, address, logo_path,
                          approval_workflow ∈ {single_stage, two_stage},
                          is_active, created_by, timestamps
properties                id, client_organisation_id, name, code, address,
                          owner_name, owner_kra_pin, is_active, sort_order
organisation_members      id, client_organisation_id, user_id,
                          position ∈ {requester, verifier, approver, accounts},
                          can_approve_up_to (nullable cap), signature_path,
                          display_name, is_active
                          UNIQUE(client_organisation_id, user_id)

── Float ledger (Phase 3) ────────────────────────────────────────
deposit_accounts          id, client_organisation_id, ceiling_amount,
                          threshold_type ∈ {absolute, percent}, threshold_value,
                          override_threshold_value, override_by, override_expires_at,
                          override_reason, currency, opened_at, is_active
deposit_ledger_entries    id, deposit_account_id, entry_type ∈ {booking, consumption,
                          settlement_topup, tax_certificate_topup, adjustment, reversal},
                          amount (signed), balance_after, service_request_id (nullable),
                          invoice_id (nullable), reference, note, recorded_by, occurred_on

── Invoicing & settlement (Phase 4) ──────────────────────────────
invoices                  id, invoice_number, client_organisation_id, kind ∈ {proforma,
                          tax_invoice}, batch_id (nullable), status ∈ {held, dispatched,
                          part_settled, settled, void}, subtotal_ex_vat, vat_amount,
                          total_inc_vat, whvat_amount, wht_amount, net_expected,
                          issued_at, dispatched_at, etims_receipt_path, pdf_path
invoice_lines             id, invoice_id, service_request_id, variation_order_id (nullable),
                          property_id, description, requester_name, approver_name,
                          job_completed_on, amount_ex_vat
settlements               id, client_organisation_id, method ∈ {rtgs, cheque, mpesa, other},
                          gross_amount, reference, paid_on, proof_path,
                          submitted_by (client user, nullable), validated_by, validated_at,
                          status ∈ {submitted, validated, rejected}, rejection_reason
settlement_allocations    id, settlement_id, invoice_id, amount
tax_certificates          id, client_organisation_id, invoice_id, type ∈ {wht, whvat},
                          certificate_number, amount, document_path,
                          submitted_by, validated_by, validated_at, status

── Approval artefacts (Phase 2) ──────────────────────────────────
corporate_approvals       id, service_request_id, quotation_id (nullable),
                          variation_order_id (nullable), stage ∈ {verify, approve},
                          decided_by, decision ∈ {approved, declined}, comments,
                          lpo_number, lpo_document_path, payer_kra_pin,
                          signatory_name, signature_path, decided_at
variation_cards           id, card_number, service_request_id, raised_by, scope_description,
                          justification, status ∈ {pending, approved, declined, quoted},
                          decided_by, decision_comments, decided_at,
                          variation_order_id (nullable — set once TW quotes it)

── Rate schedule (Phase 7) ───────────────────────────────────────
rate_schedules            id, client_organisation_id (nullable = global), name, version,
                          status ∈ {draft, active, superseded}, effective_from, approved_by
rate_items                id, rate_schedule_id, code, description, search_terms,
                          category, unit ∈ {no, sqm, cum, lot, lm, kg, hr},
                          material_rate, labour_rate, transport_rate, consumable_rate,
                          overhead_rate, margin_rate, composite_rate (derived), is_active
rate_item_revisions       id, rate_item_id, changed_by, changed_at, before (json), after (json)
service_request_items     id, service_request_id, rate_item_id (nullable), description,
                          unit, quantity, urgency, location_detail,
                          planned_start, planned_end, sort_order
```

Alterations to existing tables:

```
service_requests       + segment, client_organisation_id, property_id,
                       + raised_by_member_id, quote_reference_suffix, prices_visible_to_requester
job_assignments        (no change — roster fields already present)
variation_orders       + variation_card_id, revision_suffix
technicians            (no change — national_id and profile_photo_path already exist)
```

---

## 6. Phase plan

Each phase is independently shippable and leaves the retail module untouched.

### Phase 0 — Decisions & scaffolding *(≈1 week)* — ✅ **delivered**
- ⬜ Resolve the open questions in §7 with LNI. **Phase 3 and 4 cannot be safely built until OQ-1…OQ-5 are answered.**
- ✅ `service_requests.segment`, defaulted to `retail` with a `(segment, status)` index. No backfill UPDATE: MySQL applies the default as it adds the column, so all 116 existing rows read `retail` without a write against live data.
- ✅ `ServiceRequest::SEGMENT_*` constants, `retail()` / `corporate()` / `inSegment()` scopes, `isCorporate()` / `isRetail()` helpers. Deliberately **not** a global scope — that would hide corporate rows from reports and admin tooling invisibly.
- ✅ Feature flag `config/corporate.php` (`CORPORATE_MODULE_ENABLED`, default off), read through `App\Support\CorporateModule`, plus a `corporate` middleware alias that 404s while the module is off.
- ✅ CA-10 enforced early: the admin and PM RFQ queues default to `retail`, with `?segment=corporate` as an explicit escape hatch so nothing becomes unreachable before the corporate queue ships.
- ✅ `FEATURE_TRACKER.md` §23 carries these requirement IDs.

**Exit:** ✅ 461 tests green (450 pre-existing + 11 new in `tests/Feature/CorporateSegmentTest.php`); `segment` present and defaulted on dev MySQL; nothing user-visible changed.

### Phase 1 — Corporate accounts & properties *(≈2 weeks)* — ✅ **delivered**
- ✅ `client_organisations`, `properties`, `organisation_members` with admin CRUD, modelled on `ServiceCategoryController`. Screens at `/admin/organisations`, behind the `corporate` gate.
- ✅ Client users attach to one organisation with a position. **Position is not a platform role** — `users.role` stays `client`, so no existing `role === 'client'` check needed revisiting.
- ✅ Property carried on the request (`property_id`) with a single `Property::label` accessor, so the picker, the quotation and the invoice cannot name the same building three ways.
- ✅ Property filter **and** name/code search on the admin job and RFQ lists (CA-4).
- ✅ Authorised signatories per organisation: `display_name` for documents, `signature_path`, and an optional `can_approve_up_to` ceiling (CA-9).
- ✅ **OQ-9 answered in the build**: the landlord's PIN lives on the property, since the landlord is a fact about the building. The Phase 2 approval screen defaults from it and allows an override.
- ✅ A **readiness panel** on the account screen states what setup is still missing — a two-stage company is asked for a verifier, a single-stage one never is.
- ✅ A **consistency invariant** on `ServiceRequest`: a corporate request must carry an organisation, a retail one must carry none, and neither a property nor a requester from another company can be attached.

**Exit:** ✅ an admin onboards a management company with its buildings and its people; a request carries its property; the job list filters and searches by building. 489 tests green (461 before Phase 1, plus 27 new and one migration-safety guard).

**Found and fixed in passing:** the auto-generated name for one composite index came to 68 characters, over MySQL's 64-character limit. The `CREATE` failed, and because table creations are guarded with `hasTable` for retry-safety, the retry then skipped the table and **reported success with the index missing**. `MigrationSafetyTest` now measures every index name the migrations build and fails over 64 — the check is verified to catch the original bug.

### Phase 2 — Corporate REQ lifecycle & approvals *(≈3 weeks)* — CA-6…CA-8, CA-10, RQ-1…RQ-5, RQ-8, RQ-9
- Corporate queue separated from retail on the admin RFQ screen.
- Visibility scoping: requester sees own REQs; verifier/approver see all in the organisation; reassign action for the senior manager.
- Configurable 1-stage / 2-stage approval routing (`corporate_approvals`).
- Decline-with-comments returning to **both** admin and PM, with an admin→PM comment channel.
- **Approval transition page**: LPO number + upload, payer KRA PIN (defaulted from the property's owner PIN), signatory selection and signature capture.
- Reference numbering service: `REQ-XXXX`, `REQ-XXXX/R1`, `REQ-XXXX/VO-01/R02`; status colour coding in the UI.

**Exit:** a junior can raise a REQ, TW can quote it, and a two-stage client approval completes with a signed LPO on file.

### Phase 3 — Float ledger & work gating *(≈2.5 weeks)* — DP-1…DP-6, DP-9, AD-3, AD-4
- `deposit_accounts` + `deposit_ledger_entries`; `DepositService` as the only writer.
- Admin screens: book a deposit, set the ceiling and threshold, view the ledger and running balance.
- Assignment gate on available float, wired into the same seam `JobAuthorisation` uses today.
- Admin override switch (temporary threshold reduction) with reason, expiry and audit trail — copy the `JobAuthorisation` shape.
- Suppress down-payment requests entirely for corporate REQs (DP-6).
- Client-facing float widget: balance, threshold, headroom.

**Exit:** a 500k float blocks assignment at the threshold, an override unblocks it, and every movement is reconstructible from the ledger.

### Phase 4 — In-tray, invoicing, tax & settlement *(≈4 weeks)* — DP-7, DP-8, IN-1…IN-13
- In-tray: closed jobs generate a **held** invoice; float consumption posted on closure (DP-7).
- Threshold trigger → batch dispatch of proformas to the client's portal + email, plus an **internal alert** to raise the tax invoice (IN-3).
- PDF templates for both modes — one consolidated invoice and per-REQ invoices — carrying every field in IN-6 (TW PIN, bank details, logo, REQ numbers, requester, approver, property, associated VOs).
- eTIMS receipt upload and attachment per invoice.
- WHVAT/WHT computation on the VAT-exclusive value, rates configurable per organisation.
- Client batch settlement: upload one POP, tick the covered jobs, post; accountant reconciles against final quotes, validates or rejects with reason (IN-10).
- Cheque capture with the client's remittance statement, attached per REQ (IN-11).
- TW-side settlement entry for clients who email POPs (IN-12).
- Tax certificate upload → accountant validation → job closes fully paid; both events post float top-ups, capped at the ceiling (DP-8, DP-9, IN-9).
- **360° REQ view** assembling reports, VOs, invoices, POPs, cheque images and certificates (IN-13).

**Exit:** the §8 worked example runs end to end and the float returns to 500,000.

### Phase 5 — Variation cards *(≈1.5 weeks)* — VC-1…VC-5
- `variation_cards`: client-raised scope request with justification.
- Senior manager approve/decline with comments; declined cards remain visible.
- Approved card surfaces to TW and seeds a `VariationOrder` (reusing the existing service), inheriting the card's reference.
- Revision suffixes on VOs (RQ-8); declined revisions retained and rendered in red.

**Exit:** a client can request extra scope, have it approved internally, and receive a TW quote for it against the same REQ.

### Phase 6 — Consolidated reporting & site access *(≈1.5 weeks)* — RP-1, RP-2
- Extend the existing batch release into a **scheduled daily consolidated report** per organisation, segmented by job, replacing per-job client notifications for corporate clients.
- Printable technician roster (name, ID number, passport photo, role on job, attendance dates) as a PDF issuable to security — mostly wiring existing data into a template.
- RP-3 (security portal) explicitly **deferred**; design the roster as data, not a rendered email, so a portal can consume it later.

**Exit:** a corporate client receives exactly one report email per day, and a security-ready roster PDF on assignment.

### Phase 7 — SLA rate schedule & auto-quoting *(≈5 weeks, parallelisable from Phase 3)* — SL-1…SL-16
- `rate_schedules` / `rate_items` / `rate_item_revisions` with component decomposition and derived composite rate (SL-2, SL-3).
- Bulk import (CSV/XLSX) for the ~4,000 items, plus admin CRUD and revision history.
- Client request builder: typeahead over description + search terms, quantity, per-item urgency, location detail (SL-5…SL-7, SL-15).
- **Auto-populate**: one button resolves rates, multiplies by quantity, snapshots component amounts onto quote lines (SL-8, AD-5).
- Ancillary cost lines and live VAT recalculation on a VAT-exclusive base (SL-9, SL-10).
- Per-line planned start/end dates feeding the access schedule (SL-11).
- Digital signature on the quote before dispatch (SL-13).
- **Projection service** with client / security / technician presets: price masking, per-line audience filtering, open/close visibility windows (SL-12, SL-14, SL-16).

**Exit:** a client composes a 10 Sq.M tiling + 2 toilets request from the catalogue and TW returns a priced, signed, scheduled quote in under a minute.

---

## 7. Open questions for LNI

These change the build. OQ-1 through OQ-5 gate Phases 3–4.

| ID | Question | Our recommendation |
|---|---|---|
| **OQ-1** | Does the float reduce when a quote is **approved** (commitment) or when the job **closes** (p5 §8)? As written, ten 100k jobs could be approved against a 500k float. | Track both: reduce *available* on approval, reduce *balance* on closure. Gate assignment on available. |
| **OQ-2** | The illustration says the in-tray total of 320,000 is "below the 300,000 invoice trigger threshold" — 320,000 is not below 300,000. We read the trigger as firing on **remaining float** (500,000 − 320,000 = 180,000, which *is* below 300,000). Confirm. | Trigger on remaining float. |
| **OQ-3** | Are float amounts **VAT-inclusive**? The example reduces the float by 120,000 and then invoices 320,000 as a VAT-inclusive figure, so we assume yes. | Float is VAT-inclusive throughout. |
| **OQ-4** | The brief cites an RTGS of "314,482.76 … in the amount of 306,206.90". Only 306,206.90 reconciles (320,000 − 2% WHVAT 5,517.24 − 3% WHT 8,275.86, both on the ex-VAT value of 275,862.07). Confirm 314,482.76 is a typo. | Implement 306,206.90; make both rates configurable per organisation. |
| **OQ-5** | One active float per organisation, or can a client run several (e.g. per portfolio)? | One active float per organisation; model allows more later. |
| **OQ-6** | eTIMS: manual PDF upload now, or API integration with KRA? | Manual upload in Phase 4; API as a later phase. |
| **OQ-7** | Digital signature (RQ-5, SL-13): a stored signature image plus name, or a cryptographic signature? | Stored image + name + audit timestamp. |
| **OQ-8** | Who supplies the ~4,000 catalogue items, in what format, and when? This is the long pole on Phase 7. | Need a CSV/XLSX sample of ~50 rows before Phase 7 starts. |
| **OQ-9** | ✅ *Settled in Phase 1.* The landlord PIN is stored on `properties` and will default onto the LPO page, overridable at approval. Confirm this matches how they actually work. | Done — confirm only. |
| **OQ-10** | Should any existing retail clients be migrated to corporate accounts, or is this new-clients-only? | New accounts only; no migration in scope. |
| **OQ-11** | Is the daily consolidated report a fixed send time, or configurable per client? | Configurable per organisation, default 17:00 EAT. |
| **OQ-12** | When an approver declines a **variation card**, does the REQ pause, or continue on the original scope? | Continue on original scope; the card is a separate object. |

---

## 8. Worked example used as the Phase 4 acceptance test

Encode this in a feature test — it exercises DP-1…DP-9 and IN-1…IN-9 in one pass.

1. Admin books a **500,000** float for "Acme Property Managers", threshold **300,000**.
2. Caretaker A at Building 1 raises REQ-0001. It lands in the corporate queue, not retail.
3. Admin assigns a PM. PM quotes. Admin validates.
4. Client's verifier approves, approver approves, capturing LPO + landlord PIN + signature.
5. Job runs and closes at **120,000** → float **380,000**; invoice held in the in-tray.
6. A second job closes at **200,000** → float **180,000**, which is below 300,000.
7. Trigger fires: a proforma for **320,000** is dispatched; TW gets the tax-invoice alert; consolidated and per-REQ PDFs are downloadable; eTIMS receipts attach.
8. Client pays **306,206.90** by RTGS (320,000 − 5,517.24 WHVAT − 8,275.86 WHT). Accountant validates → float tops up to **486,206.90**. Jobs stay open.
9. Client uploads WHT and WHVAT certificates. Accountant validates → float tops up by **13,793.10** to the **500,000** ceiling → jobs close as fully paid.
10. Assert: ledger reconstructs to 500,000; all reports, invoices, POPs and certificates remain retrievable from the 360° view.

---

## 9. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| **Ledger correctness** — a float bug is a commercial dispute with a corporate client | High | Append-only ledger (AD-3), single writer service, property-based tests, the §8 scenario as a gate |
| **Scope size** — this is comparable to the retail module in surface area | High | Seven independently shippable phases; Phase 7 parallelisable; retail untouched behind a segment flag |
| **Catalogue data quality** — 4,000 items with six cost components each is 24,000 numbers | High | Start Phase 7 with a 50-row sample (OQ-8); import validation; revision history from day one |
| **Permission matrix explosion** — platform roles × organisation positions × visibility projections | Medium | One `CorporatePolicy` with an explicit matrix test, not scattered `if` checks |
| **Tax compliance** (eTIMS, WHT/WHVAT) | Medium | Confirm rates and formulas with TW's accountant before Phase 4; make rates configuration, not code |
| **Retail regression** | Medium | `segment` defaults to `retail`; corporate branches are additive; full retail suite runs on every phase |

---

## 10. Immediate next steps

1. Send §7 to LNI; OQ-1…OQ-5 and OQ-8 are the blocking ones.
2. Land Phase 0 (segment column + feature flag + tracker entries) — safe to do now, independent of the answers.
3. Confirm WHT/WHVAT treatment with TW's accountant.
4. Request a 50-row sample of the rate schedule to size Phase 7 properly.
5. On answers returning, start Phase 1.
