# Local setup — Snipe-IT v8.8.0 (Laravel 12, PHP 8.2+, MariaDB)

**Status: NOT VERIFIED ON THIS MACHINE.** Docker and PHP are not installed on the
workstation this branch was prepared on, so every command below was derived from
the repository's own compose files, `Dockerfile.alpine`, `.env.example` and
`app/Console/Commands/CreateAdmin.php` — not from a run. Expect to correct small
details the first time it is executed, and update this file with what actually
happened.

Reference files in the repo: `dev.docker-compose.yml`, `docker-compose.yml`,
`.env.dev.docker`, `.env.example`.

## 0. Prerequisites

| Tool | Version | Why |
| --- | --- | --- |
| Docker Desktop (or Engine + Compose v2) | current | runs app, MariaDB, Redis, Mailhog |
| git | any | this clone |

Nothing else is needed locally: PHP, Composer, MySQL and Node all run inside the
image built from `Dockerfile.alpine`.

## 1. Which compose file

Two exist and they are not interchangeable:

- `dev.docker-compose.yml` — **use this.** Builds the image from this working
  tree (`Dockerfile.alpine`), adds MariaDB 11.4.7, Redis 7.4.3 and Mailhog, and
  publishes the app on `localhost:8000` and Mailhog on `localhost:8025`. Reads
  `.env.dev.docker`.
- `docker-compose.yml` — production. Pulls the published `snipe/snipe-it` image
  and ignores local code. Wrong for development.

## 2. Environment file

`.env.dev.docker` is tracked in the repo and already points `DB_HOST` at the
`mariadb` service. Values that must be right for a local run:

```dotenv
APP_ENV=develop
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_KEY=                      # filled in step 4
DB_CONNECTION=mysql
DB_HOST=mariadb               # the compose service name, not 127.0.0.1
DB_PORT=3306
DB_DATABASE=snipeit
DB_USERNAME=snipeit
DB_PASSWORD=<choose one>
MYSQL_ROOT_PASSWORD=<choose one>
MAIL_MAILER=smtp
MAIL_HOST=mailhog
MAIL_PORT=1025
```

`APP_URL` matters more than it looks: QR codes encode `route('hardware.show')`,
so a wrong `APP_URL` bakes an unreachable host into every generated label
(see `QR_CODES.md`).

## 3. Build and start

```bash
docker compose -f dev.docker-compose.yml up -d --build
```

First build compiles PHP extensions and runs `composer install`; several minutes
is normal. Watch it come up:

```bash
docker compose -f dev.docker-compose.yml logs -f snipeit
```

MariaDB has a healthcheck and the app waits on it, so a failure in the first
seconds is usually the app container, not the database.

## 4. Application key

```bash
docker compose -f dev.docker-compose.yml exec snipeit php artisan key:generate --show
```

Paste the printed value into `APP_KEY` in `.env.dev.docker`, then restart the app
container. Do **not** regenerate the key after data exists: encrypted custom
field values and two-factor secrets become unreadable.

```bash
docker compose -f dev.docker-compose.yml restart snipeit
```

## 5. Migrate

```bash
docker compose -f dev.docker-compose.yml exec snipeit php artisan migrate --force
```

495 migrations as of v8.8.0; it is slow on first run. Check it finished:

```bash
docker compose -f dev.docker-compose.yml exec snipeit php artisan migrate:status | tail -5
```

## 6. Demo data

`database/seeders/DatabaseSeeder.php` seeds companies, categories, locations,
departments, users, manufacturers, suppliers, custom fields, models, status
labels, assets, licenses, accessories, components, consumables, action logs,
maintenances and imports.

```bash
docker compose -f dev.docker-compose.yml exec snipeit php artisan db:seed --force
```

**Never run this against anything but a local database** — several seeders
truncate before inserting.

## 7. Admin user

Snipe-IT's own command, not a seeder:

```bash
docker compose -f dev.docker-compose.yml exec snipeit php artisan snipeit:create-admin --first_name=Local --last_name=Admin --email=admin@example.test --username=admin --password='<choose a local-only password>'
```

Signature confirmed in `app/Console/Commands/CreateAdmin.php`. The account is
created with the `superuser` permission.

## 8. Confirm the web UI

1. Open `http://localhost:8000`.
2. Sign in as the admin created above.
3. The dashboard should show seeded asset counts; **Assets -> List All** should
   show seeded rows.
4. Mailhog on `http://localhost:8025` catches outgoing mail — check it after a
   checkout that requires acceptance.

Record here what the first real run showed: ____

## 9. Confirm the API

The v1 API authenticates with Laravel Passport bearer tokens
(`config/auth.php`: the `api` guard is `driver => passport`).

### Create a personal access token

Easiest in the UI: profile menu -> **Manage API Keys** -> **Create New Token**.
Copy it immediately; it is shown once.

There is also an API route for it once you already hold a token:
`POST /api/v1/account/personal-access-tokens`.

### Call the API

Two gotchas before the first call, both specific to this codebase:

- **`Accept: application/json` is required.** Without it Laravel answers HTML.
- **Send a real `User-Agent`.** `app/Http/Middleware/EnforceApiUserAgent.php`
  can reject blank User-Agents and prefix-matched clients (`curl/`,
  `PostmanRuntime/`, `python-requests/`) when the admin enables
  `block_api_user_agents`. If a call 4xx's with a user-agent message, either set
  a custom UA or turn the setting off for local work.

```bash
TOKEN='<paste token>'
curl -s http://localhost:8000/api/v1/hardware -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -A 'snipeit-local-dev/1.0' | head -40
```

Expected: a JSON object with `total` and a `rows` array.

Two more worth confirming, because the mobile app depends on both:

```bash
# the authenticated user
curl -s http://localhost:8000/api/v1/users/me -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -A 'snipeit-local-dev/1.0'

# lookup by asset tag — the scanner's main call
curl -s http://localhost:8000/api/v1/hardware/bytag/<a-seeded-tag> -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -A 'snipeit-local-dev/1.0'
```

Record the first real responses here: ____

## 10. Routine commands

```bash
# shell in the app container
docker compose -f dev.docker-compose.yml exec snipeit sh

# clear caches after changing config or routes
docker compose -f dev.docker-compose.yml exec snipeit php artisan optimize:clear

# tests
docker compose -f dev.docker-compose.yml exec snipeit php artisan test

# stop, keeping the database volume
docker compose -f dev.docker-compose.yml down

# stop and destroy the database
docker compose -f dev.docker-compose.yml down -v
```

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `No application encryption key` | `APP_KEY` empty | step 4 |
| `SQLSTATE[HY000] [2002]` | app started before MariaDB, or `DB_HOST=127.0.0.1` | `DB_HOST=mariadb`, restart app |
| API returns HTML | missing `Accept: application/json` | add the header |
| API rejects the client | `EnforceApiUserAgent` | send a custom `User-Agent` |
| QR codes point at the wrong host | `APP_URL` | fix `APP_URL`, clear the cached barcode PNGs |
| 500 after editing config | cached config | `php artisan optimize:clear` |
