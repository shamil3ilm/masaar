# Staging

A second environment on the same machine, isolated from development by its own
database and its own keys. It exists so a change can be exercised the way a
deployment runs it — real MySQL, real HTTP, `APP_DEBUG=false` — before it
reaches anything that matters.

## Why it is not just "run it locally"

The test suite runs on in-memory SQLite, which is fast and permissive. It does
not enforce foreign key types, it accepts a `WHERE` on a column that does not
exist, and it is a console process, where `TenantScope` stands down. Each can
hide a real defect: a foreign key declared `bigint` against a UUID key, which
MySQL refuses outright; a counter allocation that behaves differently under a
real database's locking; a read of a column no table has.

Staging is the cheapest place to catch that class of thing.

## What it is

| | Development | Staging |
|---|---|---|
| Database | `masaar` | `masaar_staging` |
| Env file | `.env` | `.env.staging` |
| `APP_ENV` | `local` | `staging` |
| `APP_DEBUG` | typically true | **false** |
| Port | 8000 | 8001 |

`APP_ENV=staging` rather than `production` is deliberate. Two boot guards in
`AppServiceProvider` refuse to start under `production` — debug being on, and
the two submission rate limits disagreeing — and staging should not be the
place those are first discovered. `APP_DEBUG` is still false, because a staging
environment that leaks stack traces teaches the wrong habits about what a
deployment looks like.

Neither `.env` nor `.env.staging` is committed: `.gitignore` excludes `.env.*`
and admits only `.env.example`.

## Setting it up

The database and `.env.staging` already exist. From a clean checkout, or to
rebuild staging from scratch:

```bash
# 1. The database
php artisan tinker --execute="DB::statement('CREATE DATABASE IF NOT EXISTS masaar_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"

# 2. Its own keys - never the development ones
php artisan key:generate --env=staging --force
php artisan jwt:secret --env=staging --force

# 3. Schema
php artisan migrate --env=staging --force

# 4. Somebody who can sign in. Prompts for a password; nothing is seeded,
#    and DatabaseSeeder would not help - it creates a user with no
#    platform-admin flag. Prints a one-time two-factor enrolment link: open
#    it before signing in.
php artisan masaar:create-admin ops@masaar.test --env=staging

# 5. Assets, because the console renders @vite and public/build is gitignored
npm run build

# 6. Serve it
php artisan serve --env=staging --host=127.0.0.1 --port=8001
```

Then sign in at `http://127.0.0.1:8001/login`.

- `/admin` — the internal console: dashboard, organizations, licences, alerts,
  webhooks, offline queue, logs.
- `/portal` — the taxpayer portal: invoices, submissions, certificates.

## What to check once it is up

These are the things that pass under SQLite and fail under MySQL, or that only
appear over real HTTP:

- **Sign in.** The login page must render a CSRF token. If `public/build` is
  missing, the page throws and every form on the site stops working — the
  symptom is five login checks failing while everything around them passes.
- **The dashboard shows non-zero figures** that match the database. Zeros on a
  populated database mean the page is not reading the data it reports.
- **Issue an invoice from the portal** for an organization with no certificate.
  It should get a Phase 1 QR — five TLV tags, no cryptographic ones.
- **`php artisan fatoora:verify-hash-chain --env=staging`** should pass. It
  reports only issued invoices; a draft is not a chain member.
- **`php artisan fatoora:overdue-submissions --env=staging`** should find
  nothing on a fresh database.

## Pointing it at ZATCA

`.env.staging` ships `ZATCA_ENVIRONMENT=sandbox`. The sandbox needs no
registration and issues a shared certificate, so onboarding works immediately:

```bash
php artisan fatoora:onboard --step=full --otp=123345 --target=sandbox --env=staging
```

Note what that certificate is: the sandbox hands every caller the same one, and
nobody outside the authority holds its private key, so signatures made with it
cannot be verified by anyone. See `docs/sa/STATUS.md` section 3.

## What staging is not

It is not a rehearsal for production in the ZATCA sense. That is the
*simulation* environment, which needs a VAT registration to obtain an OTP —
see `docs/sa/STATUS.md` section 5. Staging exercises this application; it does
not exercise the authority's production path.

It also shares a MySQL server with development. That is fine for catching
schema and scoping faults, and wrong for anything about replication, failover
or backup restore, which need real infrastructure.
