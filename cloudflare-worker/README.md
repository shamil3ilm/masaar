# Masaar License Server (Cloudflare Worker)

Free license validation server for phone-home verification.

See [LICENSING.md](../docs/operations/LICENSING.md) for how the platform uses it.

## Setup

### 1. Create Cloudflare Account
- Go to [cloudflare.com](https://cloudflare.com)
- Sign up for free

### 2. Create Worker
1. Go to **Workers & Pages** in Cloudflare dashboard
2. Click **Create Application** → **Create Worker**
3. Name it (e.g., `masaar-license-server`)
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
| `ADMIN_SECRET` | at least 32 characters, e.g. `openssl rand -hex 32` | Secret for admin operations. **Required**: unset or shorter, every admin call answers 503 |
| `LICENSE_SECRET` | (same as `PLATFORM_LICENSE_SECRET` in .env) | Verifies the signature on usage reports. **Required** for `/usage`: unset, every report answers 503 |

Add both as encrypted secrets (`wrangler secret put ADMIN_SECRET`), not plain variables.

### 7. Get Your Worker URL
Your license server URL will be:
```
https://masaar-license-server.YOUR-SUBDOMAIN.workers.dev
```

### 8. Configure Masaar
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
  -d '{"license_key": "ACME-TRIAL-YYYYMMDD-abc123"}'
```

### Register License (Admin)
```bash
curl -X POST https://your-worker.workers.dev/register \
  -H "Content-Type: application/json" \
  -H "X-Admin-Secret: your-admin-secret" \
  -d '{
    "license_key": "ACME-TRIAL-YYYYMMDD-abc123",
    "partner": "ACME",
    "type": "TRIAL",
    "expires": "YYYY-MM-DD"
  }'
```

### Revoke License (Admin)
```bash
curl -X POST https://your-worker.workers.dev/revoke \
  -H "Content-Type: application/json" \
  -H "X-Admin-Secret: your-admin-secret" \
  -d '{
    "license_key": "ACME-TRIAL-YYYYMMDD-abc123",
    "reason": "Partnership ended"
  }'
```

### List All Licenses (Admin)
```bash
curl -H "X-Admin-Secret: your-admin-secret" "https://your-worker.workers.dev/list"
```

### Report Usage (From Partner)
```bash
# Signed by the deployment (php artisan license:report-usage does this):
# X-Usage-Signature = hex HMAC-SHA256 under LICENSE_SECRET of "<timestamp>.<body>"
curl -X POST https://your-worker.workers.dev/usage \
  -H "Content-Type: application/json" \
  -H "X-Usage-Timestamp: 1767225600" \
  -H "X-Usage-Signature: <hex hmac>" \
  -d '{
    "license_key": "ACME-PROD-YYYYMMDD-abc123",
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
curl -H "X-Admin-Secret: your-admin-secret" "https://your-worker.workers.dev/usage/stats"

# Specific partner
curl -H "X-Admin-Secret: your-admin-secret" "https://your-worker.workers.dev/usage/stats?partner=ACME"
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
php artisan license:generate ACME --type=TRIAL --days=30
```

### Register in Cloud (Optional)
If you want remote revocation capability:
```bash
curl -X POST https://your-worker.workers.dev/register \
  -H "Content-Type: application/json" \
  -H "X-Admin-Secret: your-admin-secret" \
  -d '{
    "license_key": "ACME-TRIAL-YYYYMMDD-abc123",
    "partner": "ACME",
    "type": "TRIAL",
    "expires": "YYYY-MM-DD"
  }'
```

### Partner Deploys
Partner adds to their `.env`:
```env
PLATFORM_LICENSE_KEY=ACME-TRIAL-YYYYMMDD-abc123
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
  -H "X-Admin-Secret: your-admin-secret" \
  -d '{
    "license_key": "ACME-TRIAL-YYYYMMDD-abc123",
    "reason": "Non-payment"
  }'
```

Partner's app will stop working within 1 hour (cache expiry).

## Security Notes

1. Keep `ADMIN_SECRET` private - never commit to version control
2. Use different secrets for different environments
3. Admin calls (`/register`, `/revoke`, `/list`, `/usage/stats`) take the secret only in the `X-Admin-Secret` header - never the query string, which access logs record, nor the body. It is compared in constant time
4. Admin routes send no CORS headers, allow 60 calls a minute per address, and lock an address out for 10 minutes after 10 failed attempts (admin or usage)
5. `/usage` accepts only a report signed by the deployment (`X-Usage-Timestamp`, and `X-Usage-Signature`: hex HMAC-SHA256 under `LICENSE_SECRET` of `<timestamp>.<body>`, at most 5 minutes old) for a licence registered here and not revoked; the partner comes from the registry
6. Monitor Worker analytics for unusual patterns
7. Cloudflare provides DDoS protection automatically
8. The `LICENSE_SECRET` must match `PLATFORM_LICENSE_SECRET` in partner's `.env` for offline validation

## Related Documentation

- [Platform licensing](../docs/operations/LICENSING.md) - Complete system documentation
