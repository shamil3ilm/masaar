# Key rotation

Audience: operators responsible for secrets and signing keys.

Every secret the platform holds, how to replace it, and what replacing it
breaks. Keep the current and previous values of each in a secret store
outside the server: a restore needs them
([BACKUP-AND-RESTORE.md](BACKUP-AND-RESTORE.md)).

| Secret | Where | Rotating it |
|---|---|---|
| ZATCA device certificates (CSIDs) | Credential store | Renewed with a new OTP; see below. |
| `ZATCA_CREDENTIAL_KEY` | `.env` | With `ZATCA_CREDENTIAL_PREVIOUS_KEYS` and `platform:rotate-credential-key`; no outage. |
| `AUDIT_CHAIN_KEY` | `.env`, kept apart from the database and from `APP_KEY` | Put the old key in `AUDIT_CHAIN_PREVIOUS_KEYS` and the new one in `AUDIT_CHAIN_KEY`: new entries are sealed with the new key and `platform:verify-audit-chain` checks old seals with the old one. Keep old keys for as long as the audit log is kept. |
| `APP_KEY` | `.env` | With `APP_PREVIOUS_KEYS`; sessions and encrypted columns keep working. |
| `JWT_SECRET` | `.env` | Every API token stops working; clients sign in again. |
| `API_KEY_PEPPER` | `.env` | Do not rotate: every API key already issued stops working. |
| Partner API secret (licence) | Admin console | Licences, Rotate: the old secret stops at once; the new one is shown once. |
| Webhook signing secret | Portal or API | Integrations, the webhook, Rotate (or `POST /api/webhooks/{id}/rotate-secret`); shown once. |
| `PLATFORM_LICENSE_SECRET` | `.env`, both sides | Every licence key signed with the old secret stops verifying; reissue them. |
| `BACKUP_ARCHIVE_PASSWORD` | `.env` | New archives use the new one; keep the old one for older archives. |
| `DB_PASSWORD`, `REDIS_PASSWORD`, mail and storage credentials | `.env` | Change at the provider and in `.env`, then restart the stack. |

## ZATCA device certificates

- `fatoora:check-certificate --notify` runs daily at 08:00 and tells each
  organization's administrators when a certificate is within
  `ZATCA_CERT_WARNING_DAYS` (30) and again within `ZATCA_CERT_CRITICAL_DAYS`
  (7) of expiry. Run it by hand with `--organization=<id>` for one.
- `fatoora:check-revocation` runs daily at 07:30 against the CA's revocation
  lists. A revoked certificate is recorded, its organization and the platform
  are told, and its device stops signing.
- **Renewal** is done by an organization administrator on the ZATCA
  certificates page, with a new OTP from the Fatoora portal of the
  organization's environment. It is refused while documents the current
  certificate signed are still unfiled, unless a reason is given (audited).
- If ZATCA answers 401 for a device's credentials, filing from that device is
  paused: what it issues waits in the offline queue and its administrators
  are told. Renewing or onboarding it again resumes it and files what waited.

## The credential key

Each device's private key and CSID are encrypted under `ZATCA_CREDENTIAL_KEY`
(production refuses to start without it). To replace it, or to move an older
deployment off `APP_KEY`:

1. Put the new key in `ZATCA_CREDENTIAL_KEY` and the old one (or the
   `APP_KEY` it used) in `ZATCA_CREDENTIAL_PREVIOUS_KEYS`; restart.
2. `php artisan platform:rotate-credential-key --dry-run`, then without
   `--dry-run`. It re-encrypts every file under the new key and lists any it
   cannot read.
3. When it reports no unreadable files, empty
   `ZATCA_CREDENTIAL_PREVIOUS_KEYS` and restart.

Stopping after step 1 leaves the platform working but still dependent on the
old key, which is not a finished rotation.

## APP_KEY

Laravel decrypts with `APP_PREVIOUS_KEYS` when the current key fails, so:
set the new `APP_KEY`, put the old one in `APP_PREVIOUS_KEYS`, restart. Keep
the old key there until nothing encrypted under it remains in use (sessions
expire; encrypted columns such as two-factor secrets are rewritten when next
saved).

## A key that has leaked

1. Rotate it as above, immediately; for a device private key, renew the
   certificate with a new OTP, and revoke the old one on the Fatoora portal.
2. Treat everything signed or sent with it since the leak as suspect, and
   check the audit log for who acted.
3. Report it: [SECURITY.md](../../SECURITY.md).
