# API design — `/api/v1/enterprise`

**Design only. No code in this branch beyond documentation.** Nothing here is
implemented; this is the contract to review before any feature code is written.

Everything in this document is **additive**, per `CUSTOMISATION_RULES.md`: new
migrations, controllers under `App\Http\Controllers\Api\Enterprise`, a separate
`routes/enterprise.php`, and a separate service provider. No upstream file is
modified except the three registration points named in that document.

## Shared conventions

**Base path** `/api/v1/enterprise`, inside the same middleware stack as v1 so
Passport auth, activation checks, the User-Agent middleware, locale and
pagination defaults all apply unchanged.

**Envelope.** Reads return `{ "total": N, "rows": [...] }` or a bare object;
writes return `Helper::formatStandardApiResponse` — `{ status, messages, payload }`.
We keep the existing shape deliberately, including its quirks, so one client can
talk to both surfaces.

**One departure, stated loudly:** enterprise write endpoints return **accurate
HTTP status codes** (201, 409, 422) alongside the envelope, rather than v1's
"200 with an error body". Clients must still read `status` in the body. This is
the only intentional divergence from v1 behaviour.

**Authorisation.** Every endpoint names a permission from
`ROLES_PERMISSIONS.md`. Two new permissions, both defaulting to denied for every
existing group:

| Permission | Grants |
| --- | --- |
| `enterprise.inventory.manage` | create, close and report on inventory sessions |
| `enterprise.transfers.approve` | approve or reject a department transfer |

Counting itself remains `assets.audit`, and the dashboard remains
`reports.view`, so an Inventory Counter and an Executive need no new grant.

**Tenancy.** Every query goes through Eloquent so `CompanyableScope` applies, and
every endpoint that resolves an asset from a request body authorises the
**resolved instance**, copying `AssetsController::audit()`.

## 1. Inventory sessions

A session is the wrapper around the audit machinery that already exists: an
expected set of assets, a stream of scans against it, progress, and a
discrepancy report on close.

### Migrations

`2026_xx_xx_create_enterprise_inventory_sessions_table`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigIncrements | |
| `name` | string(255) | "Building B — Q3 2026" |
| `company_id` | unsignedInteger nullable, indexed | FMCS scope, set from the creator |
| `location_id` | unsignedInteger nullable, indexed | scope of the expected set |
| `department_id` | unsignedInteger nullable, indexed | alternative scope, needs feature 3 |
| `category_id` | unsignedInteger nullable | optional narrowing |
| `status` | enum(`open`,`closed`,`cancelled`) default `open`, indexed | |
| `expected_count` | unsignedInteger default 0 | frozen at creation |
| `opened_at`, `closed_at` | datetime nullable | |
| `created_by`, `closed_by` | unsignedInteger nullable | matches the codebase's `created_by` convention |
| `notes` | text nullable | |
| timestamps, `deleted_at` | | soft delete, as elsewhere |

`2026_xx_xx_create_enterprise_inventory_scans_table`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigIncrements | |
| `session_id` | unsignedBigInteger, indexed, FK cascade | |
| `asset_id` | unsignedInteger nullable, indexed | null when the scan matched nothing |
| `scanned_value` | string(255) | exactly what the scanner read |
| `resolved_by` | enum(`tag`,`serial`,`url_id`,`manual`,`unresolved`) | how we found it |
| `outcome` | enum(`found`,`unexpected`,`not_found`,`duplicate`) , indexed | |
| `status_id` | unsignedInteger nullable | condition recorded at the scan |
| `location_id` | unsignedInteger nullable | where it actually was |
| `note` | text nullable | |
| `image` | string(255) nullable | evidence photo |
| `action_log_id` | unsignedInteger nullable | the `action_logs` row the audit wrote |
| `client_operation_id` | uuid nullable, **unique** | idempotency, see section 2 |
| `scanned_by` | unsignedInteger | the counter |
| `scanned_at` | datetime | device clock, kept as reported |
| timestamps | | |

A unique index on `(session_id, asset_id)` is **deliberately not** added: a
second scan of the same asset is recorded with outcome `duplicate` rather than
rejected, because knowing that two counters covered the same shelf is useful.

### `POST /enterprise/inventory/sessions`

Permission `enterprise.inventory.manage`.

```json
{
  "name": "Building B — Q3 2026",
  "location_id": 12,
  "include_child_locations": true,
  "category_id": null,
  "notes": "Annual count, floors 1-3"
}
```

