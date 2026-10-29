# Operations runbook

Audience: operators running a deployment day to day.

Running a deployment day to day. Commands run inside the app container:

```bash
alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
dc exec app php artisan <command>
```

When something is wrong, go to [INCIDENTS.md](INCIDENTS.md).

## Daily

- [ ] `curl -fsS https://<domain>/api/health/ready` answers 200.
- [ ] `storage/logs/alerts.log` (and the alert chat, if set) has nothing new
      at critical level.
- [ ] Console, **Offline queue**: empty, or draining.
- [ ] Console, **Logs**: no run of failed or rejected submissions.
- [ ] `php artisan fatoora:overdue-submissions` finds nothing (it also runs
      hourly and raises an alert).
- [ ] `php artisan backup:list` shows last night's archive.

## Weekly

- [ ] `php artisan fatoora:verify-hash-chain` (also runs Sunday 02:00) passes.
- [ ] `php artisan fatoora:check-certificate`: nothing within 30 days of
      expiry that its organization has not been told about.
- [ ] `php artisan queue:failed`: read what failed and why (below).
- [ ] Disk below 80% (`df -h`, `docker system df`).
- [ ] Apply OS security updates; restart the stack in a quiet hour.

Once a quarter, restore an archive into a scratch environment
([BACKUP-AND-RESTORE.md](../BACKUP-AND-RESTORE.md)).

## What the scheduler runs

The supervisor in the app container runs `schedule:run` every minute. Every
task is `onOneServer`, so with two replicas each runs once. Times are the
server's (UTC). `php artisan schedule:list` shows the live list.

| When | Command | What it does |
|---|---|---|
| every minute | `platform:heartbeat` | Records the scheduler's beat and sends one down the `default`, `zatca-submissions` and `webhooks` queues for a worker to record; the preflight reads them. |
| every 5 min | `fatoora:process-offline` | Files what waits in the offline queue, while auto-recovery is on (`ZATCA_AUTO_RECOVERY_ENABLED`). |
| every 10 min | `fatoora:recover-submissions` | Sends again submissions left in flight with no answer (a dead worker, a lost job). |
| every 10 min | `billing:finish-refunds` | Finishes refunds a failure left half done. |
| every 10 min | `peppol:poll-status`, `peppol:recover-sending` | UAE: asks the service provider about sent documents with no callback; settles documents stuck in sending. Only with a provider or the simulator. |
| every 15 min | `compliance:index-health --alert` | Checks the compliance tables' indexes. |
| every 15 min | `invoices:run-schedules` | Issues recurring and instalment invoices that fell due; retries failed runs. |
| hourly | `fatoora:overdue-submissions --alert` | Simplified invoices past, or near, ZATCA's 24-hour reporting deadline. |
| hourly | `license:cleanup-rate-limits` | Clears expired licence rate-limit records. |
| hourly | `license:report-usage` | Reports usage to the licence server, when licensing is on. |
| hourly | `peppol:retry-reports` | UAE: asks again for failed reports to the authority. |
| 00:00 | `license:check-expiration` | Marks expired licences. |
| 00:30 | `billing:refresh-receivables` | The platform's own unpaid invoices that fell due become overdue. |
| 01:00, 01:30, 06:00 | `backup:clean`, `backup:run`, `backup:monitor` | Prune, take and check the nightly archive ([BACKUP-AND-RESTORE.md](../BACKUP-AND-RESTORE.md)). |
| 02:30 | `website:prune-contact-details` | Clears old contact-form senders' address and browser. |
| 04:00 | `compliance:cleanup-offline-queue` | Removes completed, failed and cancelled offline-queue items older than 7 days. |
| 04:30-04:55 | `model:prune` (webhook logs, stored idempotent answers), `integrations:prune-request-log`, `imports:prune`, `finance:prune-statements` | Remove logs and uploaded files past their keeping period. |
| 05:00 | `fatoora:finish-retirements` | Retires tills whose documents are all filed. |
| 06:00 | `billing:expect-expenses` | Drafts the platform's recurring costs; reports bills not entered. |
| 07:30 | `fatoora:check-revocation` | Checks every certificate against the CA's revocation lists. |
| 08:00 | `fatoora:check-certificate --notify` | Tells organizations about certificates near expiry. |
| 08:00 | `uae:remind-provider-appointments` | UAE: reminds organizations to appoint a service provider. |
| 09:00, 09:15 | `billing:remind-renewals`, `billing:remind-receivables` | Plan-renewal and payment reminders. |
| Sunday 02:00 | `fatoora:verify-hash-chain` | Verifies every device's invoice chain. |
| Sunday 03:00 | `platform:verify-audit-chain` | Verifies that no audit log entry was changed, removed or reordered. |
| 1st of month 03:00 | `compliance:partition-maintenance --create-future --months-ahead=2` | Creates future partitions and never removes one. PostgreSQL only: on MySQL it warns and does nothing. |

