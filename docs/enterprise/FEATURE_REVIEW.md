# Feature review — what Snipe-IT v8.8.0 already does, and what it does not

Assessed against the six areas we care about: assets, inventory and audit, audit
logs, reports, user assignments, QR codes. Verdicts are from reading the code in
this repo at `v8.8.0`, not from a running instance or from the documentation
site.

**Headline: considerably more exists than a typical gap analysis assumes.** Bulk
audit, bulk checkout, polymorphic maintenance with types, a label designer and a
custom report builder are all in v8.8. The real gaps are narrower and more
specific: no session around counting, no batch sync, no department on an asset,
no proactive alerting, and no AI.

## 1. Assets

### Exists

- Full CRUD with soft delete and restore, over web and API.
- Asset tag and serial lookup endpoints (`bytag`, `byserial`) — the scanner's
  foundation.
- Polymorphic assignment to a **user, a location or another asset**, which covers
  equipment attached to other equipment.
- Models, categories, manufacturers, suppliers, status labels, locations,
  companies, departments as first-class reference data.
- Custom fields with fieldsets per model, encryption per field, and per-context
  display flags (`display_checkout`, `display_checkin`, `display_audit`).
- Purchase cost, order number, supplier, warranty months, depreciation, explicit
  or derived end-of-life date.
- Bulk update (`PATCH /hardware/bulk`) and bulk checkout with a dedicated event.
- FMCS multi-company scoping applied as a global query scope, with user-to-company
  membership now a pivot (`company_user`).
- BYOD flag, requestable flag, kits for onboarding bundles.

### Gaps

| Gap | Impact | Covered in |
| --- | --- | --- |
| **No `department_id` on assets** | an asset cannot be owned by a department unless it is checked out to a person in it; department accountability is unenforceable | `API_DESIGN.md` migration + transfers |
| **No transfer workflow** | moving an asset between departments is an edit with no request, approval or record of who agreed | `API_DESIGN.md` transfers |
| No asset-level approval on checkout | acceptance exists, authorisation does not | out of scope phase 1 |
| Status semantics are conventions | "missing" and "damaged" are status labels someone named, so no code can rely on them | KPI endpoint takes them as configuration |

## 2. Inventory and audit

### Exists — more than expected

- `POST /hardware/{asset}/audit` and the legacy body-resolved
  `POST /hardware/audit`.
- **`POST /hardware/audit/bulk`**: `ids` plus one shared payload, per-row
  response envelope with success and error counts.
- `last_audit_date` and `next_audit_date` on each asset, with
  `Setting::audit_interval` driving the next date automatically.
- `GET /hardware/{action}/{upcoming_status}` lists assets due or overdue for
  audit.
- Audits accept an uploaded **image as evidence** and an optional location
  correction (behind `update_location`).
- Custom fields marked `display_audit` are returned with the audit payload.
- Every audit writes an `action_logs` row.
- Authorisation is applied twice, class then resolved instance, so company
  scoping holds even when the asset came from the request body.

### Gaps

| Gap | Impact | Covered in |
| --- | --- | --- |
| **No inventory session** | nothing represents "count building B in Q3": no expected set, no progress, no completion, no sign-off | `API_DESIGN.md` sessions |
| **No discrepancy report** | after counting, "what did we not find" has to be assembled by hand from audit dates | `API_DESIGN.md` discrepancy report |
| **No offline batch endpoint and no idempotency** | a phone replaying 200 scans makes 200 requests, and a retry double-posts | `API_DESIGN.md` sync/batch |
| No notion of counting two people in parallel | two counters in the same room produce two unrelated streams of audits | session membership, phase 2 |
| No unexpected-asset capture | scanning something that should not be there records an audit, not an exception | session `found_unexpected`, phase 2 |

The important correction to the usual plan: **we are not building audit, we are
building the wrapper around it.** Counting a scanned asset is one existing call.

## 3. Audit logs

### Exists

- `action_logs` records every checkout, checkin, audit, acceptance, decline,
  create, update, delete, restore, request and upload, polymorphically on both
  the item and the counterparty, with `created_by` and the note.
- Per-object history endpoints for assets, users, locations, licences,
  accessories, components, consumables and maintenances.
- `GET /api/v1/reports/activity` and `/reports/activity/chart`.
- `company_id` on the log, backfilled from the item in migration 2026_04_20, so
  activity can be scoped per tenant.
- `stored_eula` preserves the exact agreement text a person accepted.

### Gaps

| Gap | Impact |
| --- | --- |
| No tamper evidence | rows are ordinary and editable by anyone with database access; for a regulated audit, append-only storage or signing would be needed |
| No retention policy | the table grows without bound and is the usual cause of a slow dashboard |
| Field-level changes are a note, not a diff | "what exactly changed" is sometimes unanswerable |
| No log of reads | who *looked* at an asset is not recorded |

