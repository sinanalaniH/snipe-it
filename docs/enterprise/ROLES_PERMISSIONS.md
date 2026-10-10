# Roles and permissions

Sources: `config/permissions.php`, `app/Models/User.php` (`hasAccess`,
`checkPermissionSection`, `isSuperUser`), `app/Providers/AuthServiceProvider.php`,
`app/Policies/*`.

## How authorisation actually works here

There is **no roles table and no permissions table**. A permission group is a row
in `permission_groups` whose `permissions` column is a JSON map of permission
name to the string `"0"` or `"1"`. Users join groups through `users_groups`, and
`User::hasAccess($permission)` walks the user's groups and returns true if any of
them grants it. `superuser` short-circuits everything.

Three layers sit on top, and new code must use them rather than reading the JSON:

1. **Policies** (`app/Policies`) — `AssetPolicy`, `UserPolicy` and friends,
   mostly extending `SnipePermissionsPolicy`, which maps `view`, `create`,
   `update`, `delete`, `checkout`, `checkin`, `audit` onto the permission names
   below. Controllers call `$this->authorize('audit', $asset)`.
2. **Gates** (`AuthServiceProvider`) for things that are not a single model:
   `admin`, `import`, `reports.view`, `activity.view`,
   `assets.view.encrypted_custom_fields`, `canCheckoutAtLeastOneItemType`,
   `canViewUsersAndCheckoutables`, `canEditAuthFields`, `editableOnDemo`.
3. **Company scoping (FMCS)** — orthogonal to permissions. `CompanyableScope`
   restricts rows to the user's companies regardless of what the permission map
   says. A group can grant `assets.view` and the user still sees only their own
   company's assets. Our enterprise endpoints inherit this for free **only if**
   they query through Eloquent models.

Consequence for the enterprise API: every new endpoint declares a permission from
this list (or a new, additive one), authorises through a policy or gate, and
never reads `permission_groups.permissions` directly.

## The full permission list in v8.8.0

Global:

| Permission | Grants |
| --- | --- |
| `superuser` | everything, including settings and bypassing FMCS |
| `admin` | administration short of superuser |
| `import` | CSV importer |
| `reports.view` | the reports section |

Per item type — assets have the richest set:

| Asset permission | Grants |
| --- | --- |
| `assets.view` | see assets |
| `assets.create` | add assets |
| `assets.edit` | change assets |
| `assets.delete` | soft-delete assets |
| `assets.checkout` | assign an asset to a user, location or asset |
| `assets.checkin` | return an asset |
| `assets.audit` | record a physical audit (what inventory counting needs) |
| `assets.view.encrypted_custom_fields` | read encrypted custom field values |
| `assets.files` | attach and download files |
| `assets.view.requestable` | see the requestable catalogue |

The same `view / create / edit / delete` shape repeats for `accessories`
(plus `checkout`, `checkin`, `files`), `consumables` (plus `checkout`, `files`),
`licenses` (plus `checkout`, `checkin`, `keys`, `files`), `components` (plus
`checkout`, `checkin`, `files`), `kits`, `users` (plus `files`), `models` (plus
`files`), `categories`, `departments` (plus `files`), `statuslabels`,
`customfields`, `suppliers` (plus `files`), `manufacturers`, `depreciations`,
`locations` (plus `files`) and `companies` (plus `files`).

Self-service, scoped to the user's own record:

| Permission | Grants |
| --- | --- |
| `self.two_factor` | manage own 2FA |
| `self.api` | **create personal access tokens** — required for the mobile app |
| `self.edit_location` | change own location |
| `self.checkout_assets` | self-checkout |
| `self.view_purchase_cost` | see purchase cost of own items |

`licenses.keys` is worth singling out: without it a user sees a licence but not
its product key.

## Recommended groups

Six groups covering the operating model, each a row in `permission_groups`.
Everything not listed is `"0"`. All six grant `self.two_factor`; only the three
that use the mobile app or API get `self.api`.

### 1. System Admin

`superuser` only. Deliberately minimal and deliberately rare: it bypasses FMCS,
reaches settings, backups and the importer, and can read every encrypted custom
field. Two named people, not a shared account.

### 2. Asset Manager

The operational owner of the asset estate, within their own company.

```
assets.view, assets.create, assets.edit, assets.delete,
assets.checkout, assets.checkin, assets.audit, assets.files,
models.view, models.create, models.edit, models.files,
categories.view, manufacturers.view, suppliers.view, suppliers.create,
statuslabels.view, locations.view, departments.view,
accessories.*  (view, create, edit, checkout, checkin, files),
consumables.*  (view, create, edit, checkout, files),
components.*   (view, create, edit, checkout, checkin, files),
licenses.view, licenses.create, licenses.edit, licenses.checkout, licenses.checkin, licenses.files,
users.view, reports.view,
self.api, self.two_factor
```

