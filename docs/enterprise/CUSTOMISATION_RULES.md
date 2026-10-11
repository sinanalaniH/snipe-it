# Customisation rules — keep upstream upgrades cheap

**These rules apply to all future code on `custom-asset-management`.** They exist
for one reason: this fork has to track upstream Snipe-IT, and every upstream file
we edit is a merge conflict we pay for again at every release. Snipe-IT ships
frequently, and `routes/api.php` alone is 1,573 lines that upstream edits often.

The rule in one sentence: **add files, do not edit them.**

## Where our code lives

| Kind | Location |
| --- | --- |
| Controllers | `app/Http/Controllers/Api/Enterprise/` |
| Form requests | `app/Http/Requests/Enterprise/` |
| Models | `app/Models/Enterprise/` |
| Policies | `app/Policies/Enterprise/` |
| Transformers | `app/Http/Transformers/Enterprise/` |
| Services and query builders | `app/Services/Enterprise/` |
| Console commands | `app/Console/Commands/Enterprise/` |
| Routes | `routes/enterprise.php` (one new file) |
| Config | `config/enterprise.php` (one new file) |
| Migrations | `database/migrations/` — new files only, never edited ones |
| Seeders | `database/seeders/Enterprise/` |
| Tests | `tests/Feature/Enterprise/`, `tests/Unit/Enterprise/` |
| Docs | `docs/enterprise/` |

## The only upstream files we touch

Three registration points, one line each. Keep this list honest — if it grows,
say so in review rather than letting it grow quietly.

1. **`app/Providers/RouteServiceProvider.php`** — one `require` for
   `routes/enterprise.php`, alongside the existing requires for `routes/scim.php`
   and the `routes/web/*.php` files. The surrounding code already has that shape,
   so the conflict, if any, is a one-line resolution.
2. **`config/app.php`** — register `EnterpriseServiceProvider` in the providers
   array (this version has no `bootstrap/providers.php`).
3. **`config/permissions.php`** — an appended block for the new permissions, at
   the end, so an upstream insertion elsewhere does not overlap it.

Everything else is a new file.

## Rules

### 1. Never modify an upstream migration

Add a new one. An edited migration has already run on every existing instance and
will never run again, so the edit is invisible in production and present only in
a fresh install — the worst kind of divergence.

### 2. Extend models, do not edit them

`assets.department_id` is our column on an upstream table. Reach it without
editing `app/Models/Asset.php`:

- **Preferred:** a trait or a service that queries the column explicitly, with
  the column named in a `select`, plus a local scope registered from our service
  provider.
- **Acceptable:** an `Enterprise\EnterpriseAsset extends \App\Models\Asset` with
  the extra relation and fillable entry, used only by our controllers.
- **Never:** adding `department_id` to the upstream model's `$fillable` and
  hoping the next merge keeps it.

Mass assignment of our column goes through an explicit `forceFill` or an
assignment plus `save()` in our own service, so it does not depend on an upstream
array.

### 3. Query through Eloquent, never raw SQL

`CompanyableScope` and `CompanyableChildScope` apply FMCS tenant filtering as
global query scopes. A `DB::select()` or a `DB::table()` call **silently crosses
the tenant boundary**. Every read in our code goes through a model, and if a
query is too awkward for Eloquent, it goes in `app/Services/Enterprise/` with a
test that proves the company filter is present.

This is the single most important rule here. It is a security rule wearing an
architecture rule's clothes.

### 4. Authorise the resolved instance

Copy `AssetsController::audit()`: authorise the class, then authorise the model
you actually resolved, especially when the identifier came from a request body.
Resolving an id and trusting the lookup leaves tenant enforcement depending on a
scope firing somewhere else.

### 5. Reuse events, never reimplement state changes

Checkout, checkin, audit and acceptance have events and listeners that write the
action log, create acceptance records and send mail (`WORKFLOWS.md`). Writing
`assigned_to` directly skips all of it. Our endpoints call the existing paths and
record our own metadata alongside, pointing at the `action_logs` row that the
upstream path created.

### 6. Permissions are additive and default to denied

New permissions append to `config/permissions.php` and must be absent from every
existing `permission_groups.permissions` map, which means denied. Never
retro-grant a new permission to existing groups in a migration: an administrator
would be handed authority they did not choose.

### 7. Reuse the response envelope

`Helper::formatStandardApiResponse` for writes, `{ total, rows }` for lists. One
documented departure, in `API_DESIGN.md`: enterprise endpoints also return
accurate HTTP status codes. No other divergence.

### 8. Configuration, not constants

Status ids that mean "missing" or "damaged", the AI model name, batch limits,
cache TTLs — all `config/enterprise.php`, with env overrides. Hard-coding a
status id guarantees the code is wrong at the second customer.

### 9. Tests mirror the structure

`tests/Feature/Enterprise/` for endpoints, including a test per feature proving
that a user from company A cannot see company B's rows. The tenancy test is not
optional; it is the one that catches the mistake rule 3 is about.

### 10. No upstream dependency bumps for our convenience

Adding a Composer package means a conflict in `composer.json` and
`composer.lock` at every upgrade, and `composer.lock` conflicts are miserable.
Use what is installed. If a package is genuinely required, raise it in review
with the upgrade cost named.

## Keeping up with upstream

```bash
git remote add upstream https://github.com/grokability/snipe-it.git
git fetch upstream
git merge upstream/master        # or rebase, per team preference
```

Then check, in this order:

1. `config/permissions.php` — did upstream add permissions near our block?
2. `app/Providers/RouteServiceProvider.php` and `config/app.php` — our three
   lines.
3. `database/migrations/` — did upstream add a column we also added? Two columns
   with the same name in different migrations is a hard failure on a fresh
   install, and the only fix is a conditional `Schema::hasColumn` guard in ours.
4. `app/Models/Asset.php`, `app/Http/Controllers/Api/AssetsController.php`,
   `app/Listeners/CheckoutableListener.php` — not ours, but our code calls into
   them. Signature changes break us silently; the feature tests are the guard.
5. `php artisan test --testsuite=Feature --filter=Enterprise`.

## Licence obligation — read this before shipping

Snipe-IT is **AGPL-3.0** (`LICENSE`, GNU Affero General Public License v3). The
Affero clause is the one that matters: if we **run a modified version and let
users interact with it over a network**, those users must be offered the
corresponding source of our modified version. Not just redistribution —
operation as a service triggers it.

Practical consequences:

- Our modified Snipe-IT source must be available to its users. A public fork
  satisfies this comfortably. A private fork with a documented, honoured offer of
  source to users is the alternative, and it is more work to get right.
- Keep the licence headers and the `LICENSE` file intact.
- The Flutter app in a separate repository communicating over HTTP is a separate
  program and not covered (`MOBILE_APP.md`), which is one of the reasons it is
  separate.
- Get this confirmed by whoever owns legal risk **before** the first production
  deployment, not after. It is in the `ROADMAP.md` risk register.

I am not a lawyer and this is not advice; it is the reason the question has to be
asked early.