201:

```json
{
  "status": "success",
  "messages": "Inventory session created",
  "payload": {
    "id": 7,
    "name": "Building B — Q3 2026",
    "status": "open",
    "location": { "id": 12, "name": "Building B" },
    "expected_count": 418,
    "opened_at": "2026-10-10 09:14:00",
    "created_by": { "id": 3, "name": "A. Counter-Manager" }
  }
}
```

The expected set is computed once, from `GET /locations/{id}/assets` semantics
(optionally walking `locations.parent_id`), excluding archived statuses and
soft-deleted assets, and `expected_count` is frozen. Assets added mid-count then
appear as `unexpected`, which is correct: they were not in scope when the count
began.

### `GET /enterprise/inventory/sessions`

Permission `assets.audit` (a counter needs to see their sessions).
Filters: `status`, `location_id`, `department_id`, `created_by`, plus standard
`limit`, `offset`, `sort`, `order`.

```json
{
  "total": 2,
  "rows": [
    { "id": 7, "name": "Building B — Q3 2026", "status": "open",
      "expected_count": 418, "scanned_count": 263, "found_count": 258,
      "unexpected_count": 5, "percent_complete": 61.7,
      "opened_at": "2026-10-10 09:14:00" }
  ]
}
```

### `POST /enterprise/inventory/sessions/{session}/scans`

Permission `assets.audit`, plus the session must be `open`. **This is the hot
path** — one call per scanned asset.

```json
{
  "client_operation_id": "9f1c0f7e-6b4b-4a4e-9a4a-2b9a1d7c8e01",
  "scanned_value": "SNIPE-000418",
  "scanned_at": "2026-10-10T09:41:22+03:00",
  "status_id": 2,
  "location_id": 12,
  "update_location": true,
  "note": "shelf 4B"
}
```

`scanned_value` accepts an asset tag, a serial, or an asset URL from a QR code;
resolution follows the decision table in `QR_CODES.md`, including the host check.

201:

```json
{
  "status": "success",
  "messages": "Asset audited",
  "payload": {
    "scan_id": 2041,
    "outcome": "found",
    "resolved_by": "tag",
    "asset": { "id": 418, "asset_tag": "SNIPE-000418", "name": "ThinkPad T14",
               "status_label": "Deployed", "assigned_to": "R. Hassan" },
    "audit": { "action_log_id": 99231, "last_audit_date": "2026-10-10 09:41:22",
               "next_audit_date": "2027-10-10" },
    "progress": { "expected": 418, "found": 259, "percent_complete": 62.0 }
  }
}
```

Behaviour:

- The audit itself is performed by **calling the existing audit path**, so
  `last_audit_date`, `next_audit_date`, the `action_logs` row, the
  `update_location` flag and the evidence image all behave identically to
  `POST /hardware/{asset}/audit`. We add a row to `enterprise_inventory_scans`
  recording the resulting `action_log_id`.
- `authorize('audit', $resolvedAsset)` runs on the resolved instance.
- Unresolvable value: 200 with `outcome: "not_found"`, `asset: null`, and the
  scan still recorded. **Never a 404** — a counter must be able to keep moving,
  and the unmatched value is itself a finding.
- An asset outside the expected set: `outcome: "unexpected"`, audited anyway.
- An asset already scanned in this session: `outcome: "duplicate"`, no second
  audit written.
- Replaying the same `client_operation_id` returns the original result with
  `"replayed": true` and writes nothing.

### `GET /enterprise/inventory/sessions/{session}/progress`

Permission `assets.audit`. Cheap and pollable — the app's progress bar.

```json
{
  "session_id": 7,
  "status": "open",
  "expected": 418,
  "found": 259,
  "missing": 159,
  "unexpected": 5,
  "duplicates": 3,
  "unresolved": 1,
  "percent_complete": 62.0,
  "counters": [ { "id": 9, "name": "S. Counter", "scans": 211 } ],
  "last_scan_at": "2026-10-10 11:02:14"
}
```

### `POST /enterprise/inventory/sessions/{session}/close`

Permission `enterprise.inventory.manage`.

```json
{ "notes": "Floors 1-3 complete, floor 4 deferred", "force": false }
```

`force: false` and an incomplete count returns **409** with the remaining number,
so closing a half-finished count is a deliberate act:

