# Flutter app design — field asset verification and counting

**Design only.** Recommendation up front: **build this in a separate
repository**, not in this one. Reasons in the last section.

The app has one job: let someone walk around a building with a phone, scan what
is in front of them, and have the system agree with reality afterwards. Everything
else is secondary to that, including looking good.

## Scope

In:

- Sign in and hold a Passport token.
- Scan a QR or Code 128 label and resolve it to an asset.
- Verify an asset: see what the system believes, confirm or correct it.
- Count: work through an inventory session, see progress.
- Capture a photo as evidence.
- Work with no network, queue everything, sync when back.

Out of phase 1: creating assets, editing arbitrary fields, printing labels (the
QR image route is session-authenticated — see `QR_CODES.md`), licences,
accessories, reports, approvals.

## Platform

| Concern | Choice | Why |
| --- | --- | --- |
| Framework | Flutter, stable channel | one codebase, and the camera and SQLite packages below are mature |
| Scanner | `mobile_scanner` | MLKit on Android, AVFoundation on iOS; handles QR and Code 128 in one controller |
| Local database | `drift` over SQLite | typed queries, migrations, and a watchable stream for the queue badge |
| HTTP | `dio` | interceptors for the auth header, the User-Agent and retry |
| Secure storage | `flutter_secure_storage` | Keychain and Keystore for the token |
| State | `riverpod` | testable without a widget tree |
| Connectivity | `connectivity_plus` | a hint to start syncing, never a gate on queueing |

## Authentication

**Personal access token, pasted or scanned once.** Snipe-IT has no password-grant
API: `/api/v1/account/personal-access-tokens` needs a token already, so the first
one comes from the web UI. The onboarding flow is therefore:

1. The user signs in to Snipe-IT in a browser, creates a token under Manage API
   Keys (needs `self.api`).
2. In the app: enter the server URL, then paste the token — or scan a QR the web
   UI could render for it, which is a small upstream-side convenience we could
   add later.
3. The app calls `GET /api/v1/users/me` and stores the token in secure storage
   only if that succeeds. The response also gives the user's id, name,
   permissions-relevant flags and company, which drives what the UI offers.

Every request carries:

```
Authorization: Bearer <token>
Accept: application/json
User-Agent: SnipeFieldApp/1.0 (Android 14; pixel-7a)
```

The User-Agent is **not optional**: `EnforceApiUserAgent` can reject blank ones
and prefix-matched tool names (`API_REFERENCE.md`).

Token lifecycle in the app:

- A 401 clears the token and returns to onboarding — but **never discards the
  queue**. Unsynced work survives a sign-out; it belongs to the device, not to
  the session.
- A 403 on one action disables that action's button rather than signing out; the
  user may audit but not check in, and the UI should show that.
- Tokens do not refresh. There is no silent renewal to implement.

## Scanning

```
camera frame -> mobile_scanner -> raw string -> resolve
```

Resolution follows the decision table in `QR_CODES.md`, and **the host check is
mandatory**: a QR from another Snipe-IT instance contains a valid-looking asset
id that would resolve to the wrong asset in ours.

```dart
// shortcut: resolution order is tag, then serial, then URL id. Revisit if a
// customer's tag format ever collides with their serial format.
ScanTarget resolve(String raw, Uri serverBase) {
  final uri = Uri.tryParse(raw);
  if (uri != null && uri.hasScheme) {
    if (uri.host != serverBase.host) return ScanTarget.foreignHost(uri.host);
    final id = _assetIdFromPath(uri.path);           // /hardware/{id}
    return id != null ? ScanTarget.byId(id) : ScanTarget.unknown(raw);
  }
  return ScanTarget.byTag(raw.trim());               // falls back to byserial
}
```

Practical details that decide whether this is usable in a warehouse:

- **Debounce.** The camera emits the same code many times a second. Ignore a
  repeat of the same value within ~2 seconds, and show a visible "already
  scanned" state rather than silently dropping it.
- **Torch toggle** reachable with the thumb holding the phone. Server rooms and
  storerooms are dark; this is the most-used control after the shutter.
- **Haptic plus sound on a successful scan**, distinct from the failure sound.
  People count with the phone at arm's length and cannot read the screen.
- **Manual entry always available.** Labels get scratched. A text field that
  accepts a tag or serial is not a fallback, it is a feature.
- Keep the camera **running** between scans in counting mode; stopping and
  restarting the controller costs about a second each time and that is the whole
  interaction.

## Screens

1. **Onboarding** — server URL, token, verify.
2. **Home** — two large actions, Verify and Count, plus the queue badge
   ("7 waiting to sync") and the last sync time.
