# Staging

Two places to try a change before it reaches a taxpayer.

## The staging server

A full deployment, built exactly as [DEPLOYMENT.md](DEPLOYMENT.md) describes
and hosted in the Kingdom, on its own subdomain. It is where the rehearsal
runs and where a pilot meets ZATCA's simulation environment.

What differs from production is only the configuration:

| Setting | Staging server |
|---|---|
| `APP_ENV` | `production`: the boot checks, the preflight and the static error pages are the ones a taxpayer will meet. |
| `ZATCA_ENVIRONMENT` | `simulation`. Production refuses `sandbox` as the default; the rehearsal organization is set to the sandbox on its own. |
| `PLATFORM_LICENSE_ENABLED` | `false` unless the server is a partner's licensed installation. |
| Backups | Optional. Without the `BACKUP_*` values the nightly run is refused and raises an alert; set them if pilots' data matters. |

Then:

```bash
alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
dc exec app php artisan platform:rehearse --admin=<admin email>
```

and follow [the pilot runbook](runbooks/PILOT-SIMULATION.md) for the
browser checklist, the preflight and the switch.

## Local staging against MySQL

The test suite runs on SQLite, which accepts things MySQL refuses: a foreign
key of the wrong type, a `WHERE` on a column that does not exist, locking that
behaves differently. A second local environment on real MySQL catches that
class of fault. It uses its own database (`staging` below) and its own keys,
in an uncommitted `.env.staging` with `APP_ENV=staging` and `APP_DEBUG=false`.
`staging` rather than `production` so it starts without the production
secrets.

```bash
php artisan key:generate --env=staging --force
php artisan jwt:secret --env=staging --force
php artisan migrate --env=staging --force
php artisan platform:create-admin ops@example.com --env=staging   # prints the enrolment link
npm run build                                                     # the console needs public/build
php artisan serve --env=staging --host=127.0.0.1 --port=8001
```

Check, at `http://127.0.0.1:8001`:

- signing in works (a missing `public/build` breaks every form);
- the admin dashboard's figures match the database;
- an invoice issued for an organization with no certificate gets a
  first-phase QR code (five tags);
- `php artisan fatoora:verify-hash-chain --env=staging` passes and
  `php artisan fatoora:overdue-submissions --env=staging` finds nothing.

Onboarding against the sandbox needs its published OTP:
`php artisan fatoora:onboard --step=full --otp=123345 --target=sandbox --env=staging`.

Local staging shares the development MySQL server, so it says nothing about
replication, failover or restoring a backup.