```json
{ "status": "error",
  "messages": { "session": ["159 of 418 assets have not been scanned. Send force=true to close anyway."] },
  "payload": { "expected": 418, "found": 259 } }
```

Closing sets `status = closed`, `closed_at`, `closed_by`, and **does not change
any asset**. Deciding that an unscanned asset is missing is a human judgement
made from the discrepancy report; the close endpoint never mass-updates statuses.

### `GET /enterprise/inventory/sessions/{session}/discrepancies`

Permission `enterprise.inventory.manage` or `reports.view`.
`?type=missing|unexpected|moved|condition_changed|unresolved`, paginated, and
`Accept: text/csv` returns the same rows as CSV.

```json
{
  "session": { "id": 7, "name": "Building B — Q3 2026", "status": "closed" },
  "summary": { "missing": 159, "unexpected": 5, "moved": 22, "condition_changed": 4, "unresolved": 1 },
  "total": 191,
  "rows": [
    { "type": "missing", "asset": { "id": 77, "asset_tag": "SNIPE-000077", "name": "Dell U2720Q" },
      "expected_location": "Building B", "assigned_to": "M. Ali",
      "last_audit_date": "2025-09-30", "last_seen_action_log_id": 71233 },
    { "type": "moved", "asset": { "id": 418, "asset_tag": "SNIPE-000418" },
      "expected_location": "Building B", "found_location": "Building C", "scanned_by": "S. Counter" }
  ]
}
```

## 2. Offline sync

### Migration

`2026_xx_xx_create_enterprise_sync_operations_table`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigIncrements | |
| `client_operation_id` | uuid, **unique** | the idempotency key |
| `device_id` | string(128), indexed | which device sent it |
| `user_id` | unsignedInteger, indexed | who it acts as |
| `operation` | string(64) | `inventory.scan`, `asset.checkout`, `asset.checkin`, `asset.audit` |
| `request_payload` | json | as received |
| `response_payload` | json nullable | what we returned, replayed verbatim |
| `http_status` | smallInteger nullable | |
| `state` | enum(`applied`,`rejected`,`conflict`) , indexed | |
| `client_timestamp` | datetime | device clock |
| `applied_at` | datetime nullable | server clock |
| timestamps | | |

Retention: keep 90 days, then prune by scheduled command. The audit trail lives
in `action_logs`; this table exists only to make retries safe.

### `POST /enterprise/sync/batch`

Permission: whatever each operation requires, checked **per operation**, not once
for the batch.

```json
{
  "device_id": "pixel-7a-ops-004",
  "operations": [
    { "client_operation_id": "9f1c...8e01", "operation": "inventory.scan",
      "client_timestamp": "2026-10-10T09:41:22+03:00",
      "payload": { "session_id": 7, "scanned_value": "SNIPE-000418", "status_id": 2 } },
    { "client_operation_id": "a2b4...11c9", "operation": "asset.checkin",
      "client_timestamp": "2026-10-10T09:44:01+03:00",
      "payload": { "asset_id": 418, "status_id": 1, "note": "returned to store" } }
  ]
}
```

200, **always a per-row envelope** even when some rows failed:

```json
{
  "status": "success",
  "messages": "2 operations processed: 1 applied, 1 conflict",
  "payload": {
    "applied": 1, "rejected": 0, "conflicts": 1, "replayed": 0,
    "results": [
      { "client_operation_id": "9f1c...8e01", "state": "applied", "http_status": 201,
        "payload": { "scan_id": 2041, "outcome": "found" } },
      { "client_operation_id": "a2b4...11c9", "state": "conflict", "http_status": 409,
        "messages": { "asset": ["Already checked in on the server at 2026-10-10 09:43:10 by another user."] },
        "payload": { "server_state": { "assigned_to": null, "last_checkin": "2026-10-10 09:43:10" } } }
    ]
  }
}
```

Rules:

1. **Idempotency.** `client_operation_id` is a client-generated UUIDv4, unique
   across devices. A replay returns the stored `response_payload` with
   `"replayed": true` and changes nothing. The uniqueness is enforced by the
   database index, not by a read-then-write check, so two concurrent replays
   cannot both apply.
2. **Order.** Operations are applied in array order, which the client must keep
   as its queue order. A failure does **not** stop the batch — each row is
   independent and the client retries only the failures.
