# Roadmap

Phases, effort, dependencies and risks for the work designed in
`API_DESIGN.md` and `MOBILE_APP.md`.

Effort is in **engineer-days for one experienced Laravel engineer**, including
tests and code review, excluding stakeholder time and app store review. Treat
them as relative sizes rather than promises: the numbers were produced from
reading this codebase, not from having built in it.

## Phase 0 — Foundations (blocking, 3–5 days)

| Task | Days |
| --- | --- |
| Run the local environment and correct `LOCAL_SETUP.md` from the real run | 1 |
| Confirm the schema against a live database and fix `DATABASE_SCHEMA.md` | 0.5 |
| Create the six permission groups as a seeder, idempotent and non-destructive | 1 |
| Scaffold the additive structure: `EnterpriseServiceProvider`, `routes/enterprise.php`, `config/enterprise.php`, one health endpoint, the three upstream registration lines | 1 |
| CI: run the existing suite plus an `Enterprise` suite on every push | 0.5–1 |

**Dependencies:** none. **Exit:** `GET /api/v1/enterprise/ping` answers with a
real token, CI is green, and the two unverified documents are verified.

Nothing else starts before this. Every estimate below assumes a working local
instance, and `LOCAL_SETUP.md` is currently written from the repository rather
than from a run.

## Phase 1 — Inventory sessions (8–12 days)

| Task | Days |
| --- | --- |
| Two migrations plus models and policies | 1 |
| Create, list, show — including the frozen expected set with child locations | 2 |
| Scan endpoint delegating to the existing audit path | 2–3 |
| Progress endpoint, with the aggregates indexed | 1 |
| Close, with the incomplete-count 409 | 1 |
| Discrepancy report, JSON and CSV | 2 |
| Feature tests including cross-company isolation | 1–2 |

**Dependencies:** phase 0. **Risks:** the expected-set query across a deep
location tree is the one likely performance surprise; measure it on real data
before the scan endpoint is built on top of it.

**Why first:** it is the highest-value gap, it needs no schema change to
`assets`, and it can be used from the web UI or curl before any app exists —
which means it can be validated by an actual count in an actual building.

## Phase 2 — Offline sync (5–8 days)

| Task | Days |
| --- | --- |
| `enterprise_sync_operations` migration and the idempotency store | 1 |
| Batch endpoint with per-operation authorisation and per-row results | 2–3 |
| Conflict rules per operation type | 1–2 |
| Replay and concurrency tests, including two simultaneous replays of one key | 1–2 |

**Dependencies:** phase 1 (`inventory.scan` is the first operation type).
**Risks:** the conflict matrix is where correctness lives. Get the tests written
before the implementation; this is the part that is expensive to fix after a
pilot has produced data.

## Phase 3 — Department and transfers (6–9 days)

| Task | Days |
| --- | --- |
| `assets.department_id` migration plus the additive model access | 1 |
| Optional backfill command from `assigned_to -> users.department_id` | 0.5 |
| Transfers table, model, state machine | 1.5 |
| Request, approve, reject, complete, cancel, with the self-approval block | 2–3 |
| `checkin_first` path through the existing checkin event | 1 |
| Tests, including the partial unique index on open transfers | 1–2 |

**Dependencies:** phase 0. Independent of phases 1 and 2 — this is the one that
can run in parallel if there are two engineers.

**Risks:** the open question in `API_DESIGN.md` about whether a Department
Custodian should be *restricted* to their own department. If the answer is yes,
add 3–5 days for a department-scoped policy, and expect it to interact awkwardly
with FMCS, which scopes by company. Decide before starting, not during.

## Phase 4 — Maintenance schedules and alerts (5–7 days)

| Task | Days |
| --- | --- |
| Schedules migration, model, CRUD | 2 |
| Generation command and endpoint creating `maintenances` rows | 1.5 |
| `GET /enterprise/alerts` with computed warranty expiry | 1.5 |
| Tests, including the null `purchase_date` and null `warranty_months` cases | 1 |

**Dependencies:** phase 0. **Risks:** low. The `unknown_warranty` count will
likely be large on real data, and that is a data-quality finding to report, not a
bug to hide.

## Phase 5 — Dashboard KPIs (3–5 days)

| Task | Days |
| --- | --- |
| Aggregate queries per section | 2 |
| Caching per scope, and the configurable missing and damaged status ids | 1 |
| Tests, including the unconfigured case returning null rather than zero | 1 |
| Index review on `action_logs.created_at` for the activity window | 0.5–1 |

**Dependencies:** phases 1, 3 and 4 for the inventory, transfer and alert
sections — build it last so it reports on things that exist. **Risks:**
`action_logs` size. Measure before shipping; this endpoint is the one everybody
loads at 9am.

## Phase 6 — Flutter app (20–30 days)

A different skill set and a separate repository (`MOBILE_APP.md`).

| Task | Days |
| --- | --- |
| Project setup, CI, signing, distribution | 2–3 |
| Onboarding and token storage | 2 |
| Scanner, with resolution and the host check | 3 |
| Verify screen with permission-shaped actions | 3 |
| Counting screen | 3–4 |
| Drift schema, queue, optimistic local state | 4–5 |
| Sync engine, backoff, conflict presentation | 3–4 |
| Photo capture and deferred upload | 2 |
| Tests, including the airplane-mode and replay runs | 3 |

**Dependencies:** phases 1 and 2 must be deployed somewhere the app can reach.
**Risks:** the queue surviving an app update with work in it; iOS background
upload limits; store review cycles on every fix. Budget for a pilot on 2–3
devices before any wider rollout.

## Phase 7 — AI assistant (8–12 days)

