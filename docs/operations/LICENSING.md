# Platform licensing

Audience: partners running a licensed installation, and the operators who support them.

A partner who runs the platform on their own infrastructure needs a licence
key. This page covers the key, how the platform checks it, and the licence
server it can phone home to. An operator hosting the platform itself sets
`PLATFORM_LICENSE_ENABLED=false` and can skip the rest.

## Settings

| Setting | Meaning |
|---|---|
| `PLATFORM_LICENSE_ENABLED` | Default true. While true, every API request (not the web console) needs a valid key. The health, readiness and licence-status paths are exempt. |
| `PLATFORM_LICENSE_KEY` | The key issued to the partner. |
| `PLATFORM_LICENSE_SECRET` | The secret keys are signed with; the same value on the issuer's and the partner's side. Production refuses to start without it while licensing is enabled. |
| `PLATFORM_LICENSE_SERVER_URL` | Optional licence server. Unset: the key is verified offline, from its signature. |
| `PLATFORM_LICENSE_CACHE_SECONDS` | How long a result is cached (3600). A revoked key stops working within this time. |
| `PLATFORM_LICENSE_WARNING_DAYS` | Warn this many days before expiry (7). |

Without a valid key the API answers 403 with the code `LICENSE_INVALID`.

## The key

```
{PARTNER}-{TYPE}-{EXPIRY YYYYMMDD}-{SIGNATURE}
```

`TYPE` is `TRIAL` (500 invoices a month, 5 organizations), `PROD` (no limits)
or `DEV` (100 invoices a month, 2 organizations). The signature is an HMAC
over the rest, under `PLATFORM_LICENSE_SECRET`.

```bash
php artisan license:generate ACME --type=TRIAL --days=30
php artisan license:generate ACME --type=PROD --expires=<YYYY-MM-DD>
php artisan license:status               # the deployment's own key; --clear-cache to re-check now
php artisan license:report-usage --show  # the usage it would report
```

## How it is checked

1. The key and secret must both be set, or the key is invalid.
2. A cached result is used while it lasts.
3. With a server URL, the server's `/validate` decides: it knows about
   revocation as well as expiry.
4. If the server cannot be reached, or asks for it, the key is verified
   offline from its signature and expiry.

With licensing enabled, `license:report-usage` sends usage hourly (invoices
created, submitted, cleared and reported; organizations; API calls).

## The licence server

A small edge worker kept in the repository with its own setup notes
([its README](../../cloudflare-worker/README.md)). It stores licences in a
key-value namespace and is administered with an `ADMIN_SECRET` of its own.

| Path | Method | Admin secret | Purpose |
|---|---|---|---|
| `/health` | GET | no | Is it up. |
| `/validate` | POST | no | Validate a key. |
| `/usage` | POST | no (signed) | Receive a usage report. |
| `/register` | POST | yes | Register a key, so it can be revoked. |
| `/revoke` | POST | yes | Revoke a key. |
| `/list` | GET | yes | All licences. |
| `/usage/stats` | GET | yes | Usage by partner and month. |

- **Admin calls** send the secret in the `X-Admin-Secret` header only, never
  the query string (access logs record it) or the body; it is compared in
  constant time. The worker refuses every admin call with 503, and logs why,
  while `ADMIN_SECRET` is unset or shorter than 32 characters. Admin routes
  send no CORS headers and allow 60 calls a minute per address.
- **Failed authentication** (admin or usage) locks the address out for ten
  minutes after ten attempts.
- **Usage reports** are signed by the deployment with
  `PLATFORM_LICENSE_SECRET` (the worker's `LICENSE_SECRET`):
  `X-Usage-Timestamp` and `X-Usage-Signature`, hex HMAC-SHA256 of
  `<timestamp>.<body>`, at most five minutes old. The worker takes them only
  for a licence registered there and not revoked, and files them under the
  registry's partner. `php artisan license:report-usage` signs; without the
  secret it sends nothing. The secret is shared by every deployment, so the
  signature keeps out anyone without it, not one partner from another's key.

Manual check after deploying the worker: `/list` without the header answers
401, with it 200; `/list?admin_secret=...` answers 401; `/usage` unsigned
answers 401; `php artisan license:report-usage` succeeds.

Revoke a partner at once with `/revoke`; their API stops within the cache
time. A key that was never registered can only expire.

## When validation fails

1. `php artisan license:status --clear-cache` names the reason.
2. Check `PLATFORM_LICENSE_KEY` and that `PLATFORM_LICENSE_SECRET` matches
   the issuer's.
3. Check the key has not expired or been revoked (`/list`).
4. The server unreachable is not itself a failure: the key is then verified
   offline.