3. **Batch size.** Maximum 100 operations, 1 MB body. 422 if exceeded. The client
   chunks.
4. **No transaction across the batch.** Each operation commits on its own.
   Wrapping the batch would mean one bad scan discarding 99 good ones.
5. **Per-operation authorisation**, so a token that may audit but not check in
   gets `applied` on the scans and `rejected` on the checkin.
6. **Clock.** `client_timestamp` is recorded as reported and used for conflict
   reporting, but the server's own time is authoritative for
   `last_audit_date` and `last_checkin`. Device clocks drift and cannot be
   trusted for ordering.

### Conflict rules, per operation

| Operation | Conflict | Resolution |
| --- | --- | --- |
| `inventory.scan` | asset already scanned in this session | `applied` with `outcome: duplicate`; not an error |
| `inventory.scan` | session closed while offline | **`conflict`**, 409, nothing written — the count is finished and its report may be signed |
| `asset.audit` | another audit landed first | `applied`; audits are idempotent in effect, last writer wins on `last_audit_date` |
| `asset.checkin` | already checked in server-side | `conflict`, returning the server state so the user can see who did it |
| `asset.checkout` | already checked out to someone else | `conflict`, never silently reassigned — reassignment is a decision, not a merge |
| `asset.checkout` | checked out to the same target | `replayed`, treated as the same intent |
| any | asset soft-deleted while offline | `rejected`, 422 |
| any | asset now outside the user's company scope | `rejected`, 404 shape, no information leaked about its existence |

The principle: **a count conflict is informational, an assignment conflict is a
stop.** Scanning twice is harmless; quietly moving someone else's asset is not.

## 3. Asset transfers between departments

### Migrations

`2026_xx_xx_add_department_id_to_assets_table` — the one change to an upstream
table in this whole design:

```php
Schema::table('assets', function (Blueprint $table) {
    $table->unsignedInteger('department_id')->nullable()->after('company_id');
    $table->index('department_id');
});
```

Nullable, no foreign key — consistent with the surrounding columns, several of
which have their constraints commented out (`DATABASE_SCHEMA.md`). The column is
added by us but **read through an added relation on a subclassed model or via a
local scope**, never by editing `Asset::$fillable` in place; the mechanism is in
`CUSTOMISATION_RULES.md`. No backfill: a one-off command can derive it from
`assigned_to -> users.department_id` for currently assigned assets, and
leaving the rest null is honest.

`2026_xx_xx_create_enterprise_asset_transfers_table`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigIncrements | |
| `asset_id` | unsignedInteger, indexed | |
| `from_department_id`, `to_department_id` | unsignedInteger nullable | |
| `from_location_id`, `to_location_id` | unsignedInteger nullable | optional physical move |
| `state` | enum(`requested`,`approved`,`rejected`,`completed`,`cancelled`) default `requested`, indexed | |
| `reason` | text nullable | required on request |
| `requested_by`, `approved_by`, `completed_by` | unsignedInteger nullable | |
| `requested_at`, `approved_at`, `completed_at` | datetime nullable | |
| `approval_note`, `rejection_reason` | text nullable | |
| timestamps, `deleted_at` | | |

A partial unique index on `asset_id` where `state` in (`requested`,`approved`)
prevents two open transfers for one asset.

### Endpoints

| Method | Path | Permission |
| --- | --- | --- |
| POST | `/enterprise/transfers` | `assets.edit` or `assets.checkout` |
| GET | `/enterprise/transfers` | `assets.view` |
| GET | `/enterprise/transfers/{transfer}` | `assets.view` |
| POST | `/enterprise/transfers/{transfer}/approve` | `enterprise.transfers.approve` |
| POST | `/enterprise/transfers/{transfer}/reject` | `enterprise.transfers.approve` |
| POST | `/enterprise/transfers/{transfer}/complete` | `assets.edit` |
| POST | `/enterprise/transfers/{transfer}/cancel` | requester or approver |

Request:

```json
{ "asset_id": 418, "to_department_id": 4, "to_location_id": 19,
  "reason": "Moving with R. Hassan to Finance" }
```

201:

```json
{ "status": "success", "messages": "Transfer requested",
  "payload": { "id": 31, "state": "requested",
    "asset": { "id": 418, "asset_tag": "SNIPE-000418" },
    "from": { "department": "IT", "location": "Building B" },
    "to": { "department": "Finance", "location": "Building C" },
    "requested_by": { "id": 9, "name": "S. Custodian" },
    "requested_at": "2026-10-10 11:20:00" } }
```

