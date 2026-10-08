# Saudi Arabia — Platform API Integration Guide

**Base URL:** `https://your-masaar-instance.com/api`  
**Auth:** JWT Bearer token (`Authorization: Bearer <token>`)

---

## Quick Start

### 1. Register and log in

```http
POST /api/auth/register
Content-Type: application/json
{
  "name": "My Company",
  "email": "admin@mycompany.sa",
  "password": "secret"
}

POST /api/auth/login
→ returns { "token": "eyJ..." }
```

### 2. Create a Compliance Profile (SA)

```http
POST /api/organizations/{org_id}/compliance-profiles
Authorization: Bearer <token>
Content-Type: application/json
{
  "jurisdiction": "SA",
  "engine": "fatoora",
  "status": "pending_onboarding",
  "settings": {
    "vat_number": "300000000000003",
    "wave": 24
  }
}
```

### 3. Onboard (CSID)

```http
POST /api/compliance/onboarding/request-csid
Authorization: Bearer <token>
{ "otp": "123456" }
```

The OTP above is a placeholder. ZATCA's developer-portal sandbox carries no
taxpayer identity and takes any value, which is why examples of this call are
usually written with a dummy one. Simulation and production do not: the OTP is
issued to your own TIN and lasts an hour.

To get one: sign in at <https://fatoora.zatca.gov.sa/> with your TIN
credentials, open the Fatoora Simulation Portal for testing, then **Onboard New
Solution Unit/Device** and generate the OTP. `php artisan fatoora:onboard
--step=info` prints the same steps.

The certificate is requested for whatever the CSR says, and an OTP is spent
whether the request is accepted or refused, so check the CSR's identity first.
Every default of `fatoora:generate-csr` is ZATCA's sample taxpayer:

```bash
php artisan fatoora:generate-csr \
  --vat=<your 15-digit VAT number> \
  --org="<your registered name>" \
  --unit=<your 10-digit TIN> \
  --cn=<a name for this device> \
  --template=PREZATCA-Code-Signing   # ZATCA-Code-Signing for production
```

`--unit` must be the 10-digit TIN of the group member whose device is being
onboarded. ZATCA's SDK refuses a department name for every one of the three
templates, so the command's own `IT Department` default cannot produce a CSR
through the SDK at all.

The templates, and the flag each one is requested with:

| Template | Environment | SDK flag |
|---|---|---|
| `TSTZATCA-Code-Signing` | compliance / sandbox | `-nonprod` |
| `PREZATCA-Code-Signing` | simulation | `-sim` |
| `ZATCA-Code-Signing` | production | none; the SDK's default |

The generated request is read back to check it carries the template that was
asked for, because a request sent to one environment carrying another's
template is refused - and the OTP is spent either way.

### 4. Generate + Submit an Invoice

```http
POST /api/compliance/sa/generate/{invoice_id}
→ returns { "data": { "hash": "...", "qr_code": "..." } }

POST /api/compliance/sa/submit/{invoice_id}
→ returns { "data": { "clearance_status": "CLEARED", ... } }
```

### 5. Check Status

```http
GET /api/compliance/sa/status/{submission_id}
```

---

## Error Codes

| Code | Meaning |
|------|---------|
| `FATOORA_REJECTED` | ZATCA rejected the invoice — check `errors` array |
| `FATOORA_UNAVAILABLE` | ZATCA API unreachable — invoice queued for offline retry |
| `CERT_EXPIRED` | CSID certificate expired — run `fatoora:check-certificate`, then onboard again for a new CSID |
| `INVALID_VAT` | VAT number format invalid (must be 15 digits, start+end with 3) |

## Deprecated Endpoints

The following endpoints are kept for v1 backward compatibility and will be removed in v2.0:

```
POST /api/compliance/zatca/submit/{id}  →  Use /api/compliance/sa/submit/{id}
GET  /api/compliance/zatca/status/{id}  →  Use /api/compliance/sa/status/{id}
```
