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
| `CERT_EXPIRED` | CSID certificate expired — run `fatoora:renew-certificate` |
| `INVALID_VAT` | VAT number format invalid (must be 15 digits, start+end with 3) |

## Deprecated Endpoints

The following endpoints are kept for v1 backward compatibility and will be removed in v2.0:

```
POST /api/compliance/zatca/submit/{id}  →  Use /api/compliance/sa/submit/{id}
GET  /api/compliance/zatca/status/{id}  →  Use /api/compliance/sa/status/{id}
```
