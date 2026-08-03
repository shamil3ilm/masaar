# Phone-Home License System

This document describes the Masaar license validation system using Cloudflare Workers.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         License Validation Flow                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│   Partner's Server                    Cloudflare Worker (Free)           │
│   ┌─────────────────┐                ┌─────────────────────────┐        │
│   │                 │   1. Validate  │                         │        │
│   │  Masaar App  │ ─────────────► │  License Server         │        │
│   │  (Docker)       │                │  raspy-wood-ef0d        │        │
│   │                 │ ◄───────────── │  .shamil3ilm.workers.dev│        │
│   └─────────────────┘   2. Response  └──────────┬──────────────┘        │
│          │                                      │                        │
│          │ 3. Fallback                          │                        │
│          ▼ (if offline)                         ▼                        │
│   ┌─────────────────┐                ┌─────────────────────────┐        │
│   │ Offline License │                │  Cloudflare KV          │        │
│   │ Validation      │                │  (LICENSES namespace)   │        │
│   └─────────────────┘                └─────────────────────────┘        │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

## Cloudflare Worker Details

### URL
```
https://raspy-wood-ef0d.shamil3ilm.workers.dev
```

### Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/health` | GET | None | Health check |
| `/validate` | POST | None | Validate license key |
| `/register` | POST | ADMIN_SECRET | Register new license |
| `/revoke` | POST | ADMIN_SECRET | Revoke a license |
| `/list` | GET | ADMIN_SECRET | List all licenses |
| `/usage` | POST | None | Report usage metrics |
| `/usage/stats` | GET | ADMIN_SECRET | Get usage statistics |

### Environment Variables

| Variable | Type | Description |
|----------|------|-------------|
| `ADMIN_SECRET` | Secret | Password for admin operations |

### KV Namespace

| Namespace | Variable | Description |
|-----------|----------|-------------|
| LICENSES | `LICENSES` | Stores license data |

## License Key Format

```
{PARTNER}-{TYPE}-{EXPIRY}-{SIGNATURE}

Example: TAXFLY-TRIAL-20260305-a1b2c3d4
         │       │      │        │
         │       │      │        └─ HMAC signature (8 chars)
         │       │      └─ Expiry date (YYYYMMDD)
         │       └─ License type (TRIAL/PROD/DEV)
         └─ Partner identifier
```

## License Types

| Type | Features |
|------|----------|
| `TRIAL` | 500 invoices/month, 5 orgs, email support |
| `PROD` | Unlimited invoices, unlimited orgs, priority support |
| `DEV` | 100 invoices/month, 2 orgs, community support |

## Local Commands

### Generate License Key
```powershell
php artisan license:generate TAXFLY --type=TRIAL --days=30
php artisan license:generate ACME --type=PROD --expires=2027-12-31
```

### Check License Status
```powershell
php artisan license:status
php artisan license:status --clear-cache
```

### Report Usage Metrics
```powershell
# Show current metrics without reporting
php artisan license:report-usage --show

# Report metrics to license server
php artisan license:report-usage
```

## Usage Tracking

Partners automatically report usage metrics hourly (via scheduler). This enables:
- Usage-based billing
- Monitoring partner activity
- Enforcing license limits

### Tracked Metrics

| Metric | Description |
|--------|-------------|
| `invoices_created` | New invoices since last report |
| `invoices_submitted` | Invoices submitted to ZATCA |
| `invoices_cleared` | B2B invoices cleared |
| `invoices_reported` | B2C invoices reported |
| `organizations_count` | Total active organizations |
| `api_calls` | API requests made |

### View Partner Usage (Admin)

```powershell
# All partners this month
Invoke-RestMethod -Uri "https://raspy-wood-ef0d.shamil3ilm.workers.dev/usage/stats?admin_secret=YOUR_ADMIN_SECRET"

# Specific partner
Invoke-RestMethod -Uri "https://raspy-wood-ef0d.shamil3ilm.workers.dev/usage/stats?admin_secret=YOUR_ADMIN_SECRET&partner=TAXFLY"
```

### Response Example
```json
{
  "partner": "TAXFLY",
  "month": {
    "partner": "TAXFLY",
    "month": "2026-02",
    "total_invoices_created": 1500,
    "total_invoices_submitted": 1450,
    "total_invoices_cleared": 1000,
    "total_invoices_reported": 450,
    "peak_organizations": 25,
    "total_api_calls": 50000
  }
}
```

## API Operations

### Register License (Enable Remote Revocation)