None of these block our work. The retention point should be on the roadmap before
the table reaches millions of rows.

## 4. Reports

### Exists

- Depreciation, maintenance, licence, accessory, activity and unaccepted-asset
  reports, each with CSV export.
- A **custom report builder** with saved templates (`report_templates`, with a
  `type` column added in migration 2026_04_28).
- `GET /low-stock` against each model's `min_amt`.
- `GET /calendar/events` exposing expected checkins and expiries.
- `GET /dashboard/activity`, `/dashboard/categories`, `/dashboard/companies`,
  `/dashboard/locations` for counts by dimension.
- Unaccepted-asset report with reminder mail and bulk delete.

### Gaps

| Gap | Impact | Covered in |
| --- | --- | --- |
| **No single KPI endpoint** | a dashboard needs four or five calls, each paginated and shaped differently | `API_DESIGN.md` dashboard KPIs |
| **No warranty-expiring query** | the data exists (`purchase_date` + `warranty_months`) but only as a calendar feed; nothing answers "expiring in 30 days" | `API_DESIGN.md` alerts |
| No scheduled report delivery | reports are pulled, never pushed |
| Exports are synchronous | a large CSV ties up a request |
| No inventory-progress reporting | follows from having no session |

## 5. User assignments

### Exists

- Checkout to users, locations or assets, with notification, EULA, acceptance and
  signature capture.
- Decline, which checks the asset back in rather than only flagging it.
- Requestable catalogue with request, cancel and automatic fulfilment on
  checkout.
- Per-user views of assets, accessories, consumables and licences held.
- Departments, managers (`manager_id`), locations, and groups for permissions.
- Licence seats assignable to a user or an asset, with automatic assignment
  (`autoassign_licenses`).
- Consumable checkout as one-way consumption with no checkin.
- Kits so a new starter receives a defined bundle.
- LDAP, SCIM, SAML and OIDC for identity.
- Expected checkin dates and overdue reporting.

### Gaps

| Gap | Impact |
| --- | --- |
| **No department-scoped permission** | FMCS scopes by company only; a custodian with `assets.checkout` can act on any asset in the company. The requested Department Custodian role cannot be fully enforced today — see `ROLES_PERMISSIONS.md` |
| No approval before checkout | requests are fulfilled by whoever has the permission; there is no second signature |
| No handover between two people | a transfer is a checkin followed by a checkout, with the asset briefly unassigned |
| Leaver offboarding is manual | nothing automatically reclaims equipment when `end_date` passes |

The department-scoped permission gap is the one to raise with stakeholders
early, because it is a product-shaped limitation rather than something a
plugin-sized change fixes cleanly.

## 6. QR codes

### Exists

- QR (and PDF417, and 1D Code 128) generation through `tc-lib-barcode`.
- `GET /{object_type}/{id}/qr_code` for ten object types, with per-object
  `view` authorisation and cached PNGs.
- A label designer with sheet templates, custom fonts, custom labels as data
  (`custom_labels`, migration 2026_03_23), and PDF generation over web and API.
- Codes resolve to the item's own page, so scanning with any camera works for a
  signed-in member of staff.

### Gaps

| Gap | Impact | Covered in |
| --- | --- | --- |
| **QR encodes the database id in a URL, not the asset tag** | a scanner must parse the URL, and an id from another instance is a valid id in ours | `QR_CODES.md` scan decision table |
| **`APP_URL` is baked into printed labels** | a hostname change invalidates every printed code and the PNG cache is not invalidated automatically | operational warning |
| The QR image route is session-authenticated | the mobile app cannot render labels with its bearer token; it can only scan |
| No bulk tag-to-id resolution | resolving 200 scanned tags is 200 requests | `sync/batch` accepts tags |

## Summary table

| Area | Verdict |
| --- | --- |
| Assets | strong; missing department ownership and transfers |
| Inventory and audit | audit is strong, including bulk; the session around it is entirely absent |
| Audit logs | strong for operations, not tamper-evident, no retention policy |
| Reports | broad, all pull-based, no single KPI call, no warranty-expiry query |
| User assignments | strong; no department-scoped authority, no approval step |
| QR codes | complete for web use; scanner-hostile payload, APP_URL lock-in |

## What this means for scope

Build the five things that genuinely do not exist — sessions, batch sync with
idempotency, department plus transfers, alerts, KPIs — and the AI assistant on
top of read-only queries. Do **not** rebuild audit, checkout, labels or reports;
wrap what is there. That is both less code and less to re-reconcile at every
upstream upgrade (`CUSTOMISATION_RULES.md`).
