# QR codes and barcodes

Sources: `app/Http/Controllers/QrCodeController.php`, `routes/web.php` (around
line 994), `app/View/Label.php`, `app/Helpers/Helper.php::barcodeDimensions`,
`app/Models/Setting.php`, `app/Enums/FileStorage.php`, `app/Models/Labels/*`,
`composer.json`.

## What a Snipe-IT QR code contains

**A URL, not an asset tag.** `QrCodeController::show()` and `Label.php` both
build the payload with `route('hardware.show', $asset->id)`, so a scanned asset
QR reads as:

```
https://<APP_URL>/hardware/1234
```

Three things follow, and all three matter for the mobile app:

1. The code embeds the **database id**, not the asset tag. A scanner that wants
   `GET /api/v1/hardware/bytag/{tag}` must parse the id out of the URL and call
   `GET /api/v1/hardware/{id}` instead, or handle both shapes.
2. The code embeds **`APP_URL`**. Change the hostname and every previously
   printed label points at the old host. Treat `APP_URL` as permanent once labels
   are printed.
3. Scanning with a plain phone camera opens the web UI and asks for a login. That
   is the designed behaviour for staff, and it is why our app must accept both a
   URL and a bare tag — people will print both kinds of label, and third-party
   stickers carry bare tags.

## The libraries

| Library | Used for |
| --- | --- |
| `tecnickcom/tc-lib-barcode` | the actual generation in `QrCodeController` and `Label.php` (`Barcode::getBarcodeObj`) |
| `bacon/bacon-qr-code` | present as a dependency, used by the label and 2FA paths |

Supported 2D symbologies come from tc-lib-barcode's type strings. The codebase
handles `QRCODE`, `PDF417` and the 1D `C128` explicitly in
`Helper::barcodeDimensions`, and `none` disables 2D codes entirely.

## Settings that control it

On the settings record (`app/Models/Setting.php`):

| Setting | Effect |
| --- | --- |
| `label2_2d_type` | `QRCODE`, `PDF417`, `none`. `none` makes `QrCodeController::show()` return `false` and no QR renders anywhere |
| `qr_code` | legacy flag; `Setting` treats 2D as enabled when `qr_code == '1'` **or** `label2_2d_type !== 'none'` |
| `label2_template`, `label2_asset_logo`, and the other `label2_*` fields | label layout, selected in `app/Models/Labels/` |
| `alt_barcode`, `alt_barcode_enabled` | the 1D barcode alongside the 2D one |

`Helper::barcodeDimensions` returns negative height and width values — in
tc-lib-barcode those mean "module size" rather than pixels, so a QR comes out
3 modules per dot and a C128 comes out 10 wide by 1 high. Do not "fix" them to
positive numbers.

## The image endpoint

```
GET /{object_type}/{id}/qr_code
```

A **web** route (`routes/web.php`), not an API route: session `auth` middleware,
no bearer token. `object_type` is constrained to
`accessories|assets|hardware|licenses|locations|models|companies|components|consumables|users`,
and `QrCodeController::$map_show_route` maps each to the show route whose URL
gets encoded.

Behaviour worth knowing:

- **Authorisation per object.** `Gate::allows('view', $object)` runs on the
  resolved object, and a failure returns 404, not 403 — deliberate, so the
  endpoint does not confirm that an id exists.
- **Soft-deleted objects still resolve** (`withTrashed()`), so a QR on a retired
  asset keeps working.
- **PNGs are cached on the public disk** under the `barcodes` path as
  `qr-<object_type>-<id>.png` (`FileStorage::Barcodes`). The file is generated
  once and served from cache afterwards. Change `APP_URL` and **you must clear
  that directory**, or old URLs keep being served.
- The response is **buffered**, not streamed, with a comment explaining why: a
  `StreamedResponse` truncated bodies on S3 and a 302 to storage left Safari with
  an HTML body on an `img` fetch. Keep it buffered.

Because this route is session-authenticated, **the mobile app cannot fetch QR
images with its bearer token.** It does not need to — it scans codes rather than
rendering them. If in-app label printing is ever wanted, that needs a new
token-authenticated endpoint, which `API_DESIGN.md` deliberately leaves out of
phase one.

## Label printing

`POST /api/v1/hardware/labels` (API) and the labels controller (web) render PDF
sheets. Templates live in `app/Models/Labels/` with `Sheet`, `RectangleSheet`,
`DefaultLabel`, `CustomUserLabel` and `CustomLabelFonts`;
`GET /api/v1/labels` and `/labels/{name}` list them. `custom_labels` became a
table in migration 2026_03_23, so label layouts are now data rather than code.

## What the mobile scanner should do

Decision table for a scan result, in this order:

| Scanned text | Action |
| --- | --- |
| `https://<our host>/hardware/{id}` | `GET /api/v1/hardware/{id}` |
| `https://<other host>/hardware/{id}` | reject with "this label belongs to another system" — do not silently query our instance with a foreign id |
| anything matching our asset tag pattern | `GET /api/v1/hardware/bytag/{tag}` |
| a serial-looking string with no tag match | fall back to `GET /api/v1/hardware/byserial/{serial}` |
| otherwise | show the raw text and offer manual search |

Checking the host is the part most likely to be skipped and the part most likely
to cause a wrong asset to be audited. An id from another company's Snipe-IT is a
perfectly valid id in ours.

Implementation notes for Flutter, expanded in `MOBILE_APP.md`:

- `mobile_scanner` handles QR and Code 128 in one controller.
- Debounce duplicate reads: the camera will emit the same code many times a
  second.
- Keep the torch toggle reachable — warehouse and server-room lighting is poor,
  and this is the single most used control in a counting session.

## Operational warnings

1. **`APP_URL` is permanent once labels are printed.** Decide the hostname before
   the first print run.
2. **Clear the barcode cache** after changing `APP_URL` or `label2_2d_type`;
   nothing invalidates it automatically.
3. **`label2_2d_type = none` silently removes QR codes everywhere**, including
   from the API label output. If codes vanish, check this setting first.
4. QR codes carry no authorisation of their own. Anyone holding a printed label
   has a URL; the login is the control. Do not put anything confidential in a
   custom label field.