Withheld on purpose: `licenses.keys` (product keys are a separate trust level),
`assets.view.encrypted_custom_fields`, `customfields.*`, `companies.*`,
`depreciations.*`, `import`, and every `delete` outside assets.

### 3. Department Custodian

Accountable for the assets of one department. Hands things out and takes them
back; does not create or destroy records.

```
assets.view, assets.checkout, assets.checkin, assets.audit,
accessories.view, accessories.checkout, accessories.checkin,
consumables.view, consumables.checkout,
models.view, categories.view, locations.view, departments.view,
users.view,
self.api, self.two_factor, self.checkout_assets, self.view_purchase_cost
```

Note what Snipe-IT cannot do today: there is **no permission that restricts a
custodian to their own department's assets**. FMCS scopes by company, not
department. Until the `department_id` column and transfer workflow in
`API_DESIGN.md` exist, a custodian with `assets.checkout` can check out any asset
in their company. Either accept that, or keep one company per department — a
blunt instrument with its own cost. This is the single biggest gap between the
requested role model and the product as shipped.

### 4. Inventory Counter

A phone user who verifies that assets exist where the system says they do, and
nothing more.

```
assets.view, assets.audit,
models.view, categories.view, locations.view,
self.api, self.two_factor
```

No `assets.edit`. The audit endpoint writes `last_audit_date`,
`next_audit_date`, an optional location change and an `action_logs` row without
needing edit rights, which is exactly the separation we want: a counter can
record what they found but cannot rewrite an asset record.

### 5. Auditor

Read-only across the estate, including history and reports. The only group
besides System Admin that should see purchase costs and encrypted fields.

```
assets.view, assets.view.encrypted_custom_fields, assets.files,
accessories.view, consumables.view, components.view, licenses.view,
models.view, categories.view, manufacturers.view, suppliers.view,
statuslabels.view, locations.view, departments.view, companies.view,
depreciations.view, customfields.view, kits.view,
users.view, reports.view,
self.two_factor, self.view_purchase_cost
```

No create, edit, delete, checkout, checkin or audit anywhere. No `self.api`: an
auditor reads in the browser. If an auditor needs API access for an external
analysis tool, add `self.api` to that individual's own second group rather than
to this one.

### 6. Executive

Dashboard and reports, no record-level browsing of people's equipment.

```
assets.view, reports.view,
models.view, categories.view, locations.view, departments.view, companies.view,
self.two_factor
```

Deliberately excludes `users.view`, `assets.files`, every checkout permission and
`self.view_purchase_cost` at group level. Executives want totals, not serial
numbers; the dashboard KPI endpoint in `API_DESIGN.md` is built for this group
and reads aggregates only.

## Matrix

`v` view, `c` create, `e` edit, `d` delete, `o` checkout, `i` checkin,
`a` audit, `-` none.

| Area | System Admin | Asset Manager | Dept Custodian | Inventory Counter | Auditor | Executive |
| --- | --- | --- | --- | --- | --- | --- |
| Assets | all | v c e d o i a | v o i a | v a | v | v |
| Asset files | yes | yes | - | - | v | - |
| Encrypted custom fields | yes | - | - | - | v | - |
| Accessories | all | v c e o i | v o i | - | v | - |
| Consumables | all | v c e o | v o | - | v | - |
| Components | all | v c e o i | - | - | v | - |
| Licences | all | v c e o i | - | - | v | - |
| Licence keys | yes | - | - | - | - | - |
| Models | all | v c e | v | v | v | v |
| Categories, manufacturers, suppliers | all | v (+ supplier c) | v | v | v | v (cat only) |
| Locations | all | v | v | v | v | v |
| Departments | all | v | v | - | v | v |
| Companies | all | - | - | - | v | v |
| Users | all | v | v | - | v | - |
| Custom fields, status labels, depreciations | all | v (status) | - | - | v | - |
| Reports | yes | yes | - | - | yes | yes |
| Import | yes | - | - | - | - | - |
| Settings, backups | yes | - | - | - | - | - |
| `self.api` | yes | yes | yes | yes | - | - |

## Rules for the enterprise work

1. **Reuse permissions before inventing them.** Inventory counting is
   `assets.audit`. The dashboard is `reports.view`. Nothing new needed.
2. New permissions go in `config/permissions.php` as an **additive** block and
   must default to denied for every existing group. Candidates:
   `enterprise.inventory.manage` (open and close a counting session) and
   `enterprise.transfers.approve` (approve a department transfer). Counting
   itself stays `assets.audit`, so an Inventory Counter needs no new grant.
3. The AI assistant endpoint runs **as the asking user**, not with elevated
   rights: every tool call goes through the same policies, so an Inventory
   Counter cannot ask it for purchase costs. See `API_DESIGN.md`.
4. Never grant `superuser` to a service account. Give it a narrow group and a
   personal access token belonging to a named, non-human user record.
5. Setting up these groups is a seeder, not a migration, so re-running it on an
   existing instance must not overwrite a group an administrator has edited.
