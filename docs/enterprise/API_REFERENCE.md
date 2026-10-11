# API reference — the existing v1 endpoints we will build on

Source: `routes/api.php` (1,573 lines), `app/Http/Controllers/Api/*`,
`app/Http/Transformers/*`, `app/Http/Kernel.php`, `app/Helpers/Helper.php`,
`app/Http/Middleware/SetPaginationDefaults.php`.

This is not the whole API. It is the subset the mobile app and the enterprise
endpoints depend on, with the behaviour that is easy to get wrong written down.

## Conventions

**Base path** `/api/v1`. Every route lives in one group:
`['prefix' => 'v1', 'middleware' => ['api', 'api-throttle:api']]`.

**Authentication** is a Passport bearer token — `config/auth.php` defines the
`api` guard as `driver => passport`. The middleware stack is
`auth:oidc,api` (OIDC first, because Passport's TokenGuard clears the
Authorization header when it fails), then `CheckUserIsActivated`,
`EnforceApiTwoFactorEnrollment`, `EnforceApiUserAgent`, `CheckLocale`,
`LogAuthedUserHeader`, `SetPaginationDefaults`, `SubstituteBindings`.

Four consequences worth knowing before writing a client:

- **Send `Accept: application/json`.** Without it, errors come back as HTML.
- **Send a real `User-Agent`.** `EnforceApiUserAgent` can reject blank User-Agents
  and prefix-matches `curl/`, `PostmanRuntime/`, `python-requests/` when the
  admin enables `block_api_user_agents`. A mobile client must identify itself
  with its own UA string.
- **A deactivated user's token stops working** mid-session via
  `CheckUserIsActivated` — the client must treat a sudden 401/403 as "sign in
  again", not as a bug.
- **Two-factor enrolment can be enforced on the API.** If the instance requires
  it, token calls fail until the user enrols. Worth testing before a pilot.

**Token lifecycle**

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/account/personal-access-tokens` | create (requires `self.api`) |
| GET | `/account/personal-access-tokens` | list |
| DELETE | `/account/personal-access-tokens/{tokenId}` | revoke |
| POST | `/logout` | revoke the current token |

The first token has to come from the web UI (profile menu, Manage API Keys),
because creating one over the API needs a token already.

**Response envelope.** Writes use `Helper::formatStandardApiResponse` and return
`{ "status": "success" | "error", "messages": ..., "payload": ... }` — frequently
with **HTTP 200 even for `status: error`**. A client must check the body, not only
the status code. Reads return either a transformed object or a list:
`{ "total": N, "rows": [ ... ] }`.

**Pagination.** `limit` (capped at `app.max_results`), `offset` or `page`,
`sort`, `order=asc|desc`. `SetPaginationDefaults` converts `page` into an
offset, so do not send both.

**Dates** come back as objects shaped `{ "date": ..., "formatted": ... }` in many
transformers. Parse the raw `date`, never the formatted string.

## Assets — `/hardware`

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/hardware` | list and search; supports `search`, `status_id`, `model_id`, `location_id`, `company_id`, `category_id`, `assigned_to`, `order_number` |
| GET | `/hardware/{id}` | one asset |
| POST | `/hardware` | create |
| PATCH or PUT | `/hardware/{asset}` | update |
| PATCH | `/hardware/bulk` | bulk update |
| DELETE | `/hardware/{id}` | soft delete |
| POST | `/hardware/{asset_id}/restore` | undelete |
| GET | `/hardware/bytag/{tag}` | **resolve a scanned asset tag** |
| GET | `/hardware/byserial/{serial}` | resolve a serial |
| GET | `/hardware/selectlist` | lightweight picker list |
| GET | `/hardware/{asset}/history` | action log for one asset |
| GET | `/hardware/{asset}/licenses` | licence seats on the asset |
| GET | `/hardware/{asset}/assigned/assets`, `/accessories`, `/components` | what is attached to it |
| GET | `/hardware/{action}/{upcoming_status}` | due or overdue for audit, due for checkin |

Checkout and checkin:

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/hardware/{id}/checkout` | assign; body `checkout_to_type` (`user`, `location`, `asset`) plus `assigned_user`, `assigned_location` or `assigned_asset`, optional `expected_checkin`, `note`, `name`, `status_id` |
| POST | `/hardware/{id}/checkin` | return; optional `status_id`, `location_id`, `note` |
| POST | `/hardware/bytag/{tag}/checkout` | checkout straight from a scan |
| POST | `/hardware/bytag/{tag}/checkin` | checkin straight from a scan |
| POST | `/hardware/checkinbytag` | tag in the body instead of the path |

The `bytag` checkout and checkin routes are the ones the scanner should use: one
request instead of resolve-then-act, which also removes a race.

Audit — already richer than it looks:

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/hardware/audit` | legacy form; resolves the asset from the body via `audit_key` plus `audit_by_field`, falling back to `asset_tag` |
| POST | `/hardware/{asset}/audit` | newer form, asset bound from the URL |
| POST | `/hardware/audit/bulk` | **bulk audit**: `ids` names the assets, the rest of the body is the shared payload (note, `next_audit_date`, location, image), and the response is a per-row envelope with success and error counts |

Both single forms authorise twice — `authorize('audit', Asset::class)` and then
`authorize('audit', $resolvedAsset)` — specifically so FMCS scoping is enforced
on the resolved asset no matter how it was found. Any endpoint we add must copy
that pattern.

