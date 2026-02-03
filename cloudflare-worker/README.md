# CompliPay License Server (Cloudflare Worker)

Free license validation server for phone-home verification.

## Current Deployment

> **Already Deployed:** `https://raspy-wood-ef0d.shamil3ilm.workers.dev`
>
> See [PHONE-HOME-LICENSE-SYSTEM.md](../docs/PHONE-HOME-LICENSE-SYSTEM.md) for usage instructions.

## Setup (For New Deployments)

### 1. Create Cloudflare Account
- Go to [cloudflare.com](https://cloudflare.com)
- Sign up for free

### 2. Create Worker
1. Go to **Workers & Pages** in Cloudflare dashboard
2. Click **Create Application** → **Create Worker**
3. Name it (e.g., `complipay-license-server`)
4. Click **Deploy**

### 3. Add Code
1. Click **Edit code**
2. Delete the default code
3. Paste contents of `license-server.js`
4. Click **Save and Deploy**

### 4. Create KV Namespace
1. Go to **Workers & Pages** → **KV**
2. Click **Create a namespace**
3. Name it `LICENSES`
4. Note the namespace ID

### 5. Bind KV to Worker
1. Go back to your Worker
2. Click **Settings** → **Variables**
3. Under **KV Namespace Bindings**, click **Add binding**
4. Variable name: `LICENSES`
5. Select your namespace

### 6. Add Environment Variables
In Worker Settings → Variables → Environment Variables:

| Variable | Value | Description |
|----------|-------|-------------|
| `ADMIN_SECRET` | `your-secret-here` | Secret for admin operations |
| `LICENSE_SECRET` | (same as `PLATFORM_LICENSE_SECRET` in .env) | For offline signature verification |

### 7. Get Your Worker URL
Your license server URL will be:
```
https://complipay-license-server.YOUR-SUBDOMAIN.workers.dev
```

### 8. Configure CompliPay
Add to your `.env`:
```env
PLATFORM_LICENSE_ENABLED=true
PLATFORM_LICENSE_KEY=PARTNER-TYPE-YYYYMMDD-signature
PLATFORM_LICENSE_SERVER_URL=https://your-worker.YOUR-SUBDOMAIN.workers.dev
PLATFORM_LICENSE_SECRET=your-license-secret-here
```

## API Endpoints

### Validate License (Public)
```bash
curl -X POST https://your-worker.workers.dev/validate \
  -H "Content-Type: application/json" \
  -d '{"license_key": "TAXFLY-TRIAL-20260303-abc123"}'
```

### Register License (Admin)
```bash
curl -X POST https://your-worker.workers.dev/register \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "your-admin-secret",
    "license_key": "TAXFLY-TRIAL-20260303-abc123",
    "partner": "TAXFLY",
    "type": "TRIAL",
    "expires": "2026-03-03"
  }'
```

### Revoke License (Admin)
```bash
curl -X POST https://your-worker.workers.dev/revoke \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "your-admin-secret",
    "license_key": "TAXFLY-TRIAL-20260303-abc123",
    "reason": "Partnership ended"
  }'
```

### List All Licenses (Admin)
```bash
curl "https://your-worker.workers.dev/list?admin_secret=your-admin-secret"
```

### Report Usage (From Partner)
```bash
curl -X POST https://your-worker.workers.dev/usage \
  -H "Content-Type: application/json" \
  -d '{
    "license_key": "TAXFLY-PROD-20270203-abc123",
    "metrics": {
      "invoices_created": 150,
      "invoices_submitted": 145,
      "invoices_cleared": 100,
      "invoices_reported": 45,
      "organizations_count": 25,
      "api_calls": 5000
    }
  }'
```

### Get Usage Stats (Admin)
```bash
# All partners this month
curl "https://your-worker.workers.dev/usage/stats?admin_secret=your-admin-secret"

# Specific partner
curl "https://your-worker.workers.dev/usage/stats?admin_secret=your-admin-secret&partner=TAXFLY"
```

### Health Check
```bash
curl https://your-worker.workers.dev/health
```

## Cost

**$0** - Cloudflare Workers free tier includes:
- 100,000 requests/day
- 10ms CPU time per request
- 1GB KV storage

This is more than enough for license validation.

## Workflow

### Generate License (Local)
```bash
php artisan license:generate TAXFLY --type=TRIAL --days=30
```

### Register in Cloud (Optional)
If you want remote revocation capability:
```bash
curl -X POST https://your-worker.workers.dev/register \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "your-secret",
    "license_key": "TAXFLY-TRIAL-20260303-abc123",
    "partner": "TAXFLY",
    "type": "TRIAL",
    "expires": "2026-03-03"
  }'
```

### Partner Deploys
Partner adds to their `.env`:
```env
PLATFORM_LICENSE_KEY=TAXFLY-TRIAL-20260303-abc123
```

### Validation Flow
1. Partner's app starts
2. App calls your Cloudflare Worker
3. Worker validates and responds
4. If Worker unreachable, app uses offline validation

### Revoke Access (Emergency)
```bash
curl -X POST https://your-worker.workers.dev/revoke \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "your-secret",
    "license_key": "TAXFLY-TRIAL-20260303-abc123",
    "reason": "Non-payment"
  }'
```

Partner's app will stop working within 1 hour (cache expiry).

## Security Notes

1. Keep `ADMIN_SECRET` private - never commit to version control
2. Use different secrets for different environments
3. Monitor Worker analytics for unusual patterns
4. Cloudflare provides DDoS protection automatically
5. The `LICENSE_SECRET` must match `PLATFORM_LICENSE_SECRET` in partner's `.env` for offline validation

## Related Documentation

- [Phone-Home License System](../docs/PHONE-HOME-LICENSE-SYSTEM.md) - Complete system documentation
- [TaxFly Deployment Guide](../docs/TAXFLY-DEPLOYMENT-GUIDE.md) - Partner deployment instructions

---

**Last Updated:** February 3, 2026
