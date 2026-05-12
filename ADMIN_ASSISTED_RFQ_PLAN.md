# Admin-Assisted Service Request Plan

## Goal

Add a clean admin-assisted path where an admin can:

- create a service request on behalf of a client
- proxy-approve the quotation on behalf of that client
- keep the rest of the lifecycle unchanged

The normal client self-service path must continue to work in parallel without branching the core workflow.

## Best-Case Scenarios

1. Walk-in or phone-in client
- Admin captures the request for an existing client or creates the client first.
- The service request still belongs to the real client account.

2. Offline quotation approval
- The client approves via email, signed document, or phone call.
- Admin records the approval inside the system with an audit trail.

3. Urgent operations workflow
- Admin creates the request quickly and moves the job into the same RFQ/payment/assignment path as any other request.

4. Repeat corporate client
- Admin can assist with submission and approval while reports still attribute the business to the correct client.

## Clean Implementation Approach

### Core Principles

- Keep `service_requests.user_id` as the true client owner.
- Do not create a separate workflow table.
- Reuse the same request statuses and RFQ statuses after creation.
- Mark assisted/proxy actions with explicit metadata and audit entries.

### Service Request Metadata

Add these fields to `service_requests`:

- `submission_mode`: `client_self` or `admin_proxy`
- `created_by_admin_id`: nullable user foreign key
- `proxy_quote_approved_by`: nullable user foreign key
- `proxy_quote_approved_at`: nullable timestamp
- `proxy_quote_approval_note`: nullable text

### Admin Path

Add an admin-only path at `/admin/rfq/create` that lets admin:

- select an existing client
- create the request using the same essential fields as the client form
- upload supporting files
- mark the request as admin-assisted automatically

### Proxy Approval Rules

Allow admin proxy quotation approval only for:

- requests created as `submission_mode = admin_proxy`
- quotations currently in `rfq_status = quoted`

The proxy approval should:

- update RFQ status to `approved`
- transition the service request to `awaiting_payment` when applicable
- store who approved on behalf of the client and when
- record a note for later review
- create an audit entry

### Review Visibility

Show assisted/proxy metadata on the admin RFQ workspace:

- source badge: `Client Submitted` vs `Admin Assisted`
- created by admin name where applicable
- proxy approval status
- proxy approval note in the details view

### Reporting / Review Inclusion

Expose the following fields in report/review datasets so assisted requests are reviewable:

- `client_name`
- `client_email`
- `service_name`
- `job_reference`
- `submission_mode`
- `created_by_admin_name`
- `proxy_quote_approved_by_name`
- `proxy_quote_approved_at`

Use those fields in:

- RFQ revenue rows
- client revenue rows
- PDF/Excel exports

## Testing Strategy

Add comprehensive feature tests covering:

1. Admin can create a service request on behalf of a client.
2. Normal client request creation still works unchanged.
3. Admin can proxy-approve only admin-assisted quoted requests.
4. Admin cannot proxy-approve a normal client-owned self-service request.
5. Admin-assisted request metadata appears in report output.

Also run:

- focused feature tests
- existing technician workflow suite
- production frontend build

## Definition of Done

- Admin-assisted request creation works from a dedicated admin path.
- Proxy quotation approval works only on the intended assisted path.
- Normal client journey remains untouched.
- Admin RFQ review surfaces show assisted/proxy context clearly.
- Report/export data includes client, service, and assisted/proxy markers for review.
- Tests and build pass.