`POST /hardware/labels` generates label PDFs.

## Users — `/users`

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/users/me` | **the authenticated user**; the mobile app's session check |
| GET | `/users` | list and search |
| GET, POST, PATCH, DELETE | `/users`, `/users/{id}` | CRUD |
| GET | `/users/selectlist` | picker for checkout |
| GET | `/users/{user}/assets`, `/accessories`, `/consumables`, `/licenses` | what a person holds |
| GET | `/users/{user}/history` | their action log |
| GET | `/users/{user}/eulas` | accepted EULAs |
| POST | `/users/{user}/email` | send credentials |
| POST | `/users/{user}/restore` | undelete |
| POST | `/users/ldapsync` | LDAP sync |

## Reference data

Each of these has the same shape — `index`, `show`, `store`, `update`,
`destroy`, plus `selectlist` where a picker makes sense:

`/models`, `/categories` (`/categories/{item_type}/selectlist`),
`/manufacturers`, `/suppliers`, `/locations`, `/companies`, `/departments`,
`/statuslabels`, `/depreciations`, `/fields`, `/fieldsets`, `/groups`,
`/maintenance-types`.

Useful extras:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/locations/{location}/assets` | assets at a location — the inventory session's starting set |
| GET | `/locations/{location}/assigned/assets`, `/accessories` | assigned at a location |
| GET | `/locations/{location}/history` | location history |
| GET | `/statuslabels/{id}/assetlist` | assets in a status |
| GET | `/statuslabels/{statuslabel}/deployable` | is this status deployable |
| GET | `/statuslabels/assets/name`, `/assets/type` | status counts for charts |

## Maintenance — `/maintenances`

| Method | Path | Purpose |
| --- | --- | --- |
| GET, POST, PATCH, DELETE | `/maintenances`, `/maintenances/{id}` | CRUD |
| GET | `/maintenances/{maintenance}/history` | history |
| GET, POST | `/maintenances/{maintenance}/notes` | notes |
| POST | `/maintenances/{maintenance}/complete` | mark complete |
| GET, POST, … | `/maintenance-types` | the type catalogue added in v8.8 |

Create with `item_id` and `item_type`, not `asset_id` (see `DATABASE_SCHEMA.md`).

## Other checkoutables

- **Accessories** — `/accessories`, `{accessory}/checkout`, `{accessory}/checkin`,
  `{accessory}/checkedout`, `{accessory}/history`, `{accessory}/adjust-quantity`.
- **Consumables** — `/consumables`, `{consumable}/checkout`,
  `{consumable}/adjust-quantity`, `{id}/users`. No checkin: consumption is
  one-way.
- **Components** — `/components`, `{id}/checkout`, `{id}/checkin`,
  `{component}/assets`, `{component}/adjust-quantity`.
- **Licences** — `/licenses`, `{license_id}/checkout`, `{license_id}/checkin`,
  `/licenses/{license}/seats`, `{license}/history`.
- **Kits** — `/kits` plus `/kits/{kit_id}/{licenses|models|accessories|consumables}`
  for bundling what a new hire receives.

## Dashboard, reports, files

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/dashboard/activity` | recent activity |
| GET | `/dashboard/categories`, `/companies`, `/locations` | counts by dimension |
| GET | `/reports/activity` | the action log, filterable |
| GET | `/reports/activity/chart` | activity over time |
| GET | `/reports/depreciation` | depreciation report |
| GET | `/low-stock` | items under their `min_amt` |
| GET | `/calendar/events` | expected checkins and expiries as events |
| GET | `/version` | app version, no auth needed in practice — a cheap reachability probe |
| GET | `/labels`, `/labels/{name}` | label templates |
| GET, POST, DELETE | `/{object_type}/{id}/files[...]` | attachments on any object type |
| GET | `/settings/backups`, `/settings/backups/download/{file}` | superuser only |

`GET /dashboard/*` plus `GET /reports/activity/chart` already cover part of the
KPI requirement; `API_DESIGN.md` only adds what they do not.

## What this API does not give us

The gaps that justify new endpoints, each covered in `API_DESIGN.md`:

1. **No inventory session.** Bulk audit exists, but nothing models "count
   building B this quarter" — no expected set, no progress, no discrepancy
   report, no open/closed lifecycle.
2. **No batch sync with idempotency.** Every write is one request. An offline
   client replaying 200 scans makes 200 requests with no dedupe, so a retry
   double-posts.
3. **No department on an asset**, therefore no transfer between departments and
   no approval step.
4. **No warranty or maintenance alerting.** `warranty_months` and
   `/calendar/events` exist; a queryable "expiring in the next N days" list does
   not.
5. **No single KPI endpoint.** A dashboard today means four or five calls, and
   "missing" and "damaged" are conventions in status labels rather than
   first-class counts.
6. **No AI assistant.**

## Known rough edges to design around

- HTTP 200 with `status: error` in the body.
- Date objects rather than ISO strings in several transformers.
- `limit` silently capped at `app.max_results`.
- Custom field values appear as dynamically named keys on the asset payload, so a
  typed client needs a permissive map for them.
- Soft-deleted rows are excluded unless an endpoint opts into `withTrashed()`,
  which a few do and most do not.
