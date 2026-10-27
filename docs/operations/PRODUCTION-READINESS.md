# Go-live checklist

Tick each before a real taxpayer files through a deployment. How to do each
is linked; this page only lists them.

## Hosting

- [ ] Server and backups in the Kingdom for Saudi taxpayers
      ([DEPLOYMENT.md](DEPLOYMENT.md#where-to-host-it)).
- [ ] HTTPS on the deployment's own name; both firewalls open on 80 and 443
      only; SSH by key.
- [ ] Clock synchronised (`timedatectl`); the preflight's clock check passes.
- [ ] Disk has room to grow; alert at 80% used.

## Configuration

- [ ] The stack starts with `APP_ENV=production`: every boot check passes
      ([DEPLOYMENT.md](DEPLOYMENT.md#what-production-refuses-to-start-without)).
- [ ] `ZATCA_ENVIRONMENT` is `production` (or `simulation` on a pilot-only
      server), and each organization's own environment is the one intended.
- [ ] Licensing decided: a partner key and secret, or
      `PLATFORM_LICENSE_ENABLED=false` ([LICENSING.md](LICENSING.md)).
- [ ] `ZATCA_CA_CHAIN_PATH` set, and `ZATCA_CRL_URLS` if a certificate names
      no revocation point; `/api/health/ready` answers 200.
- [ ] Mail delivers: the preflight with `--send-test-mail=<you>`, and the
      message arrives.
- [ ] `LOG_SLACK_WEBHOOK_URL` or another alert route set, so critical events
      reach a person and not only `storage/logs/alerts.log`.
- [ ] `/api/metrics` scraped (`METRICS_TOKEN` or `METRICS_ALLOWED_IPS`).

## Secrets and backups

- [ ] `APP_KEY`, `ZATCA_CREDENTIAL_KEY`, `BACKUP_ARCHIVE_PASSWORD`,
      `JWT_SECRET`, `API_KEY_PEPPER` stored outside the server
      ([KEY-ROTATION.md](KEY-ROTATION.md)).
- [ ] The nightly backup runs, and one archive has been restored into a
      scratch environment by hand ([BACKUP-AND-RESTORE.md](BACKUP-AND-RESTORE.md)).
- [ ] The bucket's credentials cannot delete, or the trade-off is chosen.

## The platform

- [ ] A platform admin enrolled in two-factor; a second one, so a lost phone
      is not a lock-out.
- [ ] Queue workers and scheduler alive: `platform:simulation-preflight`
      passes its worker and scheduler checks.
- [ ] `platform:rehearse` passes every step.
- [ ] `fatoora:verify-hash-chain` passes; `fatoora:overdue-submissions` finds
      nothing.
- [ ] Someone knows the [operations runbook](runbooks/OPERATIONS.md) and the
      [incident runbook](runbooks/INCIDENTS.md), including who may throw the
      kill switch.

## ZATCA

- [ ] The first organization went through simulation with the
      [pilot runbook](runbooks/PILOT-SIMULATION.md): six compliance documents
      passed, simplified invoices reported, standard invoices cleared.
- [ ] Its QR codes scanned with ZATCA's own app.
- [ ] Production onboarding done with an OTP from the Fatoora portal, and the
      first production invoice watched through to Cleared or Reported.

## Before a second taxpayer

- [ ] An external penetration test of the API.
- [ ] The credential store on a disk every replica shares (it is, in the
      compose stack's `platform-storage` volume; check it if the layout
      changes).
