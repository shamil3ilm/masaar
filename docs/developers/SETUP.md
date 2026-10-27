# Local setup

Audience: an engineer running the platform on their own machine for the first
time (Windows, macOS or Linux).

## 1. Install

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.4 (`composer.json` requires `^8.4`) | extensions: `openssl`, `mbstring`, `bcmath`, `dom`, `xml`, `curl`, `zip`, `fileinfo`, `tokenizer`, `gd`, `intl`, `gmp`, `pdo_sqlite`, `sqlite3`, `pdo_mysql` |
| Composer | 2 | |
| Node | 24 or later (`package.json` engines) | builds the Vite assets |
| MySQL | 8.4 (as CI) | the test suite runs on SQLite by default; MySQL is for the app and the MySQL suite |
| OpenSSL | on the `PATH`, or set `OPENSSL_BIN` | the onboarding commands run it |
| Java | 17 | only for the conformance suites ([TESTING-AND-GATES.md](TESTING-AND-GATES.md)) |

On Windows use any PHP 8.4 build with those extensions enabled in `php.ini`;
on macOS and Linux the package manager's PHP 8.4 works. The extensions match
the CI jobs in `.github/workflows/ci.yml`; the container image
(`Dockerfile`) adds `redis`, `sockets` and `pcntl` for production.

## 2. Configure

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

`.env.example` is safe by default, which means production. For local work
change these in `.env` (never commit `.env`):

| Key | Local value | Why |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | the file ships `production` and `false` |
| `APP_URL` | `http://127.0.0.1:8000` | links in mail are built from it |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | your local MySQL database (the default name is `platform`) | create the database first |
| `ZATCA_ENVIRONMENT` | `sandbox` | the file ships `production`; never point a dev machine at it |
| `LICENSING_FILING_REQUIRES_LICENCE` | `false`, or approve organizations with a plan | otherwise an organization cannot connect to ZATCA |
| `PEPPOL_AE_ENABLED` and `PEPPOL_GATEWAY` | `true` and `simulator` | to work on the UAE flow with no service provider (`docs/ae/SIMULATOR.md`); the simulator also needs `PEPPOL_ARTEFACTS_PATH` and `PEPPOL_XSLT_JAR`, or it sends nothing |
| `PAYMENT_GATEWAY` | `simulator` (optional) | card payments played inside the app |
| `API_KEY_PEPPER` | output of `openssl rand -base64 32` (optional locally) | changing it later invalidates issued API keys |

`ZATCA_CREDENTIAL_KEY` may stay empty locally: it then falls back to
`APP_KEY` (development only; production refuses to serve without it).
`MAIL_MAILER=log` writes every mail, including verification links, to
`storage/logs`. Every other key is described where it sits in `.env.example`.

## 3. Database and dev users

```bash
php artisan migrate
php artisan db:seed --class=DevUsersSeeder
```

`DevUsersSeeder` runs only in `local` and `testing`. It creates
`admin@example.test` (platform admin) and `member@example.test` (portal
member), both with the password in `DevUsersSeeder::PASSWORD`, and attaches
both to the oldest organization. With no organization yet, the member has no
portal: create one first (below) and run the seeder again. `php artisan
db:seed` alone only adds a `test@example.com` user.

A platform admin signs in with a second factor. The first time, run
`php artisan platform:enrolment-link admin@example.test`, open the link it
prints in the same browser, and enrol an authenticator app.

To make an organization, either sign up at `/register` and approve it in the
admin console (Organizations), or run the sandbox rehearsal below with
`--keep`.

## 4. Run

Four processes, each in its own terminal:

```bash
php artisan serve                 # http://127.0.0.1:8000
npm run dev                       # Vite with hot reload (or npm run build once)
php artisan queue:work --queue=zatca-submissions,webhooks,peppol,default
php artisan schedule:work         # the tasks in routes/console.php
```

The queue names come from `ZATCA_QUEUE_NAME`, `ZATCA_QUEUE_WEBHOOKS` and
`PEPPOL_QUEUE`; a worker on `default` alone leaves submissions and webhooks
waiting. `composer dev` starts the server, Vite and a worker together, but its
worker listens on `default` only.

`public/build` is not committed: run `npm run build` (or keep `npm run dev`
running) before opening a page, or Blade throws a missing-manifest error.

Sign in at `/login`: the admin console is under `/admin`, the portal under
`/portal`.

## 5. ZATCA sandbox

Local and test runs file with ZATCA's developer portal ("sandbox"). It needs
no registration and issues one shared certificate for one taxpayer, VAT
number `399999999900003`
(`FatooraEnvironment::SANDBOX_VAT_NUMBER`), with the fixed OTP `123345`. An
organization in the sandbox with any other VAT number has every invoice
rejected; use simulation for a real VAT number.

```bash
# The CLI's own round trip: certificate request, onboarding, six documents
php artisan fatoora:generate-csr --standard --simplified --target=sandbox
php artisan fatoora:onboard --step=full --otp=123345 --target=sandbox

# The platform's own issuing path with the rehearsal organization
# (needs the queue worker running)
php artisan platform:rehearse --admin=admin@example.test --keep
```

`platform:rehearse` creates and approves (with a plan) the rehearsal
organization, which carries the sandbox VAT number, then onboards it and
issues and files a set of documents through the normal path, printing PASS or
FAIL per step. Without `--keep` it suspends the organization afterwards;
with it, the organization stays active to explore in the portal. Each organization has its own ZATCA
environment (sandbox, simulation or production), set by a platform admin and
defaulting to `ZATCA_ENVIRONMENT`.

## Containers instead

`docker compose up -d --build` starts the app container (nginx, PHP, the
queue workers and the scheduler, on `127.0.0.1:8000`) and MySQL, from
`docker-compose.yml` and the development `docker-compose.override.yml`. The compose files expect the secrets a
deployment sets (database passwords, `ZATCA_CREDENTIAL_KEY`,
`API_KEY_PEPPER`); the `stack` job in `ci.yml` shows a minimal `.env` for it.

Next: [CODEBASE-TOUR.md](CODEBASE-TOUR.md).