| Task | Days |
| --- | --- |
| OpenRouter client, config, `ai_enabled` default off | 1 |
| Tool definitions as typed Eloquent queries | 3 |
| Permission checks and field-level redaction per tool | 2–3 |
| Tool-calling loop with `max_tools` and limit caps | 2 |
| `enterprise_ai_queries` audit table and the grounded-answer rule | 1 |
| Tests, especially a user without `reports.view` being refused KPIs and purchase cost never reaching the model | 2 |

**Dependencies:** phases 1, 4 and 5 — the tools read what those built. **Put it
last**, and not only for dependency reasons: it is the feature most likely to be
cut by a privacy decision, and everything before it has standalone value.

## Shape of the whole thing

```
Phase 0  ████                                        3-5d    blocking
Phase 1  ████████████                                8-12d
Phase 2  ████████                                    5-8d    needs 1
Phase 3  █████████                                   6-9d    parallel with 1-2
Phase 4  ███████                                     5-7d    parallel
Phase 5  █████                                       3-5d    needs 1,3,4
Phase 6  ██████████████████████████████              20-30d  needs 1,2
Phase 7  ████████████                                8-12d   needs 1,4,5
```

Server side, one engineer, sequential: **38–58 days**. With the app:
**58–88 days**. Two engineers splitting server work and app work compresses the
calendar to roughly 35–50 days, not half, because phase 0 and the phase 1
dependency do not parallelise.

## Risk register

### 1. AGPL-3.0 obligations — likelihood certain, impact high

Snipe-IT is AGPL-3.0. Running a **modified** version and letting users interact
with it **over a network** obliges us to offer those users the corresponding
source of our modifications. This is not triggered by distribution alone;
operating it as a service is enough.

*Mitigation:* keep the fork public, or keep it private with a documented and
honoured source offer. Keep `LICENSE` and headers intact. Keep the Flutter app in
its own repository as a separate program. **Get legal sign-off before the first
production deployment.** Covered in `CUSTOMISATION_RULES.md`; not legal advice.

### 2. Upstream upgrade cost — likelihood high, impact medium

Snipe-IT releases often, and 495 migrations plus a 1,573-line `routes/api.php`
tell you how much it moves.

*Mitigation:* the additive rules, and specifically the three-file limit on
upstream edits. Merge upstream **monthly, in a small change**, rather than
annually in a large one. The duplicate-column case (upstream adds
`department_id` too) is the nastiest: guard our migration with
`Schema::hasColumn`.

### 3. Offline conflicts — likelihood high, impact medium

Two counters in one room, a session closed while a phone was offline, a checkin
that already happened. Data loss here is invisible until an audit disagrees with
reality.

*Mitigation:* the conflict matrix in `API_DESIGN.md`, server-authoritative
clocks, database-enforced idempotency, and conflicts shown to the user rather
than resolved silently. Count conflicts are informational; assignment conflicts
stop. Test replay and concurrency before the pilot.

### 4. AI data privacy — likelihood certain if enabled, impact high

Asset tags, model names, locations, department names and counts leave our
infrastructure for OpenRouter and the upstream provider. In some sectors that
alone is unacceptable, regardless of what is redacted.

*Mitigation:* `ai_enabled` off by default and per company; names and purchase
costs redacted before the model sees them unless the asking user holds the
permission; every ask logged; a self-hosted model as the fallback option.
**This is the data owner's decision, and it should be made before phase 7 is
estimated into a plan anyone commits to.**

### 5. Tenant leakage through raw SQL — likelihood medium, impact critical

FMCS is enforced by Eloquent global scopes. One `DB::table('assets')` in a
reporting query and company A sees company B's assets. It will not fail a test
that does not look for it.

*Mitigation:* rule 3 in `CUSTOMISATION_RULES.md`, a cross-company isolation test
per feature, and a grep for `DB::` in our directories as a CI check.

### 6. `action_logs` growth — likelihood high, impact medium

Every checkout, checkin and audit writes a row, and a single inventory count of
4,000 assets writes 4,000. The KPI endpoint and the activity report read this
table.

*Mitigation:* index review in phase 5, a retention decision taken early, and
`activity_7d` as the first thing to drop if the dashboard slows.

### 7. Department scoping is a product-shaped gap — likelihood certain, impact medium

Snipe-IT scopes by company, not department. The requested Department Custodian
role cannot be fully enforced today.

*Mitigation:* decide in phase 3 between a department-scoped policy (real work,
interacts with FMCS) and one company per department (blunt, with its own costs).
Do not let this be discovered during a pilot.

### 8. Estimates are unvalidated — likelihood certain, impact low

Every number here comes from reading the code, not from building in it. No
feature code has been written and the local environment has not been run.

*Mitigation:* re-estimate after phase 0, when the first real contact with the
codebase has happened.

## Recommended sequence

1. **Phase 0 immediately** — it unblocks everything and it validates the two
   documents written without a running instance.
2. **Phase 1, then a real count in a real building.** One session, one location,
   counted by hand with curl or the web UI. That single exercise will teach more
   about the requirements than any further design.
3. **Phases 2 and 3 in parallel** if there are two engineers; phase 2 first if
   there is one, because the app depends on it.
4. **Phases 4 and 5**, which make the work visible to management and earn the
   next tranche of attention.
5. **Phase 6**, piloted on a handful of devices.
6. **Phase 7 only after the privacy decision is written down.**

## Decisions needed before phase 1 ends

Repeated from `API_DESIGN.md` because they block implementation, not design:

1. Which status label ids mean **missing** and **damaged**?
2. Should a closed session **mass-update** unscanned assets? (Design says no.)
3. Must a Department Custodian be **restricted** to their own department?
4. Is **AI data leaving the network** acceptable?
5. Are **cross-company transfers** needed?
6. What is the **audit log retention** period?