```powershell
$body = @{
    admin_secret = "YOUR_ADMIN_SECRET"
    license_key = "TAXFLY-TRIAL-20260305-xxxxxxxx"
    partner = "TAXFLY"
    type = "TRIAL"
    expires = "2026-03-05"
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://raspy-wood-ef0d.shamil3ilm.workers.dev/register" `
    -Method POST -Body $body -ContentType "application/json"
```

### Revoke License

```powershell
$body = @{
    admin_secret = "YOUR_ADMIN_SECRET"
    license_key = "TAXFLY-TRIAL-20260305-xxxxxxxx"
    reason = "Partnership ended"
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://raspy-wood-ef0d.shamil3ilm.workers.dev/revoke" `
    -Method POST -Body $body -ContentType "application/json"
```

### List All Licenses

```powershell
Invoke-RestMethod -Uri "https://raspy-wood-ef0d.shamil3ilm.workers.dev/list?admin_secret=YOUR_ADMIN_SECRET"
```

### Validate License (Test)

```powershell
$body = @{
    license_key = "TAXFLY-TRIAL-20260305-xxxxxxxx"
    domain = "api.taxfly.com"
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://raspy-wood-ef0d.shamil3ilm.workers.dev/validate" `
    -Method POST -Body $body -ContentType "application/json"
```

## Configuration

### Your Environment (.env)
```env
# Development - license check disabled
PLATFORM_LICENSE_ENABLED=false
PLATFORM_LICENSE_KEY=
PLATFORM_LICENSE_SERVER_URL=https://raspy-wood-ef0d.shamil3ilm.workers.dev
PLATFORM_LICENSE_SECRET=your-secret-here-generate-with-php-random-bytes
```

### Partner Environment (.env)
```env
# Production - license check enabled
PLATFORM_LICENSE_ENABLED=true
PLATFORM_LICENSE_KEY=TAXFLY-TRIAL-20260305-xxxxxxxx
PLATFORM_LICENSE_SERVER_URL=https://raspy-wood-ef0d.shamil3ilm.workers.dev
PLATFORM_LICENSE_SECRET=your-secret-here-must-match-your-env
```

## Validation Flow

1. **App starts** → Reads `PLATFORM_LICENSE_KEY` from .env
2. **Phone-home** → Calls Cloudflare Worker `/validate`
3. **Worker checks** → Looks up key in KV, checks expiry/revocation
4. **Response** → Returns valid/invalid with details
5. **Fallback** → If Worker unreachable, uses offline validation
6. **Cache** → Result cached for 1 hour

## Security

| Secret | Location | Purpose |
|--------|----------|---------|
| `ADMIN_SECRET` | Cloudflare Worker | Admin API access |
| `PLATFORM_LICENSE_SECRET` | .env (both sides) | Signs license keys |

**Important:** Both you and the partner must use the same `PLATFORM_LICENSE_SECRET` for offline validation to work.

## Cost

| Service | Cost |
|---------|------|
| Cloudflare Workers | Free (100k requests/day) |
| Cloudflare KV | Free (1GB storage) |
| **Total** | **$0** |

## Cloudflare Dashboard

- **Workers & Pages:** https://dash.cloudflare.com → Workers & Pages
- **KV Storage:** https://dash.cloudflare.com → Storage & databases → KV
- **Worker Logs:** Workers & Pages → raspy-wood-ef0d → Logs

## Emergency: Revoke Partner Access

If you need to immediately revoke a partner's access:

```powershell
# Revoke via API
$body = @{
    admin_secret = "YOUR_ADMIN_SECRET"
    license_key = "PARTNER-TYPE-DATE-SIGNATURE"
    reason = "Reason for revocation"
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://raspy-wood-ef0d.shamil3ilm.workers.dev/revoke" `
    -Method POST -Body $body -ContentType "application/json"
```

Partner's app will stop working within 1 hour (when cache expires).

## Troubleshooting

### License validation fails
1. Check `PLATFORM_LICENSE_KEY` is set correctly
2. Check `PLATFORM_LICENSE_SECRET` matches
3. Check license hasn't expired
4. Run `php artisan license:status --clear-cache`

### Phone-home fails
- App falls back to offline validation
- Check Worker is deployed: https://raspy-wood-ef0d.shamil3ilm.workers.dev/health
- Check KV binding in Cloudflare dashboard

### Admin operations fail
- Verify `ADMIN_SECRET` matches what's in Cloudflare
- Check request format (JSON with correct fields)

---

**Last Updated:** February 3, 2026
