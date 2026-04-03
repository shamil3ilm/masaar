# UAE — Platform API Integration Guide

**Base URL:** `https://your-masaar-instance.com/api`  
**Auth:** JWT Bearer token (`Authorization: Bearer <token>`)

---

## Quick Start

### 1. Create a Compliance Profile (AE)

```http
POST /api/organizations/{org_id}/compliance-profiles
Authorization: Bearer <token>
Content-Type: application/json
{
  "jurisdiction": "AE",
  "engine": "fta",
  "status": "pending_onboarding",
  "settings": {
    "vat_number": "100000000000003",
    "peppol_participant_id": "0235100000000"
  }
}
```

### 2. Submit an Invoice

```http
POST /api/compliance/ae/submit/{invoice_id}
Authorization: Bearer <token>
→ returns {
    "data": {
      "submission_id": "...",
      "status": "queued",
      "fta_ref": null
    }
  }
```

### 3. Check Status (async)

```http
GET /api/compliance/ae/status/{submission_id}
→ returns {
    "data": {
      "status": "accepted",
      "fta_validation_status": "PASS",
      "accepted_at": "2027-01-15T10:30:00Z"
    }
  }
```

### 4. Retry a Failed Submission

```http
POST /api/compliance/ae/retry/{submission_id}
```

---

## Invoice Requirements

UAE FTA invoices **must** include:
- Supplier TRN (15 digits)
- Customer TRN (for B2B — leave null for B2C, which is excluded)
- Currency: AED
- Document type: 380 (invoice), 381 (credit note), 383 (debit note)
- VAT rate: 5% or 0% (zero-rated)

## Error Codes

| Code | Meaning |
|------|---------|
| `FTA_REJECTED` | FTA rejected the invoice — check `errors` array |
| `FTA_INVALID_TRN` | TRN must be exactly 15 digits |
| `FTA_INVALID_CURRENCY` | Only AED is accepted |
| `FTA_B2C_EXCLUDED` | B2C invoices are not submitted to FTA |
| `FTA_MISSING_REFERENCE` | Credit/debit notes must reference the original invoice |

## Deprecated Endpoints

```
POST /api/compliance/uae-fta/submit/{id}  →  Use /api/compliance/ae/submit/{id}
GET  /api/compliance/uae-fta/status/{id}  →  Use /api/compliance/ae/status/{id}
```
