# Partner Integration Guide

How an ERP or invoicing system files its customers' e-invoices through Masaar:
the licence-key API under `/api/v1`, called from the partner's server.

> The `/api` endpoints that take a login token (`Authorization: Bearer <JWT>`
> from `POST /api/auth/login`) are the portal and app API, for a person who has
> signed in. They are not for partner servers, and nothing in this guide uses
> them.

## Table of Contents

1. [Overview](#1-overview)
2. [Prerequisites](#2-prerequisites)
3. [Installation & Setup](#3-installation--setup)
4. [API Authentication](#4-api-authentication)
5. [Submitting Invoices](#5-submitting-invoices)
6. [Service Integration](#6-service-integration)
7. [Database Changes](#7-database-changes)
8. [Invoice Submission Flow](#8-invoice-submission-flow)
9. [Webhook Configuration](#9-webhook-configuration)
10. [Testing & Validation](#10-testing--validation)
11. [Migration Checklist](#11-migration-checklist)
12. [Troubleshooting](#12-troubleshooting)
13. [Changes for integrators](#changes-for-integrators)

---

## 1. Overview

### Typical Starting Point
A partner system often already issues invoices and may produce a Phase 1 QR code, but lacks what Phase 2 requires: XAdES-BES signing and the ZATCA clearance and reporting APIs.

### What Masaar Provides
- Complete ZATCA Phase 2 compliance
- UBL 2.1 XML generation with all required elements
- XAdES-BES digital signatures (ECDSA secp256k1)
- QR code generation (9 tags for Phase 2)
- ZATCA API integration (clearance & reporting)
- Webhook notifications for status updates
- **Multi-branch EGS support** (separate credentials per branch)
- **Foreign currency invoices** (USD, EUR, etc. with SAR VAT reporting)
- UAE organizations: the same endpoints issue Peppol PINT AE e-invoices

### Integration Strategy
Replace the partner system's own ZATCA handling with Masaar API calls while keeping its existing invoice management UI and business logic.

### Who does what

| Step | Who | Where |
|---|---|---|
| Sign up; approval assigns a plan and issues the organization's licence | The customer, then a platform administrator | Portal sign-up, admin console |
| Hand the licence key, secret and organization id to the partner | The platform team | Out of band, once |
| Connect to ZATCA (OTP, compliance checks, production certificate), add branches | The customer's administrator | The portal's ZATCA page |
| Draft, issue and file invoices; read their status | The partner's server | `/api/v1`, with the licence key |
| Subscribe to webhooks | The partner's server | `/api/v1/webhooks`, with the licence key |

One licence acts for one organization. A partner serving several customers
holds one key and secret per customer.

> **Advanced Scenarios**: For multi-business, multi-branch, and cross-border transactions, see [PARTNER-ADVANCED-SCENARIOS.md](./PARTNER-ADVANCED-SCENARIOS.md).

---

## 2. Prerequisites

### System Requirements
- PHP 8.0+ with Laravel 8 or later (the examples use Laravel's HTTP client, `match` and the nullsafe operator)
- OpenSSL, cURL and JSON extensions

### Required Credentials

1. **Licence key and secret** - issued by a platform administrator when the
   customer's organization is approved, or from the console's Licences page.
   The secret is shown once and stored only as a hash, so keep it in a secret
   store. A sandbox key starts `cp_test_`, a production key `cp_live_`.
2. **Organization id** - the UUID of the customer's organization, shown on the
   licence's page in the console. Pipeline requests name it as `org_id`.
3. **A connected organization** - the customer's administrator connects it to
   ZATCA in the portal with an OTP from the Fatoora portal. Until then an
   invoice can be drafted but not issued.
4. **The seller's Arabic name** - a Saudi organization's Arabic name, given at
   sign-up or on the portal's company page, is printed on every invoice.

### Masaar Endpoints

| Environment | Base URL |
|-------------|----------|
| Sandbox | `https://sandbox.example.com` |
| Simulation | `https://simulation.example.com` |
| Production | `https://api.example.com` |

---

## 3. Installation & Setup

### Step 1: Add Configuration

Create `config/masaar.php`:

```php
<?php

return [
    'base_url' => env('MASAAR_BASE_URL', 'https://sandbox.example.com'),

    // One customer's licence. With several customers, keep each one's key,
    // secret and organization id with the customer instead (section 7).
    'api_key' => env('MASAAR_API_KEY'),
    'api_secret' => env('MASAAR_API_SECRET'),
    'org_id' => env('MASAAR_ORG_ID'),

    // A standard invoice is cleared within the call, so allow for ZATCA.
    'timeout' => env('MASAAR_TIMEOUT', 60),

    'retry' => [
        'times' => 3,
        'max_wait' => 60, // seconds; a longer Retry-After is not waited for
    ],

    'webhook' => [
        'secret' => env('MASAAR_WEBHOOK_SECRET'),
    ],
];
```

### Step 2: Add Environment Variables

Add to `.env`:

```env
MASAAR_BASE_URL=https://sandbox.example.com
MASAAR_API_KEY=cp_test_your_key_here
MASAAR_API_SECRET=your_secret_here
MASAAR_ORG_ID=your_organization_uuid_here
MASAAR_WEBHOOK_SECRET=your_webhook_secret_here
MASAAR_TIMEOUT=60
```

---

## 4. API Authentication

### Licence key and secret

Every request carries the licence's key and secret in two headers:

```http
X-API-Key: cp_live_...
X-API-Secret: ...
```

`Authorization: Bearer <base64 of key:secret>` is accepted instead. Neither is
ever read from the query string: URLs are logged by proxies and servers.

Each request is checked for the key, the secret, the licence's status and
expiry, the scope the route needs, and the licence's call limits; it then acts
for the licence's organization only. Answers carry `X-License-Tier`,
`X-License-Environment`, `X-RateLimit-Limit-Minute` and
`X-RateLimit-Limit-Day`, and `X-RateLimit-Limit` and `X-RateLimit-Remaining`
for the organization's budget on that kind of endpoint.

**Rotating the secret.** A platform administrator regenerates it from the
licence's page. The key stays the same; the old secret stops working at once,
so deploy the new one straight away. There is no rotation endpoint on
`/api/v1`. To ask for a rotation, write to shamil3ilm@gmail.com.

**Your licence.** These answer with the same two headers:

| Endpoint | Answers |
|---|---|
| `GET /api/license` | Tier, status, expiry, features and limits |
| `GET /api/license/quotas` | Invoices used and remaining this month, the call limits |
| `GET /api/license/usage` | The month's counts by day (`?month=YYYY-MM`) |
| `GET /api/license/health` | Expiry and allowance warnings |
| `GET /api/license/features/{feature}` | Whether the licence has one feature |

### Errors

Every error has the same envelope:

```json
{"success": false, "error": {"code": "AUTH_SCOPE_DENIED", "message": "...", "category": "license", "retryable": false, "details": {"required_scope": "webhook.manage"}}}
```

| HTTP | `error.code` | Meaning |
|---|---|---|
| 401 | `LICENSE_INVALID_KEY` | No key, or a key that does not exist |
| 401 | `LICENSE_INVALID_SECRET` | The secret does not match the key |
| 403 | `LICENSE_EXPIRED`, `LICENSE_SUSPENDED`, `LICENSE_REVOKED`, `LICENSE_INACTIVE`, `LICENSE_PENDING_ACTIVATION` | The licence cannot be used; `details` says why |
| 403 | `AUTH_SCOPE_DENIED` | The licence lacks the scope the route needs; `details.required_scope` names it |
| 403 | `LICENSE_FEATURE_UNAVAILABLE` | The licence lacks the feature the document needs; `details.feature` names it |
| 403 | `FORBIDDEN` | The `org_id` in the body is not the licence's organization |
| 403 | `AUTH_014` | A platform administrator has switched the pipeline or batch submission off for this organization |
| 429 | `LICENSE_RATE_LIMITED` | A call limit was reached; `Retry-After` says when to try again |
| 429 | `LICENSE_QUOTA_EXCEEDED` | The month's invoice allowance is used up |
| 429 | `RATE_LIMITED` | The organization's per-minute budget for this kind of endpoint is spent; `details.retry_after` in seconds |
| 422 | `VALIDATION_ERROR` | The body is wrong; `details` lists each field's errors |
| 422 | `JURISDICTION_UNAVAILABLE` | This deployment does not issue in the organization's country |

`error.retryable` is true for 408, 429 and 5xx answers.

### Scopes

A licence holds a list of scopes, and each route needs one:

| Scope | Routes |
|---|---|
| `invoice.read` | `GET /api/v1/invoices`, `GET /api/v1/invoices/{invoice}` |
| `invoice.submit` | `POST /api/v1/invoices`, `PUT /api/v1/invoices/{invoice}`, `POST /api/v1/compliance/generate/{invoiceId}`, `POST /api/v1/compliance/validate/{invoiceId}`, `POST /api/v1/compliance/submit/{invoiceId}`, `POST /api/v1/pipeline/submit`, `POST /api/v1/pipeline/batch` |
| `invoice.cancel` | `DELETE /api/v1/invoices/{invoice}` (a draft only) |
| `compliance.status` | `GET /api/v1/compliance/status/{invoiceId}`, `GET /api/v1/pipeline/status/{invoiceId}` |
| `webhook.manage` | `/api/v1/webhooks` |
| `schedule.read` | `GET /api/v1/schedules`, `GET /api/v1/schedules/{schedule}` |
| `schedule.manage` | `POST /api/v1/schedules` and the changes under `/api/v1/schedules/{schedule}` (see 5.7) |
| none | `GET /api/v1/dashboard`, `GET /api/v1/dashboard/health`, `GET /api/v1/dashboard/usage`, `/api/license` |

`invoice.cancel` includes `invoice.read`, `invoice.batch` includes
`invoice.submit`, and `schedule.manage` includes `schedule.read`. A licence
issued without a list gets its tier's: Starter `invoice.submit`,
`invoice.read`, `compliance.status`, `organization.read` and
`schedule.read`; Professional adds
`invoice.cancel`, `webhook.manage`, `schedule.manage` and others; Enterprise
and Unlimited add `invoice.batch` and the rest.

### Licence features

Writing a document needs the feature for its kind, and webhooks need theirs:

| Feature | Needed to |
|---|---|
| `simplified_invoices` | create, generate or submit a simplified (B2C) invoice |
| `standard_invoices` | create, generate or submit a standard (B2B) invoice |
| `credit_notes` | issue a credit note (as well as its invoice type) |
| `debit_notes` | issue a debit note (as well as its invoice type) |
| `webhook_notifications` | manage webhooks |

By default a Starter licence includes simplified invoices only; Professional
adds standard invoices, credit and debit notes and webhooks; Enterprise adds
the rest. A platform administrator can grant one beyond the plan.

### Limits and allowances

- **Calls a minute.** A safety cap set by the tier: 30 for Starter, 60 for
  Professional, 120 for Enterprise and 300 for Unlimited. Past it:
  `429 LICENSE_RATE_LIMITED`, with `Retry-After`.
- **Calls a day.** An allowance: every request made with the licence counts,
  including the `/api/license` ones. Past it: `429 LICENSE_RATE_LIMITED` until
  the day ends.
- **Invoices a month.** An allowance: an invoice counts when it is filed with
  ZATCA. A filing that got no answer from ZATCA gives its slot back; a
  rejection keeps it, because ZATCA processed the document. Once it is used up,
  `POST /api/v1/compliance/submit/{invoiceId}`, `POST /api/v1/pipeline/submit`
  and `POST /api/v1/pipeline/batch` answer `429 LICENSE_QUOTA_EXCEEDED` with
  `details` `{"limit_type": "invoices_per_month", "limit": ..., "current": ...}`
  before doing anything. Waiting does not help within the month; ask the
  platform team to raise the allowance.

An allowance is the customer's own: unlimited unless the platform sets a
default or a platform administrator sets one for this customer. The
organization's administrators are emailed when one reaches 80% and again at
100%, once each a day or a month. `GET /api/license/quotas` shows where it
stands; `-1` means unlimited.

Beside the licence's own limits, each organization has a per-minute budget per
kind of endpoint, set by the operator: by default 300 for submissions
(`/pipeline/submit`, `/pipeline/batch`), 600 for status reads, health and the
dashboard, and 300 for everything else. Past it: `429 RATE_LIMITED`.

### Idempotency

Send an `Idempotency-Key` header with every `POST /api/v1/pipeline/submit` and
`POST /api/v1/invoices`: a value you derive from your own record, such as
`invoice-1001`, at most 255 characters (longer is refused with 422 on
`idempotency_key`). A blank header counts as none.

A key names one request, for good, within the organization. A retry with the
same key never drafts a second invoice. It answers with the one the first
request made, as it stands now, and carries on where the first stopped:

- the first was drafted but not issued: it is issued now;
- the first was issued but not filed (the filing failed or ran out of
  allowance): it is filed now, if `auto_submit` asks for it;
- otherwise: its current state, a ZATCA rejection included.

On `POST /api/v1/invoices` a replay answers `200` with
`Idempotent-Replayed: true` where the first answered `201`.

The same key sent with a different invoice - another `invoice_number`, or,
without one, another `erp_reference_id` - is refused with 422 on
`invoice_number`: send a new key for a new invoice. A request refused by
validation drafted nothing, so it can be corrected and sent again under the
same key.

Without a key, a pipeline request that sends no `invoice_number` but an
`erp_reference_id` is matched to an earlier invoice with that reference
instead.

### Masaar API Client

Create `app/Services/Masaar/MasaarClient.php`:

```php
<?php

namespace App\Services\Masaar;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MasaarClient
{
    /**
     * One customer's licence: every call acts for its organization.
     */
    public function __construct(
        private string $apiKey,
        private string $apiSecret,
        public readonly string $organizationId,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('masaar.api_key'), config('masaar.api_secret'), config('masaar.org_id'));
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /**
     * A POST that drafts an invoice carries an Idempotency-Key, so a retry
     * after a timeout cannot file the sale twice.
     */
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        return $this->send('POST', $path, ['json' => $body], $idempotencyKey);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->send('PUT', $path, ['json' => $body]);
    }

    public function delete(string $path): array
    {
        return $this->send('DELETE', $path);
    }

    private function send(string $method, string $path, array $options = [], ?string $idempotencyKey = null): array
    {
        $times = config('masaar.retry.times', 3);

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::baseUrl(config('masaar.base_url'))
                    ->timeout(config('masaar.timeout', 60))
                    ->acceptJson()
                    ->withHeaders(array_filter([
                        'X-API-Key' => $this->apiKey,
                        'X-API-Secret' => $this->apiSecret,
                        'Idempotency-Key' => $idempotencyKey,
                    ]))
                    ->send($method, $path, $options);
            } catch (ConnectionException $e) {
                // No answer: safe to repeat only when a key names the request.
                if ($attempt >= $times || ($method === 'POST' && $idempotencyKey === null)) {
                    throw new MasaarException($e->getMessage(), 0);
                }
                sleep(2 ** $attempt);
                continue;
            }

            // 2xx, 207 included: the body says what happened to each step.
            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $wait = $this->retryAfter($response, $attempt);

            if ($wait !== null && $attempt < $times) {
                sleep($wait);
                continue;
            }

            $error = $response->json('error') ?? [];

            throw new MasaarException(
                $error['message'] ?? $response->reason(),
                $response->status(),
                $error['code'] ?? null,
                $error['details'] ?? [],
            );
        }
    }

    /**
     * Seconds to wait before trying again, or null when trying again cannot help.
     */
    private function retryAfter(Response $response, int $attempt): ?int
    {
        if (! $response->json('error.retryable') || $response->json('error.code') === 'LICENSE_QUOTA_EXCEEDED') {
            return null;
        }

        $wait = (int) ($response->header('Retry-After') ?: 2 ** $attempt);

        return $wait <= config('masaar.retry.max_wait', 60) ? $wait : null;
    }
}
```

### Exception Class

Create `app/Services/Masaar/MasaarException.php`:

```php
<?php

namespace App\Services\Masaar;

use Exception;

class MasaarException extends Exception
{
    public function __construct(
        string $message,
        int $status = 0,
        public readonly ?string $errorCode = null,
        private array $errors = [],
    ) {
        parent::__construct($message, $status);
    }

    /** The field errors, or the context the error carried. */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
```

---

## 5. Submitting Invoices

### 5.1 One call: `POST /api/v1/pipeline/submit`

Drafts the invoice, issues it (number, hash, signature, QR) and files it with
the authority, in one request. It needs the `invoice.submit` scope, the licence
feature for the document's kind, and allowance left for the month.

The body is the invoice as the organization's country takes it - the fields
in [docs/sa/INTEGRATION-GUIDE.md](./sa/INTEGRATION-GUIDE.md) or
[docs/ae/INTEGRATION-GUIDE.md](./ae/INTEGRATION-GUIDE.md) - with these beside
it:

| Field | |
|---|---|
| `org_id` | Required. The licence's organization id |
| `auto_submit` | Whether to file it straight after issuing. `true` unless sent |
| `branch_id` | The branch it comes from, which decides the device that signs it |
| `till_id` | The till that made the sale, one of the organization's EGS units: its counter, chain and certificate issue the invoice |
| `till_number` | Instead of `till_id`: the till's number within `branch_id` |
| `erp_reference_id` | Your own reference, at most 255 characters, returned as sent |
| `invoice_type` | Accepted for `type` |
| `invoice_number` | Optional: without one the invoice is numbered at issue from the organization's sequence |

**Arabic on Saudi invoices.** ZATCA's E-Invoicing Detailed Guidelines require
the invoice data to be in Arabic (p60), notes included (p63); other languages
may stand beside it. So a Saudi invoice carries:

- `description_ar` on every line, in Arabic letters;
- `buyer_name_ar` on a standard (B2B) invoice and on its credit and debit notes;
- `notes`, when sent, containing Arabic, optionally followed by a translation.

A line that names a saved product (`product_id` or `product_code`), or an
invoice that names a saved customer (`customer_id`), takes the Arabic from it
when none is sent. Without the Arabic the invoice is refused with 422, each
missing field named in English and Arabic - unless a platform administrator has
given the organization a grace period, when it is taken and what is missing is
listed in `warnings`. A UAE invoice needs no Arabic.

A standard invoice to a VAT-registered buyer, filed as soon as it is issued:

<!-- example: POST /api/v1/pipeline/submit sa -->
```json
{
  "org_id": "5f0c2a9e-3b7d-4e1a-9c8f-2d6b4a1e7c30",
  "erp_reference_id": "SO-1001",
  "auto_submit": true,
  "invoice_number": "INV-1001",
  "type": "standard",
  "document_type": "invoice",
  "issue_date": "2026-10-15",
  "supply_date": "2026-10-15",
  "currency": "SAR",
  "buyer_name": "Beta Industries",
  "buyer_name_ar": "شركة بيتا للصناعات",
  "buyer_vat_number": "399999999800003",
  "buyer_address": {
    "street": "Olaya Street",
    "building_number": "4321",
    "district": "Al Murooj",
    "city": "Riyadh",
    "postal_code": "11564",
    "country_code": "SA"
  },
  "lines": [
    {
      "description": "Office chair",
      "description_ar": "كرسي مكتب",
      "quantity": 2,
      "unit_code": "H87",
      "unit_price": 450.00,
      "tax_rate": 15,
      "tax_category": "S",
      "discount_amount": 50.00
    }
  ],
  "payment_means_code": "30",
  "notes": "يُرجى السداد خلال ثلاثين يوماً - Payment within thirty days"
}
```

The answer, `201` when every step went through:

<!-- example: response -->
```json
{
  "success": true,
  "message": "Invoice submitted through the pipeline",
  "data": {
    "invoice_id": "9d41c6b2-7a3e-4f0d-8b25-6e1f0c9a3d47",
    "uuid": "9d41c6b2-7a3e-4f0d-8b25-6e1f0c9a3d47",
    "invoice_number": "INV-1001",
    "erp_reference_id": "SO-1001",
    "status": "accepted",
    "compliance_status": "cleared",
    "hash": "...",
    "qr_code": "...",
    "signed_xml": "...",
    "cleared_xml": "...",
    "zatca_response": {"submission_id": "...", "state": "cleared", "clearance_status": "CLEARED", "reporting_status": null},
    "totals": {"subtotal": "850.00", "discount_amount": "0.00", "tax_amount": "127.50", "total": "977.50"},
    "errors": [],
    "warnings": []
  }
}
```

- `compliance_status` is `cleared`, `reported`, `rejected` or `pending`.
- `signed_xml` is the document of record: ZATCA's stamped copy once a standard
  invoice is cleared, otherwise the one Masaar signed. `cleared_xml` is set
  only when ZATCA's copy arrived.
- A standard invoice is cleared within the call, since it may not be given to
  the buyer before. A simplified one is reported from a queue, as ZATCA allows
  up to 24 hours: the answer says `pending`, and `invoice.reported` (or
  `invoice.rejected`) follows by webhook.
- `zatca_response` is null when nothing was filed.

| HTTP | When |
|---|---|
| 201 | Drafted, issued and, if asked, filed |
| 207 | Drafted but not issued, or issued but the filing failed or was rejected: `errors` says which. An issued invoice stays issued; send the same request with the same `Idempotency-Key` to carry on |
| 422 | Refused before anything was drafted: `error.details` names each field |
| 401, 403, 429 | See [Errors](#errors) |

A UAE organization sends its own invoice body to the same endpoint, and gets
the same answer; there is no Arabic to add. For example, with
`erp_reference_id` and its lines beside the rest of the invoice:

<!-- example: POST /api/v1/pipeline/submit ae fragment -->
```json
"erp_reference_id": "SO-7731",
"lines": [
  {"item_name": "Consulting", "quantity": 2, "unit_price": 500, "tax_category": "S", "tax_rate": 5}
]
```

### 5.2 Credit and debit notes

A note is a pipeline request with `document_type` `credit_note` or
`debit_note`, the invoice it corrects in `billing_ref` and the reason in
`adjustment_reason` (BR-KSA-17). A note against a standard invoice carries
`buyer_name_ar` like the invoice:

<!-- example: POST /api/v1/pipeline/submit sa -->
```json
{
  "org_id": "5f0c2a9e-3b7d-4e1a-9c8f-2d6b4a1e7c30",
  "erp_reference_id": "RET-1001",
  "type": "standard",
  "document_type": "credit_note",
  "billing_ref": "INV-1001",
  "adjustment_reason": "إرجاع بضاعة - Goods returned",
  "issue_date": "2026-10-20",
  "buyer_name": "Beta Industries",
  "buyer_name_ar": "شركة بيتا للصناعات",
  "buyer_vat_number": "399999999800003",
  "buyer_address": {
    "street": "Olaya Street",
    "building_number": "4321",
    "district": "Al Murooj",
    "city": "Riyadh",
    "postal_code": "11564",
    "country_code": "SA"
  },
  "lines": [
    {
      "description": "Office chair, returned",
      "description_ar": "كرسي مكتب مُرتجع",
      "quantity": 1,
      "unit_code": "H87",
      "unit_price": 450.00,
      "tax_rate": 15,
      "tax_category": "S"
    }
  ]
}
```

### 5.3 Many invoices: `POST /api/v1/pipeline/batch`

Each invoice in `invoices` is taken exactly as `/pipeline/submit` would take
it - licensed for its kind, validated, drafted, issued and filed on its own -
and answered on its own line. One that fails does not stop the others.

```json
{
  "org_id": "5f0c2a9e-3b7d-4e1a-9c8f-2d6b4a1e7c30",
  "invoices": [
    {"idempotency_key": "invoice-2041", "erp_reference_id": "SO-2041", "type": "simplified", "...": "the rest of the invoice"},
    {"idempotency_key": "invoice-2042", "erp_reference_id": "SO-2042", "type": "simplified", "...": "the rest of the invoice"}
  ]
}
```

- At most 50 invoices a request (the operator can change it).
- One `Idempotency-Key` header cannot name many requests, so each invoice
  carries its own `idempotency_key` in its body, at most 255 characters and
  distinct within the batch. The header is not read here.
- `org_id` is the batch's; each invoice is the licence's organization's.
- The allowance is checked once at the door; each filing then takes its own
  slot. An invoice that finds it used up stays issued and answers 207; send it
  again under the same key when there is allowance.
- Batching is available to every customer, whatever the tier, unless a
  platform administrator has switched it off for the organization (403
  `AUTH_014`).

The answer is `201` when every invoice went through cleanly, `207` when any
did not:

<!-- example: response -->
```json
{
  "success": true,
  "message": "Some invoices were not submitted cleanly. See each result.",
  "data": {
    "results": [
      {"index": 0, "idempotency_key": "invoice-2041", "http_status": 201, "message": "Invoice submitted through the pipeline", "data": {"invoice_id": "...", "compliance_status": "pending", "errors": [], "warnings": []}, "error": null},
      {"index": 1, "idempotency_key": "invoice-2042", "http_status": 422, "message": "Validation failed", "data": null, "error": {"code": "VALIDATION_ERROR", "details": {"lines.0.description_ar": ["Arabic description required (ZATCA: the invoice must be in Arabic) / ..."]}}}
    ],
    "summary": {"total": 2, "succeeded": 1, "failed": 1}
  }
}
```

Each result's `data` is what `/pipeline/submit` answers in its `data`, and
`http_status` the status it would have answered with.

### 5.4 Status: `GET /api/v1/pipeline/status/{invoiceId}`

Needs `compliance.status`. Answers with `invoice_id`, `invoice_number`,
`status`, `type`, `hash`, `qr_code`, `signed_xml`, `cleared_xml`,
`zatca_response`, `totals`, `issue_date`, `created_at` and `updated_at`, for
an invoice of the licence's organization; anyone else's is a 404.

### 5.5 Step by step (Saudi Arabia)

The pipeline's steps are also routes of their own, for a system that drafts
first and files later. These file with ZATCA:

| Endpoint | Does | Answers in `data` |
|---|---|---|
| `POST /api/v1/invoices` | Draft (takes `Idempotency-Key`) | `invoice` |
| `PUT /api/v1/invoices/{invoice}` | Change a draft; an issued invoice is 422 | `invoice` |
| `DELETE /api/v1/invoices/{invoice}` | Discard a draft; an issued invoice is 422 | - |
| `POST /api/v1/compliance/generate/{invoiceId}` | Issue: number, hash, signature, QR | `hash`, `qr_code`, `signed_xml` |
| `POST /api/v1/compliance/validate/{invoiceId}` | Check an issued invoice with ZATCA without filing it | `valid`, `status`, `warnings`, `errors` |
| `POST /api/v1/compliance/submit/{invoiceId}` | File an issued invoice: clearance or reporting | `status`, `warnings` |
| `GET /api/v1/compliance/status/{invoiceId}` | Its status | `invoice_id`, `status`, `hash`, `qr_code`, `zatca_response` |
| `GET /api/v1/invoices` | List, newest first; `?status=` filters | a page, with `meta` |
| `GET /api/v1/invoices/{invoice}` | One invoice with its lines | `invoice` |

Issuing an invoice that is already issued is refused rather than signed again,
since re-signing would fork the hash chain. A UAE organization uses the
pipeline: the four `/api/v1/compliance/*` routes answer it 422 with the code
`JURISDICTION_ENDPOINT_MISMATCH`, and `error.details.use` lists the pipeline
routes its invoices take.

### 5.6 Dashboard

`GET /api/v1/dashboard` (an overview), `GET /api/v1/dashboard/health` (the
checks, among them the certificate the organization signs with, under
`data.checks.certificates`) and `GET /api/v1/dashboard/usage?period=7d|30d|90d`.
Any valid licence may read them.

### 5.7 Schedules: recurring invoices and instalment plans

A schedule makes the customer's invoice to one buyer again each period - a
subscription, a monthly service, a school's terms - or splits one agreed
total into instalments. `POST /api/v1/schedules` (scope `schedule.manage`)
takes how often (`frequency`: `weekly`, `monthly`, `quarterly`, `termly`
with `terms`, or `yearly`),
when it starts and ends, whether each invoice is issued as it is made
(`auto_issue`) or left a draft, `bills_in` (`advance` or `arrears`), and the
invoice as `POST /api/v1/invoices` takes it less its number and dates. The
invoice is checked by the customer's country's rules when the schedule is
made and for every run; each run states its period as its supply period.

<!-- example: POST /api/v1/schedules sa -->
```json
{
  "name": "Beta retainer",
  "frequency": "monthly",
  "start_date": "2026-11-01",
  "occurrences": 12,
  "auto_issue": true,
  "due_days": 30,
  "invoice": {
    "type": "standard",
    "buyer_name": "Beta Industries",
    "buyer_name_ar": "شركة بيتا للصناعات",
    "buyer_vat_number": "399999999800003",
    "buyer_address": { "street": "Olaya Street", "building_number": "4321", "district": "Al Murooj", "city": "Riyadh", "postal_code": "11564", "country_code": "SA" },
    "lines": [{ "description": "Monthly retainer", "description_ar": "اشتراك شهري", "quantity": 1, "unit_price": "1000.00" }]
  }
}
```

`GET /api/v1/schedules/{schedule}` (scope `schedule.read`) answers with its
runs and the next ones as they would be made today. With `schedule.manage`:
`POST /api/v1/schedules/{schedule}/pause`, `/resume`, `/cancel` and `/skip`,
`PUT /api/v1/schedules/{schedule}/prices`, and, under
`/api/v1/schedules/{schedule}/runs/{run}`, `/paid` (an instalment of a plan
delivered up front) and `/rerun` (a period whose draft was discarded). The
webhooks `schedule.run_created` and `schedule.failed` announce each run. The
instalment models and the rules behind them are in the country guides
(docs/sa/INTEGRATION-GUIDE.md).

---

## 6. Service Integration

### Invoice Service

Create `app/Services/Masaar/InvoiceService.php`:

```php
<?php

namespace App\Services\Masaar;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    /**
     * Draft, issue and file the invoice in one call.
     *
     * The Idempotency-Key is the partner invoice's own, so calling this again
     * for the same invoice - a timeout, a queue retry - answers with the first
     * call's invoice and carries on where it stopped.
     */
    public function submit(Invoice $invoice, Company $company): array
    {
        $client = $this->clientFor($company);

        $result = $client->post('/api/v1/pipeline/submit', [
            ...$this->mapInvoiceData($invoice),
            'org_id' => $client->organizationId,
            'erp_reference_id' => (string) $invoice->id,
        ], idempotencyKey: 'invoice-'.$invoice->id)['data'];

        Log::info('Invoice submitted through Masaar', [
            'partner_invoice_id' => $invoice->id,
            'masaar_invoice_id' => $result['invoice_id'],
            'compliance_status' => $result['compliance_status'],
        ]);

        return $result;
    }

    public function status(Invoice $invoice, Company $company): array
    {
        return $this->clientFor($company)->get("/api/v1/pipeline/status/{$invoice->masaar_id}")['data'];
    }

    /**
     * The company's own licence (section 7).
     */
    private function clientFor(Company $company): MasaarClient
    {
        return new MasaarClient($company->masaar_api_key, $company->masaar_api_secret, $company->masaar_org_id);
    }

    /**
     * Map the partner invoice format to Masaar format
     */
    private function mapInvoiceData(Invoice $invoice): array
    {
        return [
            // Invoice identification
            'invoice_number' => $invoice->invoice_number,
            'type' => $this->mapInvoiceType($invoice),
            'document_type' => $this->mapDocumentType($invoice),

            // Dates
            'issue_date' => $invoice->invoice_date->format('Y-m-d'),
            'supply_date' => $invoice->supply_date?->format('Y-m-d'),

            // Currency
            'currency' => $invoice->currency ?? 'SAR',

            // Buyer information. The Arabic name is required on a standard
            // invoice and its notes (ZATCA Detailed Guidelines p60).
            'buyer_name' => $invoice->customer_name,
            'buyer_name_ar' => $invoice->customer_name_ar,
            'buyer_vat_number' => $invoice->customer_vat_number,
            'buyer_address' => $this->mapAddress($invoice),

            // Line items
            'lines' => $this->mapLineItems($invoice),

            // A discount on the whole invoice. The subtotal, VAT and total
            // are computed by Masaar from the lines; they are not sent.
            'discount_amount' => (float) ($invoice->discount ?? 0),

            // Payment
            'payment_means_code' => $this->mapPaymentMethod($invoice),

            // Credit and debit notes: the invoice corrected, and why (BR-KSA-17)
            'billing_ref' => $invoice->original_invoice_number,
            'adjustment_reason' => $invoice->adjustment_reason,

            // In Arabic, with any translation after it (p63)
            'notes' => $invoice->notes,

            // Invoice sub-types
            'is_export' => (bool) ($invoice->is_export ?? false),
            'is_third_party' => (bool) ($invoice->is_third_party ?? false),
            'is_summary' => (bool) ($invoice->is_summary ?? false),
            'is_self_billed' => (bool) ($invoice->is_self_billed ?? false),
        ];
    }

    /**
     * Map invoice type (standard/simplified)
     */
    private function mapInvoiceType(Invoice $invoice): string
    {
        // B2B = standard (requires clearance)
        // B2C = simplified (reporting only)
        if ($invoice->invoice_type === 'B2B' || !empty($invoice->customer_vat_number)) {
            return 'standard';
        }

        return 'simplified';
    }

    /**
     * Map document type (invoice/credit_note/debit_note)
     */
    private function mapDocumentType(Invoice $invoice): string
    {
        return match ($invoice->document_type ?? 'invoice') {
            'credit_note', 'CN' => 'credit_note',
            'debit_note', 'DN' => 'debit_note',
            default => 'invoice',
        };
    }

    /**
     * Map buyer address
     */
    private function mapAddress(Invoice $invoice): ?array
    {
        if (empty($invoice->customer_address)) {
            return null;
        }

        // Send what the buyer's address says and nothing invented: a Saudi
        // buyer's postal code is its real five digits (BR-KSA-67), and a
        // standard invoice needs the street, building number and city.
        if (is_string($invoice->customer_address)) {
            return [
                'street' => $invoice->customer_address,
                'city' => $invoice->customer_city,
                'postal_code' => $invoice->customer_postal_code,
                'country_code' => 'SA',
            ];
        }

        return [
            'street' => $invoice->customer_address['street'] ?? null,
            'building_number' => $invoice->customer_address['building'] ?? null,
            'district' => $invoice->customer_address['district'] ?? null,
            'city' => $invoice->customer_address['city'] ?? null,
            'postal_code' => $invoice->customer_address['postal_code'] ?? null,
            'country_code' => $invoice->customer_address['country'] ?? 'SA',
        ];
    }

    /**
     * Map line items
     */
    private function mapLineItems(Invoice $invoice): array
    {
        return $invoice->items->map(function ($item) {
            return [
                'description' => $item->description ?? $item->item_name,
                // Required on a Saudi invoice, in Arabic letters
                'description_ar' => $item->description_ar,
                'quantity' => (float) $item->quantity,
                'unit_code' => $item->unit ?? 'H87', // UN/ECE Rec 20: H87 is "piece"
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => (float) ($item->vat_rate ?? 15),
                'tax_category' => $this->mapTaxCategory($item),
                'exempt_code' => $item->exemption_code,
                'exempt_reason' => $item->exemption_reason,
                'discount_amount' => (float) ($item->discount ?? 0),
            ];
        })->toArray();
    }

    /**
     * Map tax category
     *
     * ZATCA decides the category by the exemption code (BR-KSA-CL-04), and
     * refuses a code under another category's.
     */
    private function mapTaxCategory($item): string
    {
        $rate = (float) ($item->vat_rate ?? 15);

        if ($rate > 0) {
            return 'S'; // Standard rated
        }

        return match ($item->exemption_code) {
            'VATEX-SA-29', 'VATEX-SA-29-7', 'VATEX-SA-30' => 'E', // Exempt: financial services, life insurance, real estate
            'VATEX-SA-OOS' => 'O', // Out of scope
            // Every other code is zero-rated: exports (VATEX-SA-32, -33),
            // international transport (-34-1 to -34-5), medicines (-35),
            // qualifying metals (-36), private education and healthcare
            // (EDU, HEA) and the rest. A zero-rated line without a code is
            // refused: every Z, E or O line names one.
            default => 'Z',
        };
    }

    /**
     * Map payment method to ZATCA code
     */
    private function mapPaymentMethod(Invoice $invoice): string
    {
        return match ($invoice->payment_method ?? 'cash') {
            'cash' => '10',
            'check', 'cheque' => '20',
            'bank_transfer', 'transfer' => '30',
            'card', 'credit_card' => '48',
            default => '10',
        };
    }
}
```

### Service Provider

Create `app/Providers/MasaarServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\Masaar\MasaarClient;
use Illuminate\Support\ServiceProvider;

class MasaarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // For a single customer configured in .env; with several, build a
        // client per company as InvoiceService::clientFor() does.
        $this->app->bind(MasaarClient::class, fn () => MasaarClient::fromConfig());
    }
}
```

Register in `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\MasaarServiceProvider::class,
],
```

---

## 7. Database Changes

### Migration for Masaar Integration

```bash
php artisan make:migration add_masaar_fields
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Masaar reference
            $table->string('masaar_id')->nullable()->after('id');
            $table->index('masaar_id');

            // What the pipeline answered
            $table->string('zatca_status')->default('pending')->after('status');
            $table->string('zatca_invoice_hash')->nullable();
            $table->text('zatca_qr_code')->nullable();
            $table->longText('zatca_signed_xml')->nullable();
            $table->json('zatca_warnings')->nullable();
            $table->json('zatca_errors')->nullable();
            $table->timestamp('zatca_submitted_at')->nullable();
            $table->timestamp('zatca_cleared_at')->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('description_ar')->nullable();
        });

        // Each company's own licence
        Schema::table('companies', function (Blueprint $table) {
            $table->uuid('masaar_org_id')->nullable()->after('id');
            $table->string('masaar_api_key')->nullable();
            $table->text('masaar_api_secret')->nullable(); // encrypted cast on the model
            $table->index('masaar_org_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['masaar_id']);
            $table->dropColumn([
                'masaar_id',
                'zatca_status',
                'zatca_invoice_hash',
                'zatca_qr_code',
                'zatca_signed_xml',
                'zatca_warnings',
                'zatca_errors',
                'zatca_submitted_at',
                'zatca_cleared_at',
            ]);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('description_ar');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['masaar_org_id']);
            $table->dropColumn(['masaar_org_id', 'masaar_api_key', 'masaar_api_secret']);
        });
    }
};
```

On the `Company` model, keep the secret encrypted at rest:

```php
protected $casts = [
    'masaar_api_secret' => 'encrypted',
];

protected $hidden = ['masaar_api_secret'];
```

---

## 8. Invoice Submission Flow

### Queue Job

Submit from a queue, so the partner system's own request does not wait for
ZATCA. Create `app/Jobs/SubmitInvoiceToMasaar.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Masaar\InvoiceService;
use App\Services\Masaar\MasaarException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SubmitInvoiceToMasaar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public Invoice $invoice
    ) {}

    /**
     * Safe to retry: InvoiceService sends the invoice's own Idempotency-Key,
     * so a retry finishes the first attempt instead of filing a second one.
     */
    public function handle(InvoiceService $masaar): void
    {
        try {
            $result = $masaar->submit($this->invoice, $this->invoice->company);
        } catch (MasaarException $e) {
            $this->invoice->update([
                'zatca_status' => 'failed',
                'zatca_errors' => $e->getErrors() ?: [$e->getMessage()],
            ]);

            // 422 and 403 will not change by trying again; fix the data or the licence.
            if (in_array($e->getCode(), [403, 422], true)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $this->invoice->update([
            'masaar_id' => $result['invoice_id'],
            'zatca_status' => $result['compliance_status'], // cleared, reported, rejected, pending
            'zatca_invoice_hash' => $result['hash'],
            'zatca_qr_code' => $result['qr_code'],
            'zatca_signed_xml' => $result['signed_xml'],
            'zatca_warnings' => $result['warnings'],
            'zatca_errors' => $result['errors'] ?: null,
            'zatca_submitted_at' => now(),
            'zatca_cleared_at' => $result['compliance_status'] === 'cleared' ? now() : null,
        ]);

        // 207: issued, but the filing failed. The same call resumes it.
        if ($result['errors'] !== [] && $result['compliance_status'] === 'pending') {
            Log::warning('Masaar: invoice issued but not filed; retrying', [
                'invoice_id' => $this->invoice->id,
                'errors' => $result['errors'],
            ]);

            $this->release($this->backoff[$this->attempts() - 1] ?? 3600);
        }
    }
}
```

Usage in the partner's controller:

```php
SubmitInvoiceToMasaar::dispatch($invoice);

return response()->json([
    'invoice' => $invoice,
    'message' => 'Invoice created. ZATCA submission in progress.',
], 202);
```

A simplified invoice comes back `pending` and is reported from Masaar's queue;
its final status arrives by webhook (section 9), or from
`InvoiceService::status()`.

---

## 9. Webhook Configuration

Signatures, retries and verification in other languages are in
[WEBHOOKS.md](./WEBHOOKS.md).

### Register the Webhook

With the licence key (scope `webhook.manage`, feature `webhook_notifications`):

```bash
curl -X POST https://api.example.com/api/v1/webhooks \
  -H "X-API-Key: $MASAAR_API_KEY" \
  -H "X-API-Secret: $MASAAR_API_SECRET" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "url": "https://partner.example.com/api/webhooks/masaar",
    "events": ["*"]
  }'
```

`"*"`, or a list that names `invoice.warning` beside `invoice.cleared` and
`invoice.reported`: an invoice ZATCA accepts with warnings is announced as
`invoice.warning` instead ([WEBHOOKS.md](./WEBHOOKS.md#events)).
The URL is https and public. The answer carries the webhook's `secret` in
`data.webhook.secret`, once; store it as `MASAAR_WEBHOOK_SECRET`.
`GET`, `PUT` and `DELETE /api/v1/webhooks/{webhook}` read, change and remove
it; `GET /api/v1/webhooks` lists them.

### Webhook Controller

Create `app/Http/Controllers/MasaarWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MasaarWebhookController extends Controller
{
    public function handle(Request $request)
    {
        if (! $this->verifySignature($request)) {
            Log::warning('Invalid Masaar webhook signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // A retry carries the same X-Webhook-ID: act on it once.
        $seen = 'masaar-webhook:'.$request->header('X-Webhook-ID');

        if (Cache::has($seen)) {
            return response()->json(['message' => 'Already processed']);
        }

        $event = $request->input('event');
        $data = $request->input('data', []);

        match ($event) {
            'invoice.cleared' => $this->update($data, ['zatca_status' => 'cleared', 'zatca_cleared_at' => now()]),
            'invoice.reported' => $this->update($data, ['zatca_status' => 'reported']),
            // Accepted, with warnings: cleared if it carries a clearance status
            'invoice.warning' => $this->update($data, [
                'zatca_status' => ($data['clearance_status'] ?? null) ? 'cleared' : 'reported',
                'zatca_warnings' => $data['warnings'] ?? [],
            ]),
            'invoice.rejected' => $this->update($data, ['zatca_status' => 'rejected', 'zatca_errors' => $data['errors'] ?? []]),
            'certificate.expiring' => Log::warning('ZATCA certificate expiring', [
                'org_id' => $data['org_id'],
                'expiry_date' => $data['expiry_date'],
                'days_remaining' => $data['days_remaining'],
            ]),
            default => null,
        };

        Cache::put($seen, true, now()->addDays(2));

        return response()->json(['success' => true]);
    }

    /**
     * sha256= and the hex HMAC-SHA256 of the raw body, keyed with the
     * webhook's secret. The timestamp is inside the signed body.
     */
    private function verifySignature(Request $request): bool
    {
        $signature = (string) $request->header('X-Webhook-Signature');
        $secret = (string) config('masaar.webhook.secret');

        if ($signature === '' || $secret === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        $fresh = abs(now()->diffInSeconds($request->input('timestamp'))) <= 300;

        return hash_equals($expected, $signature) && $fresh;
    }

    private function update(array $data, array $changes): void
    {
        Invoice::where('masaar_id', $data['invoice_id'] ?? null)->update($changes);
    }
}
```

### Routes

Add to `routes/api.php`, which carries no session or CSRF check, outside any
authentication group:

```php
use App\Http\Controllers\MasaarWebhookController;

// Verified by signature, not by session
Route::post('/webhooks/masaar', [MasaarWebhookController::class, 'handle']);
```

Register the URL this route has in the partner's application (under `/api`
by default) as the webhook's `url`.

---

## 10. Testing & Validation

### Test Configuration

Create `tests/Feature/MasaarIntegrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\Masaar\InvoiceService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MasaarIntegrationTest extends TestCase
{
    public function test_submits_with_licence_key_and_idempotency_key()
    {
        Http::fake(['*/api/v1/pipeline/submit' => Http::response(['success' => true, 'data' => [
            'invoice_id' => 'masaar-id', 'compliance_status' => 'cleared', 'errors' => [], 'warnings' => [],
        ]], 201)]);

        $company = Company::factory()->create([
            'masaar_org_id' => '5f0c2a9e-3b7d-4e1a-9c8f-2d6b4a1e7c30',
            'masaar_api_key' => 'cp_test_key',
            'masaar_api_secret' => 'secret',
        ]);
        $invoice = Invoice::factory()->for($company)->hasItems(1)->create();

        app(InvoiceService::class)->submit($invoice, $company);

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'cp_test_key')
            && $request->hasHeader('Idempotency-Key', 'invoice-'.$invoice->id)
            && $request['org_id'] === $company->masaar_org_id
            && filled($request['lines'][0]['description_ar']));
    }
}
```

### Sandbox Testing Checklist

1. **Environment Setup** - a sandbox licence (`cp_test_` key) against
   `MASAAR_BASE_URL=https://sandbox.example.com`.
2. **Standard invoice (B2B)** - with `buyer_name_ar` and `description_ar`;
   expect `compliance_status` `cleared`.
3. **Simplified invoice (B2C)** - with `description_ar`; expect `pending`, then
   `invoice.reported` by webhook.
4. **Credit note** - `document_type` `credit_note`, `billing_ref`,
   `adjustment_reason`, `buyer_name_ar` for a standard one.
5. **Zero-rated line** - `tax_rate` 0 with `exempt_code` `VATEX-SA-HEA` and its
   `exempt_reason`, to a named buyer.
6. **Retry** - send the same request twice with one `Idempotency-Key`; expect
   one invoice.
7. **Batch** - two invoices, one with a line missing `description_ar`; expect
   207 with one 201 and one 422 line.

---

## 11. Migration Checklist

### Phase 1: Setup
- [ ] Receive a sandbox licence key, secret and organization id
- [ ] Create configuration file and environment variables
- [ ] Run database migrations, add Arabic fields to customers and items
- [ ] Register the service provider

### Phase 2: Development
- [ ] Create MasaarClient and InvoiceService
- [ ] Send an Idempotency-Key with every submission
- [ ] Queue submissions; handle 207, 422 and 429
- [ ] Webhook handler with signature check and duplicate check

### Phase 3: Onboarding (the customer's administrator)
- [ ] Complete the company page, Arabic name included
- [ ] Obtain the OTP from the Fatoora portal
- [ ] Connect to ZATCA on the portal's ZATCA page

### Phase 4: Testing
- [ ] B2B clearance, B2C reporting, credit and debit notes
- [ ] Webhooks, retries, batch

### Phase 5: Production
- [ ] Receive the production licence (`cp_live_` key) and switch to the production URL
- [ ] Register the production webhook
- [ ] Monitor first production invoices and set up error alerts

---

## 12. Troubleshooting

### Common Issues

#### 1. "Invalid API key" or "Invalid API secret"
```
HTTP 401  {"error": {"code": "LICENSE_INVALID_KEY"}}
```
**Solution**: send both `X-API-Key` and `X-API-Secret`, without extra spaces.
Each environment issues its own licences, so a sandbox key is unknown to
production and the other way round. After a rotation only the new secret works.

#### 2. "Organization context is required" or "org_id does not match"
**Solution**: send the licence's organization id as `org_id`. A licence not yet
linked to an organization cannot submit; ask the platform team to link it.

#### 3. 207 with `status` `draft`: drafted, not issued
**Solution**: `errors` says why. Most often the organization is not connected
to ZATCA yet: its administrator connects it in the portal. Then send the same
request with the same `Idempotency-Key` to issue and file the draft.

#### 4. "Invoice validation failed"
```
HTTP 422  {"error": {"code": "VALIDATION_ERROR", "details": {"lines.0.description_ar": ["Arabic description required (ZATCA: the invoice must be in Arabic) / ..."]}}}
```
**Solution**: `details` names each field. Check:
- Arabic: `description_ar` on each line, `buyer_name_ar` on a standard invoice and its notes, Arabic in `notes`
- VAT number format (15 digits starting and ending with 3)
- Address completeness
- Line item tax categories and exemption codes

#### 5. Webhook not receiving events
**Solution**:
1. Verify the webhook URL is public and https
2. Check the webhook secret matches
3. Verify the HTTPS certificate is valid
4. Check the licence includes `webhook_notifications` and the `webhook.manage` scope
5. An endpoint is switched off after ten failed deliveries in a row with nothing delivered for a day: switch it back on with `PUT /api/v1/webhooks/{webhook}` and `"is_active": true`

#### 6. "Feature not available in your license tier"
```
HTTP 403  {"error": {"code": "LICENSE_FEATURE_UNAVAILABLE", "details": {"feature": "standard_invoices"}}}
```
**Solution**: `details.feature` names the missing one ([Licence features](#licence-features));
`GET /api/license/features/{feature}` answers whether the licence has it. Ask
the platform team to raise the tier or add the feature.

#### 7. "Access denied. Required scope ..."
```
HTTP 403  {"error": {"code": "AUTH_SCOPE_DENIED", "details": {"required_scope": "compliance.status"}}}
```
**Solution**: the licence lacks the scope ([Scopes](#scopes)); ask the platform team to add it.

#### 8. "Quota exceeded for invoices_per_month"
```
HTTP 429  {"error": {"code": "LICENSE_QUOTA_EXCEEDED", "details": {"limit_type": "invoices_per_month", "limit": 1000, "current": 1000}}}
```
**Solution**: nothing was done. Ask the platform team to raise the allowance,
or wait for the next month.

### Logging

Log Masaar's `error.code` and `error.details` with the partner invoice id; the
`X-Request-ID` header, when you send one, is recorded against each call.

### Support Contacts

- **Questions and licences**: shamil3ilm@gmail.com
- **Security vulnerabilities**: see [SECURITY.md](../SECURITY.md); do not report them in public

---

## Changes for integrators

The API now follows the official rules more closely. What it refuses, and
what to send instead:

| Refused | Rule | Send instead |
|---|---|---|
| A Saudi invoice without Arabic: a line without `description_ar`, a standard invoice or its note without `buyer_name_ar`; `notes`, `adjustment_reason`, `payment_terms` or a charge's `reason` without Arabic | ZATCA E-Invoicing Detailed Guidelines p60, p63 | The Arabic, in Arabic letters; a translation may follow it. Or name a saved product (`product_id`, `product_code`) or customer (`customer_id`) whose Arabic name is filled in |
| A Saudi payment means outside ZATCA's list, such as `99` | BR-KSA-16 | A listed code; the error names them all |
| A Saudi currency outside ZATCA's ISO 4217:2005 list, such as `VES` | BR-KSA-CL-01 | A listed code; the error names them all |
| A buyer VAT number on a Saudi export | BR-KSA-46 | `buyer_id` with `buyer_id_scheme`, such as `OTH` |
| A Saudi government invoice without its contract | BR-KSA-92 | `is_government: true` with `contract_reference` |
| A UAE payment means outside PINT AE's list, such as `42` or `48` | IBR-CL-16 | One of `1`, `10`, `20`, `21`, `30`, `49`, `54`, `55`, `68`: `30` for a transfer to a bank account, `54` or `55` for a card |
| A UAE currency outside PINT AE's ISO 4217 list | IBR-CL-04 | A listed code; the error names them all |
| A till sale time more than 30 seconds in the future | BR-KSA-04 | The till's time with its UTC offset |
| Two exemption codes in one tax category on one Saudi invoice, such as an export (`VATEX-SA-32`) and its freight (`VATEX-SA-34-1`), both `Z`; or a seller-worded code (`VATEX-SA-OOS`, `DIPLOMAT`, `DUTYFREE`) worded two ways | ZATCA XML Implementation Standard v1.2 §8.4: one VAT breakdown per category, and the reason and its words (BT-120, BT-121) are stated only there | One invoice per exemption code, or per wording, in that category; the same wording on several lines is fine. A UAE invoice may mix them: each line states its own (IBR-167-AE) |

What is no longer refused:

- The lines of a Saudi export may carry any category and exemption code
  ZATCA accepts, such as international transport (`VATEX-SA-34-1` to
  `34-5`) and standard-rated services, not only `VATEX-SA-32` and `33` -
  one code per category on each invoice.
- A till's late sale is issued however late it is sent. A Saudi invoice is
  dated when Masaar issues it; the sale time is its supply date. Send the
  sale time as `sold_at`; `issued_at` is still accepted with the same meaning.
- Batch submission (`POST /api/v1/pipeline/batch`) needs no particular
  licence tier.

The warnings ZATCA accepts an invoice with, such as `BR-KSA-F-13`, are
listed in `warnings` as `code: message`: in the answer to
`POST /api/compliance/sa/submit/{id}` and to the pipeline (a replay of the
same request too), and on `GET /api/invoices/{id}` and
`GET /api/compliance/sa/status/{id}` afterwards. They do not stop the invoice.

An invoice ZATCA rejects names each rule it broke under `rejections`, as
`{"code": "BR-KSA-37", "category": "KSA", "message": "..."}`: in the error
`details` of `POST /api/compliance/sa/submit/{id}`, in the pipeline's answer,
and on `GET /api/invoices/{id}` and `GET /api/compliance/sa/status/{id}`.

Send `H87` for a piece. `PCE` is still accepted and sent as `H87`.

---

## Appendix A: API Reference

All under `/api/v1`, with `X-API-Key` and `X-API-Secret`.

### Pipeline

| Endpoint | Method | Scope | Description |
|----------|--------|-------|-------------|
| `/api/v1/pipeline/submit` | POST | `invoice.submit` | Draft, issue and file one invoice |
| `/api/v1/pipeline/batch` | POST | `invoice.submit` | The same for up to 50, each on its own |
| `/api/v1/pipeline/status/{invoiceId}` | GET | `compliance.status` | An invoice's state and documents |

### Invoices and compliance

| Endpoint | Method | Scope | Description |
|----------|--------|-------|-------------|
| `/api/v1/invoices` | POST | `invoice.submit` | Create a draft |
| `/api/v1/invoices` | GET | `invoice.read` | List invoices |
| `/api/v1/invoices/{invoice}` | GET | `invoice.read` | Get invoice |
| `/api/v1/invoices/{invoice}` | PUT | `invoice.submit` | Change a draft |
| `/api/v1/invoices/{invoice}` | DELETE | `invoice.cancel` | Discard a draft |
| `/api/v1/compliance/generate/{invoiceId}` | POST | `invoice.submit` | Issue: number, sign, hash and QR |
| `/api/v1/compliance/validate/{invoiceId}` | POST | `invoice.submit` | Validate an issued invoice with ZATCA |
| `/api/v1/compliance/submit/{invoiceId}` | POST | `invoice.submit` | Submit to ZATCA |
| `/api/v1/compliance/status/{invoiceId}` | GET | `compliance.status` | Check status |

### Webhooks

| Endpoint | Method | Scope | Description |
|----------|--------|-------|-------------|
| `/api/v1/webhooks` | POST | `webhook.manage` | Register webhook; answers its secret once |
| `/api/v1/webhooks` | GET | `webhook.manage` | List webhooks |
| `/api/v1/webhooks/{webhook}` | GET | `webhook.manage` | Get webhook |
| `/api/v1/webhooks/{webhook}` | PUT | `webhook.manage` | Change `url`, `events`, `is_active` |
| `/api/v1/webhooks/{webhook}` | DELETE | `webhook.manage` | Delete webhook |

### Dashboard and licence

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/dashboard` | GET | Overview |
| `/api/v1/dashboard/health` | GET | Health checks, the signing certificate among them |
| `/api/v1/dashboard/usage` | GET | Usage over `?period=7d`, `30d` or `90d` |
| `/api/license` | GET | The licence: tier, status, features, limits |
| `/api/license/quotas` | GET | Allowances used and remaining |
| `/api/license/usage` | GET | The month's usage by day |
| `/api/license/health` | GET | Expiry and allowance warnings |
| `/api/license/features/{feature}` | GET | Whether the licence has a feature |

Organization registration, ZATCA onboarding and branches are the customer
administrator's, in the portal; they are not on the licence-key API.

---

## Appendix B: Multi-Currency Support

Per ZATCA official guidelines, foreign currency invoices are supported:

| Field | Required | Description |
|-------|----------|-------------|
| `currency` | Yes | Document currency (SAR, USD, EUR, etc.) |
| `exchange_rate` | If non-SAR | Exchange rate to SAR |

**ZATCA Requirements:**
- `DocumentCurrencyCode` (BT-5): Can be foreign currency
- `TaxCurrencyCode` (BT-6): Always SAR for VAT reporting
- Two `TaxTotal` elements: BT-110 (doc currency), BT-111 (SAR)

**Example:**
```php
$invoiceData = [
    'currency' => 'USD',
    'exchange_rate' => 3.75,
    'lines' => [['description' => 'Consulting', 'description_ar' => 'خدمات استشارية', 'quantity' => 1, 'unit_price' => 1000.00, 'tax_rate' => 15]],
    // Computed by Masaar: subtotal 1000.00 USD, VAT 150.00 USD (562.50 SAR), total 1150.00 USD.
];
```

---

## Appendix C: ZATCA Error Codes

Some of the rules ZATCA's validator applies, as its Schematron states them.
Masaar refuses most of them before an invoice is drafted, naming the rule in
`error.details`.

| Code | Rule | Resolution |
|------|------|------------|
| BR-KSA-04 | The issue date is not after today | Masaar dates an invoice when it issues it |
| BR-KSA-07 | An export is not self-billed | Send `is_export` or `is_self_billed`, not both |
| BR-KSA-16 | The payment means is a UNTDID 4461 code ZATCA accepts | Send one of 1-68, 70, 74-78, 91-97 or ZZZ, e.g. `10` cash, `30` credit transfer |
| BR-KSA-17 | A credit or debit note gives its reason | Send `adjustment_reason` |
| BR-KSA-31 | A simplified invoice is not self-billed or an export | Send exports and self-billed invoices as `standard` |
| BR-KSA-40 | The seller VAT number is 15 digits, starting and ending with 3 | Correct the organization's VAT number |
| BR-KSA-46 | An export names no buyer VAT number | Identify the buyer by `buyer_id` with `buyer_id_scheme`, e.g. `OTH` |
| BR-KSA-92 | A government (B2G) invoice names its contract | Send `contract_reference` with `is_government` |
| BR-KSA-CL-01 | The currency is an ISO 4217:2005 code | The error lists the codes ZATCA takes |

---

## Appendix D: Prices Including VAT

Unit prices can be sent with or without VAT in them.

| Field | Where | Description |
|-------|-------|-------------|
| `prices_include_vat` | Organization (the portal's company page) | The default for its invoices. `false` unless set. |
| `prices_include_vat` | Invoice | Overrides the organization's default for this invoice. Applies to `unit_price` and `discount_amount`. |

With VAT included, the customer's figures are the ones kept exact: each line's total and the invoice total are what was sent. The net ZATCA requires is backed out of them, per tax category.

```php
// Three at 10.00, VAT included: the customer pays 30.00.
'prices_include_vat' => true,
'lines' => [['description' => 'Notebook', 'description_ar' => 'دفتر ملاحظات', 'quantity' => 3, 'unit_price' => 10.00, 'tax_rate' => 15]],
// Stored and returned: subtotal 26.09, tax_amount 3.91, total 30.00.
// The line's unit_price is the net (8.70) and net_amount the exact net (26.09).
```

The VAT is the fraction 15/115 of the VAT-inclusive amount, as in ZATCA's Guideline for Tax Invoicing and Records (section 4.2.4, Example 6): 1,255.00 including VAT is 163.70 VAT on 1,091.30.

Occasionally no rounded net and rounded VAT add up to the gross. A single item at 10.00 is one case: a net of 8.70 with its own VAT is 10.01. The total stays 10.00 and the VAT is 1.30. That halalah is within the tolerance ZATCA's validation allows for a category's VAT (one halalah per invoice line), so the invoice is accepted without errors or warnings.

On a printed receipt, an invoice priced this way heads its price column "Unit price (incl. VAT)", as in the model simplified invoice ZATCA publishes.

---

*Document Version: 2.0*
*Masaar API Version: v1*

**Changelog:**
- v2.0: The partner API is the licence-key API under `/api/v1`: authentication, scopes, allowances, idempotency, the pipeline and batch, webhooks; Arabic on Saudi invoices
- v1.1: Added multi-branch EGS support, foreign currency invoices, branch API endpoints
