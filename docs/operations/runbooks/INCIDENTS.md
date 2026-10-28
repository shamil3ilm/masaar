# Incident runbook

Audience: the operator on call when something breaks.

Symptom, checks, fix. Commands run in the app container
(`dc exec app php artisan ...`, with `dc` as in [OPERATIONS.md](OPERATIONS.md)).
In every case: note the time, keep the logs, and tell affected organizations
if a reporting deadline is at risk. Simplified invoices must be reported
within 24 hours of issue.

## ZATCA unreachable

**Symptoms.** Documents land in the offline queue; the metrics show
`zatca_circuit_breaker_open`; submissions fail with a connection error or a
5xx. Readiness stays green on purpose.

**Checks.**
- `php artisan fatoora:process-offline --dry-run`: tests connectivity and
  shows what waits.
- From the host: `curl -sS -o /dev/null -w '%{http_code}\n' https://gw-fatoora.zatca.gov.sa/`.
  No answer at all is our network or DNS; any status is ZATCA answering.
- ZATCA's own status channels for a declared outage.

**Fix.** Usually nothing: documents are signed and queued, and the drain
files them every five minutes once ZATCA answers. Fix our side if the host
cannot reach the gateway (DNS, outbound firewall). Watch
`fatoora:overdue-submissions` as the 24-hour mark approaches; tell the
organizations concerned if it will be missed.

## ZATCA answers 401 or 403

**Symptoms.** One device's submissions fail as unauthorized; its
certificates page shows "Filing paused"; its administrators were told.

**Checks.** `php artisan fatoora:check-certificate --organization=<id>`:
expired, revoked, or issued in another ZATCA environment than the
organization now uses. Many devices at once points at our side (a wrong
`ZATCA_CREDENTIAL_KEY` after a restore or rotation) or at ZATCA.

**Fix.** The organization renews the certificate with a new OTP from the
Fatoora portal of its environment; filing resumes and what waited is filed.
If the credential key is wrong, restore the right one (and its previous
keys), then `platform:rotate-credential-key`.

## A spike of rejected invoices

**Symptoms.** Console **Logs** or the portal's Submissions show many
Rejected, often one organization or one rule.

**Checks.** Open a few: each names the rule (BR-KSA-..., BR-...). The same
rule across organizations after a release points at a regression; one
organization points at its data (seller identification, buyer address,
VAT number).

**Fix.** A rejected invoice is never sent again: fix the cause, then the
organization issues a corrected invoice. For a regression, stop issuance
with the kill switch if the fault would keep producing rejections, roll back
([DEPLOYMENT.md](../DEPLOYMENT.md#update)), and report the rule.

## Queue workers down

**Symptoms.** Simplified invoices stay Issued; webhooks stop; the preflight's
"Queue workers" check fails; `fatoora:overdue-submissions` alerts.

**Checks.** `dc exec app supervisorctl status`; `dc logs app`;
`storage/logs/worker-*.log`; `php artisan queue:failed`.

**Fix.** `dc exec app supervisorctl restart all`, or `dc restart app`. Then
`fatoora:recover-submissions` (every ten minutes on its own) resends what
was left in flight. If the scheduler also stopped (no `platform:heartbeat`),
restart it the same way.

## Database down

**Symptoms.** `/api/health/ready` answers 503 naming the database; pages
error; Traefik stops routing to the replicas.

**Checks.** `dc ps db`; `dc logs db`; disk space (below); for a managed
database, its console.

**Fix.** `dc restart db`, then `dc restart app`. If the data is damaged,
restore ([BACKUP-AND-RESTORE.md](../BACKUP-AND-RESTORE.md)) and do not
reopen filing until `fatoora:verify-hash-chain` passes.

## Migration refused: the immutability triggers

**Symptoms.** The app container never becomes ready; its log shows a
migration (1814 or 1817) refused with "The database refused to create the
immutability triggers", around MySQL error 1419 (SUPER privilege and binary
logging) or 1142 (TRIGGER command denied).

**Checks.** `dc logs app`; on the server, `SHOW VARIABLES LIKE
'log_bin_trust_function_creators'`; `SHOW GRANTS` for the migrating account.

**Fix.** Set `log_bin_trust_function_creators=1` on the MySQL server and give
the migrating account `TRIGGER`, or set `DB_MIGRATION_USERNAME` and
`DB_MIGRATION_PASSWORD` to an account that has both
([DEPLOYMENT.md](../DEPLOYMENT.md#the-database-account-that-migrates)), then
restart the app. Nothing after the refused migration has run.

## Disk full

**Symptoms.** Writes fail, MySQL stops, logs stop growing.

**Checks.** `df -h`; `docker system df`; `du -sh` on the volumes
(`platform-mysql`, `platform-logs`, `platform-storage`).

**Fix.** `docker image prune` and `docker builder prune` for old builds;
remove old `storage/logs` files beyond the keeping periods. Never delete
invoices, submissions, the credential store or the audit log. Then grow the
volume.

## Clock drift

**Symptoms.** ZATCA refuses documents for their time; the preflight's
"Server clock" check fails (more than five seconds from the gateway). The
platform itself refuses a sale time more than 30 seconds ahead.

**Checks.** `timedatectl`; `chronyc tracking` where chrony runs.

**Fix.** Turn on time synchronisation (`sudo timedatectl set-ntp true`),
wait for it to step the clock, then send again what failed. The containers
take the host's clock.

## Certificate expired

**Symptoms.** A device's filing fails or pauses; `fatoora:check-certificate`
lists it as expired; the organization was warned at 30 and 7 days.

**Fix.** The organization renews it on its ZATCA certificates page with a
new OTP. Renewal is refused while documents the old certificate signed are
unfiled, unless a reason is given; let them file first if ZATCA still
accepts them. What waited is filed once renewed. See
[KEY-ROTATION.md](../KEY-ROTATION.md#zatca-device-certificates).

## After any incident

- `php artisan fatoora:verify-hash-chain` and
  `php artisan fatoora:overdue-submissions`.
- Offline queue empty; nothing left Failed that should be sent again.
- Write down what happened, when, and what was missed.