## Workers and the heartbeat

Each app container runs, under supervisor: two `default` workers, three on
`zatca-submissions`, one on `webhooks`, one on `peppol`, and the scheduler.
A stopped scheduler or a dead worker logs nothing, which is why the
heartbeat exists: `platform:simulation-preflight` fails its "Queue workers"
or "Scheduler" check when a beat is older than five minutes.

```bash
dc exec app supervisorctl status            # every program RUNNING
dc exec app supervisorctl restart all       # after a config change
```

## The offline queue and Send again

When the platform judges ZATCA unreachable (the circuit breaker), a signed
document goes to the offline queue instead of failing, and
`fatoora:process-offline` files it once ZATCA answers. A platform admin can
also drain it from the console (**Offline queue**, Process queue) or retry
one item.

A submission that **failed** (not rejected) can be sent again: by an
organization administrator from the portal's Submissions page or the
invoice, or by a platform admin from the console's **Logs**. Every check runs
again first and the attempt is audited. A **rejected** invoice is never sent
again; the organization issues a corrected one.

## Paused devices

When ZATCA answers 401 for a device's credentials, filing from that device
is paused: nothing more is sent, what it issues waits in the offline queue,
its organization's administrators are told, and its certificates page shows
"Filing paused". Renewing the certificate (or onboarding again) with a new
OTP resumes it and files what waited.

## Certificates

`fatoora:check-certificate --notify` warns at 30 and 7 days
(`ZATCA_CERT_WARNING_DAYS`, `ZATCA_CERT_CRITICAL_DAYS`). Renewal is done by
the organization on its ZATCA certificates page with a new OTP; see
[KEY-ROTATION.md](../KEY-ROTATION.md). A revoked certificate stops its device
signing.

## Failed jobs

```bash
php artisan queue:failed                 # what failed, with the exception
php artisan queue:retry <id>             # one, once the cause is fixed
```

Retry webhook and mail jobs freely. Do not bulk-retry ZATCA submission jobs:
use Send again, which runs the checks first, and `fatoora:recover-submissions`
already picks up those left in flight.

## Logs

| Where | What |
|---|---|
| `dc logs -f app` | supervisor, nginx and php-fpm output |
| `storage/logs/alerts.log` | critical events, kept 90 days; also sent to `LOG_SLACK_WEBHOOK_URL` when set |
| `storage/logs/laravel-*.log` | the application log (`LOG_STACK=daily`), kept 14 days |
| `storage/logs/worker-*.log`, `scheduler.log` | each worker group and the scheduler |
| `storage/logs/zatca-*.log` and the other task logs | the scheduled commands' own output |

Every request carries an `X-Request-Id`, written on its log lines and the
records it created: ask a customer for it.

## The kill switch

Console, **Kill switches** (platform admins with the configure permission)
stops issuance, submission, clearance, reporting or signing for every
organization, each switch for at most a day and always with a reason, which
is logged. Use it for an emergency such as a signing fault. The switches live
in the shared cache and survive deploys. Resume from the same page.

## Restoring a backup

Follow [BACKUP-AND-RESTORE.md](../BACKUP-AND-RESTORE.md#restoring). Do not
reopen filing until `fatoora:verify-hash-chain` passes and each device's last
counter matches what ZATCA last accepted.