Approve:

```json
{ "approval_note": "Approved, collect Thursday" }
```

Complete — the only step that touches the asset:

```json
{ "note": "Handed over", "checkin_first": true }
```

Rules:

1. **Three states, three actors.** Request, approve, complete are separate calls.
   The requester may not approve their own transfer; attempting it returns 403.
   A single person holding both permissions must still make two calls, so the
   record shows the decision happened.
2. **Approval does not move anything.** Only `complete` writes
   `department_id`, and `location_id` when a move was requested.
3. **`checkin_first`.** If the asset is checked out to a person and the transfer
   changes department, completing with `checkin_first: true` performs a real
   checkin through `CheckoutableCheckedIn` so the notification and action log
   happen. With `false`, the holder keeps it and only the department changes —
   legitimate when a person moves team with their laptop.
4. **Every transition writes an `action_logs` row** with `action_type` values
   `transfer_requested`, `transfer_approved`, `transfer_rejected`,
   `transfer_completed`. New action types are additive strings; nothing upstream
   enumerates them exhaustively.
5. 409 on a second open transfer for the same asset.
6. Transfers across companies are **rejected** in phase 1: that is an FMCS
   boundary crossing, and it needs its own decision about who may authorise it.

## 4. Maintenance schedules and warranty alerts

### Migration

`2026_xx_xx_create_enterprise_maintenance_schedules_table`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigIncrements | |
| `name` | string(255) | "Quarterly generator service" |
| `scope_type` | enum(`asset`,`model`,`category`,`location`) | what it applies to |
| `scope_id` | unsignedInteger | the id within that scope |
| `maintenance_type_id` | unsignedInteger | reuses the v8.8 `maintenance_types` table |
| `interval_days` | unsignedInteger | the cadence |
| `lead_time_days` | unsignedInteger default 14 | how early it appears as due |
| `supplier_id` | unsignedInteger nullable | |
| `estimated_cost` | decimal(15,2) nullable | |
| `is_active` | boolean default true, indexed | |
| `last_generated_at`, `next_due_at` | datetime nullable, `next_due_at` indexed | |
| `notify_user_ids` | json nullable | who to alert |
| `created_by` | unsignedInteger nullable | |
| timestamps, `deleted_at` | | |

No new table for warranty alerts: the data is already
`assets.purchase_date` + `assets.warranty_months`, and duplicating it would let
the copy drift.

### Endpoints

| Method | Path | Permission |
| --- | --- | --- |
| GET, POST | `/enterprise/maintenance-schedules` | `assets.view` / `assets.edit` |
| GET, PATCH, DELETE | `/enterprise/maintenance-schedules/{schedule}` | `assets.view` / `assets.edit` |
| GET | `/enterprise/alerts` | `assets.view` |
| POST | `/enterprise/maintenance-schedules/{schedule}/generate` | `assets.edit` |

```json
{ "name": "Quarterly generator service", "scope_type": "category", "scope_id": 11,
  "maintenance_type_id": 3, "interval_days": 90, "lead_time_days": 14,
  "supplier_id": 6, "estimated_cost": 450.00, "notify_user_ids": [3, 9] }
```

`GET /enterprise/alerts?types=warranty_expiring,maintenance_due,audit_overdue&within_days=30`
— one call for everything that needs attention:

```json
{
  "within_days": 30,
  "summary": { "warranty_expiring": 14, "maintenance_due": 6, "audit_overdue": 212, "eol_approaching": 3 },
  "total": 235,
  "rows": [
    { "type": "warranty_expiring", "asset": { "id": 418, "asset_tag": "SNIPE-000418", "name": "ThinkPad T14" },
      "due_date": "2026-11-02", "days_remaining": 23,
      "detail": { "purchase_date": "2023-11-02", "warranty_months": 36, "supplier": "Acme IT" } },
    { "type": "maintenance_due", "asset": { "id": 55, "asset_tag": "SNIPE-000055" },
      "due_date": "2026-10-19", "days_remaining": 9,
      "detail": { "schedule_id": 2, "maintenance_type": "Generator service", "interval_days": 90 } }
  ]
}
```