3. **Verify** — scan, then the asset: tag, name, model, status, who holds it,
   location, last audit date. Actions, each shown only if permitted: *Confirm*
   (audit), *Correct location*, *Check in*, *Report damaged or missing* (a status
   change), *Add photo*, *Add note*.
4. **Session list** — open sessions from
   `GET /enterprise/inventory/sessions`, each with its progress bar.
5. **Counting** — the camera stays live, a running counter
   ("259 / 418 · 62%"), the last five scans as a strip with their outcomes, and
   colour plus haptics distinguishing found, unexpected, duplicate and not
   found. A session summary screen behind one tap.
6. **Queue** — what is waiting, what failed and why, a retry button, and the
   conflict detail when the server disagreed.

Accessibility and field reality are the same requirement here: large tap
targets, high contrast, no information carried by colour alone, and readable in
sunlight.

## Offline model

**Offline is the default assumption, not an error state.** Every action writes to
the local queue first and returns immediately; the network is a background
detail.

```
drift tables
  cached_asset(id, asset_tag, serial, name, model, status_label, assigned_to,
               location, last_audit_date, next_audit_date, synced_at)
  session(id, name, status, expected_count, found_count, synced_at)
  queued_operation(client_operation_id TEXT PRIMARY KEY, operation, payload JSON,
                   client_timestamp, state, attempts, last_error, server_response JSON)
  pending_image(client_operation_id, local_path, uploaded)
```

Rules:

1. **The client generates the `client_operation_id`** — a UUIDv4, at the moment
   of the action, stored as the local primary key. It is the same key the server
   uses for idempotency (`API_DESIGN.md`), which is what makes a retry safe no
   matter how the first attempt failed.
2. **Optimistic local state.** A scan immediately increments the local found
   count and marks the cached asset audited. If the server later reports a
   conflict, the queue screen surfaces it; the counter does not silently rewind
   under the user's thumb.
3. **FIFO per device.** Operations sync in creation order, 50 per
   `POST /enterprise/sync/batch` call.
4. **Per-row results.** A batch returns an outcome per operation. Applied rows
   are deleted locally, conflicts and rejections are kept with their reason for
   the user to see. A failure never blocks the rows behind it.
5. **Retry with backoff** — 2s, 10s, 1m, 5m, 30m, then only on manual retry or a
   connectivity change. Cap attempts and stop rather than looping forever on a
   422 that will never succeed.
6. **Pre-cache before going offline.** Opening a session downloads its expected
   asset set (paginated, a few hundred rows) so scanning resolves locally and the
   screen can show the asset name without a network call. The audit itself still
   queues.
7. **Images queue separately**, uploaded after their operation succeeds, because
   a photo is tens of times the size of the scan and must not delay it. If the
   image upload ultimately fails, the audit still stands and the queue says the
   photo did not arrive.
8. **Device clock is recorded, server clock decides.** Send
   `client_timestamp`; expect the server's `last_audit_date` to differ.

Conflict presentation is worth getting right: for an assignment conflict, the
user needs the server's current state, who did it and when, and the choice to
discard their change or act again. Silently discarding either side is the one
behaviour that loses trust in the whole app.

## Testing

- Unit: scan resolution including the foreign-host case; queue ordering; backoff.
- Drift migration tests — the queue must survive an app update with work in it,
  which is exactly when it is most painful to lose.
- Integration against a local Snipe-IT (`LOCAL_SETUP.md`) with a token from a
  group holding only `assets.view`, `assets.audit` and `self.api`, to prove the
  permission-shaped UI is right.
- Airplane-mode run: queue 100 scans, restart the app, reconnect, confirm 100
  applied and zero duplicated.
- Replay test: send the same batch twice, confirm every row comes back
  `replayed` and nothing is written twice.

## Why a separate repository

1. **Licence.** Snipe-IT is AGPL-3.0. A Flutter client in its own repo
   communicating over HTTP is a separate program; folding it into this tree
   invites an argument about whether the app is a derivative work and whether
   source must be offered to its users. The cleaner boundary is the cheaper one.
   See the risk register in `ROADMAP.md`.
2. **Upstream merges.** This repo is rebased on upstream Snipe-IT regularly.
   Dart sources and Gradle and Xcode build outputs in the same tree make every
   merge noisier for no benefit.
3. **CI.** PHP and Flutter toolchains, caches and runner images share nothing.
4. **Release cadence.** App store review is days; a server deploy is minutes.
   Tying them to one version number helps nobody.

Suggested name `snipe-field-app`, with the API contract in
`API_DESIGN.md` as the shared reference and a generated Dart client if the
contract settles. Keep this `MOBILE_APP.md` here as the server-side record of
what the app expects, so a change to the enterprise API can be checked against
it.