Warranty expiry is **computed in the query**
(`purchase_date + warranty_months` within the window), not stored. Assets with a
null `purchase_date` or null `warranty_months` are excluded and counted
separately as `unknown_warranty`, because silently dropping them would read as
"nothing expiring".

`generate` creates the actual `maintenances` rows for a schedule, with `item_id`
and `item_type` (never `asset_id`), under the caller's authorisation. A scheduled
command does the same nightly; the endpoint exists so it can be triggered and
tested by hand.

Notification delivery (mail, Slack) is **phase 2**. `GET /enterprise/alerts`
being pollable is enough to prove the data is right before anyone's inbox
depends on it.

## 5. Dashboard KPIs

### `GET /enterprise/dashboard/kpis`

Permission `reports.view`. One call, aggregates only, no asset rows — so the
Executive group (which has no `users.view` and no `assets.files`) can use it
without seeing who holds what.

Query: `?company_id=`, `?location_id=`, `?department_id=`,
`?missing_status_ids=7,8`, `?damaged_status_ids=9`.

```json
{
  "generated_at": "2026-10-10 11:45:00",
  "scope": { "company_id": 1, "location_id": null, "department_id": null },
  "assets": {
    "total": 4182, "deployed": 3104, "ready_to_deploy": 812,
    "pending": 44, "archived": 160, "undeployable": 62,
    "missing": 31, "damaged": 12, "byod": 58
  },
  "value": { "currency": "IQD", "total_purchase_cost": 1842500000, "depreciated_value": 912300000 },
  "inventory": {
    "open_sessions": 2,
    "sessions": [ { "id": 7, "name": "Building B — Q3 2026", "expected": 418, "found": 259, "percent_complete": 62.0 } ],
    "assets_never_audited": 118, "assets_audit_overdue": 212
  },
  "alerts": { "warranty_expiring_30d": 14, "maintenance_due_30d": 6, "eol_approaching_90d": 3, "unknown_warranty": 203 },
  "transfers": { "pending_approval": 4, "approved_awaiting_completion": 2 },
  "activity_7d": { "checkouts": 88, "checkins": 61, "audits": 1204 }
}
```

Design notes:

- **`missing` and `damaged` are configuration, not inference.** The status ids
  are passed in, or read from a setting; nothing in Snipe-IT marks an asset
  "missing" (`FEATURE_REVIEW.md`). Without them the fields are `null`, not `0` —
  an unconfigured KPI must not read as good news.
- Counts come from aggregate queries over models so FMCS applies; cached for
  60 seconds per scope, because this is the one endpoint everyone hits at 9am.
- `activity_7d` reads `action_logs`, which is the table most likely to be large;
  it is counted over an indexed `created_at` window and is the first thing to
  drop if the endpoint gets slow.

## 6. AI assistant

### `POST /enterprise/ai/ask`

Permission: a new `enterprise.ai.ask`, plus — and this is the point — **the tool
layer runs entirely under the asking user's own permissions**. There is no
service account, no elevated query path, and no raw SQL anywhere in it.

```json
{ "question": "Which laptops in Finance are overdue for audit?",
  "conversation_id": null, "max_tools": 5 }
```

200:

```json
{
  "status": "success",
  "payload": {
    "conversation_id": "c1f2...",
    "answer": "14 laptops assigned to Finance are past their next audit date. The oldest is SNIPE-000077, last audited 30 September 2025.",
    "tool_calls": [
      { "tool": "count_assets", "arguments": { "department_id": 4, "category_id": 2, "audit_overdue": true }, "result_summary": "14" },
      { "tool": "search_assets", "arguments": { "department_id": 4, "audit_overdue": true, "sort": "last_audit_date", "limit": 5 }, "result_summary": "5 rows" }
    ],
    "model": "openai/gpt-4o-mini",
    "tokens": { "prompt": 1840, "completion": 96 },
    "grounded": true
  }
}
```

### How it is wired

OpenRouter's OpenAI-compatible endpoint
(`POST https://openrouter.ai/api/v1/chat/completions`), with
`OPENROUTER_API_KEY`, `OPENROUTER_MODEL` and an `ai_enabled` setting in
`config/enterprise.php`. Model choice is configuration, so a provider problem is
a settings change rather than a code change.

**The tool contract — the whole security design in one list.** Each tool is a
named PHP method with a typed signature that builds an **Eloquent query**. The
model may only choose a tool and its arguments; it never produces SQL, never
sees a query string, and never receives a database connection.

| Tool | Arguments | Returns | Permission required |
| --- | --- | --- | --- |
| `search_assets` | `search`, `status_id`, `model_id`, `category_id`, `location_id`, `department_id`, `assigned_to`, `audit_overdue`, `limit` (max 25) | asset rows, fields filtered by permission | `assets.view` |
| `get_asset` | `asset_id` or `asset_tag` | one asset | `assets.view` |
| `count_assets` | same filters as `search_assets` | an integer | `assets.view` |
| `list_overdue_audits` | `location_id`, `department_id`, `limit` | asset rows | `assets.view` |
| `get_inventory_progress` | `session_id` | the progress object | `assets.audit` |
| `list_alerts` | `types`, `within_days` | the alerts summary | `assets.view` |
| `get_dashboard_kpis` | scope filters | the KPI object | `reports.view` |
| `get_asset_history` | `asset_id`, `limit` | action log rows | `assets.view` |

Enforcement, in order, on every tool call:

1. The tool's permission is checked with `Gate` / the policy for the **asking
   user**. A user without `reports.view` asking for KPIs gets a refusal, not a
   number.
2. The query runs through Eloquent, so `CompanyableScope` restricts it to the
   user's companies.
3. **Field-level redaction before the result reaches the model.**
   `purchase_cost` is stripped unless the user has `self.view_purchase_cost` or
   equivalent; encrypted custom fields are stripped unless the user has
   `assets.view.encrypted_custom_fields`; user names are stripped unless the
   user has `users.view`. The model cannot leak what it was never given.
4. `max_tools` (default 5, hard cap 10) bounds the loop; `limit` is capped at 25
   per tool call.
5. Every ask writes a row to `enterprise_ai_queries` — question, user, tools
   called with arguments, token counts, and whether an answer was produced. This
   is the only way to audit what the assistant told people.
6. If no tool returns data, the assistant **says it could not find anything**. It
   is not permitted to answer from the model's own knowledge: an invented asset
   tag in an asset management system is worse than no answer.

### Migration

`2026_xx_xx_create_enterprise_ai_queries_table`: `id`, `conversation_id` (uuid,
indexed), `user_id` (indexed), `company_id`, `question` (text),
`answer` (text nullable), `tool_calls` (json), `model` (string),
`prompt_tokens`, `completion_tokens`, `grounded` (boolean),
`error` (string nullable), timestamps.

### Privacy, stated plainly

Asset tags, model names, locations, department names and counts leave our
infrastructure and reach OpenRouter and the upstream model provider. That is a
decision for the data owner, not for us, and it belongs in the risk register in
`ROADMAP.md`. Three mitigations are part of the design rather than optional:
`ai_enabled` defaults to **off**; person names and purchase costs are redacted
before the model sees them unless the asking user has the permission; and the
feature is disabled per company by configuration, not only globally.

## Summary of new migrations

| Migration | Purpose |
| --- | --- |
| `add_department_id_to_assets_table` | the only upstream table touched |
| `create_enterprise_inventory_sessions_table` | counting sessions |
| `create_enterprise_inventory_scans_table` | scan stream, carries the idempotency key |
| `create_enterprise_sync_operations_table` | idempotent replay of offline work |
| `create_enterprise_asset_transfers_table` | request, approve, complete |
| `create_enterprise_maintenance_schedules_table` | recurring maintenance |
| `create_enterprise_ai_queries_table` | assistant audit trail |

Seven migrations, one new column on an existing table, no modified upstream
tables beyond that column.

## Open questions for review

1. **Department scoping.** The Department Custodian role cannot be properly
   enforced until `department_id` exists *and* a policy restricts a custodian to
   their own department. Should that policy be part of phase 1 — it changes the
   permission model, not just the schema.
2. **Missing and damaged.** Which existing status label ids mean these? The KPI
   and discrepancy endpoints need the answer, not a guess.
3. **Does a closed session mass-update statuses?** This design says no. If the
   business wants "unscanned becomes missing", that is a separate, explicit,
   reversible bulk action with its own permission.
4. **AI data leaving the network.** Approve, restrict to non-personal fields, or
   defer to a self-hosted model.
5. **Cross-company transfers.** Out of scope here; needed?
6. **Audit retention.** `action_logs` grows forever today. Agreeing a retention
   period now is cheaper than agreeing it at 10 million rows.
