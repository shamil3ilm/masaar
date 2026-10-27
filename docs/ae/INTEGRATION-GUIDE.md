# UAE e-invoicing: API integration guide

This guide covers the `/api/compliance/ae` endpoints, the service provider
callbacks, and the operator commands. For background, read
[README.md](../compliance/ae/README.md) first. For the rules behind each refusal, see
[RULES.md](../compliance/ae/RULES.md). Field lists are generated into
[openapi.yaml](../openapi.yaml).

**Base URL:** your deployment, for example `https://api.example.com`.

**Authentication:** a JWT bearer token from `POST /api/auth/login`, sent as
`Authorization: Bearer <token>`. The token's organization is the one every
call acts for. Another organization's invoice or document answers `404`.

**Availability:** every route below answers as follows when it is not
available:

- `403` for an organization that is not registered in the UAE (country `AE`).
- `422 JURISDICTION_UNAVAILABLE`, for the routes that start new work
  (`POST /invoices` and `POST /invoices/{invoice}/send`), while the UAE is
  switched off on this deployment: by a platform admin on the console's
  Countries page, or by default (`PEPPOL_AE_ENABLED=false`). The read routes
  stay open, so documents already sent can still be followed.

**Split billing.** A sale billed to a patient with an insurer's share is not
offered in the UAE. The invoice to the patient would be a sale to a consumer, and
UAE e-invoicing leaves those out: "Any supplies to or from natural persons
who are not in Business are not within the scope of Electronic Invoicing"
(UAE Electronic Invoicing Guidelines v1.1, §6.2, p14). Invoice the insurer's
share to the insurer as an ordinary tax invoice. PINT AE has no transaction
flag for a third party paying (BTAE-02). `POST /api/invoices/splits` answers
`422` with the reason under `split`.

## Responses and errors

Every JSON answer uses the platform's envelope.

```json
{ "success": true, "message": "Invoice issued", "data": { "...": "..." } }
```

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The invoice was not issued.",
    "category": "validation",
    "retryable": false,
    "details": { "problems": ["Buyer electronic address is missing."] }
  }
}
```

| Status | `error.code` | When |
|---|---|---|
| `401` | `UNAUTHORIZED` | The token is missing or invalid |
| `403` | `FORBIDDEN` | The organization is not a UAE one, or the role is too low: drafting and sending need a writer, and the seller profile needs an administrator |
| `404` | `NOT_FOUND` | UAE e-invoicing is off on this deployment, or the record is not this organization's |
| `422` | `VALIDATION_ERROR` | The request failed validation, with `error.details` mapping each field to its messages. Or the invoice cannot be sent, with `error.details.problems` listing every problem |
| `429` | `RATE_LIMITED` | Too many requests. `retryable` is `true` |

Lists use the paginated form, `{ "success": true, "data": [...], "meta": {
"current_page", "last_page", "per_page", "total" } }`. Use `per_page` to set
the page size, which defaults to 15 and is capped at 100.

## 1. Seller details

An administrator saves the details that every document carries. The first
save creates the organization's active UAE compliance profile. Later saves
change only the fields that are sent.

```http
PUT /api/compliance/ae/profile
Authorization: Bearer <token>
Content-Type: application/json

{
  "name": "Gulf Trading LLC",
  "street": "Al Wasl Road",
  "city": "Dubai",
  "emirate": "DXB",
  "trn": "100123456700003",
  "endpoint_id": "0235:1001234567",
  "legal_registration_type": "TL",
  "legal_registration_id": "CN-1234567",
  "legal_registration_authority": "Department of Economy and Tourism",
  "payment_account": "AE070331234567890123456"
}
```

| Field | Notes |
|---|---|
| `name`, `street`, `city`, `emirate` | Required. `emirate` is one of `AUH`, `DXB`, `SHJ`, `UAQ`, `FUJ`, `AJM`, `RAK`. Optional: `name_ar`, `building_number`, `additional_street`, `district`, `postal_code` |
| `trn` | The 15-digit TRN, for a seller registered for VAT. It must not be held by another organization |
| `tin` | The 10-digit TIN. Required when there is no TRN. If you send a TRN without a TIN, the TIN is set from the TRN |
| `endpoint_id` | Required. The participant identifier your service provider issued, as `0235:` followed by 10 digits |
| `legal_registration_type`, `legal_registration_id` | Required. The type is one of `TL`, `EID`, `PAS`, `CD` |
| `legal_registration_authority` | Required for `TL` (the issuing authority) and `PAS` (the issuing country, as two letters) |
| `payment_account` | The IBAN that buyers pay into. Required to send an invoice with payment means `30` (credit transfer) |

A successful save answers `200` with `data.organization` and `data.settings`
(the stored `trn`, `tin`, `endpoint_id`, `emirate`, `legal_registration_*`
and `payment_account` values).

`PUT /api/organizations/{id}` takes the same fields for a UAE organization,
each checked only when sent, and saves them the same way. It answers with
`data.organization` only.

## 2. Draft an invoice

<!-- example: POST /api/compliance/ae/invoices ae -->
```http
POST /api/compliance/ae/invoices
Authorization: Bearer <token>
Content-Type: application/json

{
  "document_type": "invoice",
  "document_category": "tax",
  "issue_date": "2026-10-10",
  "due_date": "2026-11-09",
  "currency": "AED",
  "payment_means_code": "30",
  "buyer_name": "Noor Electronics LLC",
  "buyer_vat_number": "100765432100003",
  "buyer_address": { "street": "Khalifa Street", "city": "Abu Dhabi", "country_code": "AE" },
  "buyer_endpoint": "0235:1007654321",
  "buyer_subdivision": "AUH",
  "transaction_flags": "00000000",
  "lines": [
    {
      "item_name": "Consulting",
      "description": "Consulting services, October",
      "quantity": 2,
      "unit_code": "DAY",
      "unit_price": 500,
      "tax_category": "S",
      "tax_rate": 5
    }
  ]
}
```

The answer is `201`, with `data.invoice` (status `draft`, its lines, and the
stored totals: here `subtotal` `1000.00`, `tax_amount` `50.00` and `total`
`1050.00`). A draft has no number yet unless you send `invoice_number`.

### Invoice fields

| Field | Required | Notes |
|---|---|---|
| `document_type` | Yes (default `invoice`) | `invoice` or `credit_note`. There is no debit note |
| `document_category` | Yes (default `tax`) | `tax`, `commercial` or `self_billing` |
| `issue_date` | Yes | |
| `due_date` | Except on a credit note or deemed supply | On or after `issue_date` |
| `supply_date`, `supply_end_date` | For a summary invoice | `supply_date` becomes the VAT point date when it is earlier than `issue_date` |
| `currency` | Yes (default `AED`) | ISO 4217 code |
| `exchange_rate` | When `currency` is not `AED` | The rate to AED. Amounts in AED are computed from it |
| `payment_means_code` | | UNCL 4461 code, for example `30` for a credit transfer |
| `invoice_number` | | Leave it out and a number is allocated from the organization's sequence when the invoice is sent |
| `type` | | Only `standard`. The UAE has no simplified invoice |
| `discount_amount`, `notes` | | A document-level discount, which may not exceed the lines |
| `charges` | | Charges on the whole invoice, such as delivery. See [Discounts and charges](#discounts-and-charges) |
| `payment_terms` | | The payment terms in words, up to 1000 characters (IBT-020) |
| `buyer_reference`, `order_reference`, `contract_reference` | | The buyer's reference, purchase order and contract, up to 127 characters each (IBT-010, IBT-013, IBT-012) |

### Buyer fields

On a self-billed document the buyer fields describe the **seller**, because
the organization is the buyer.

| Field | Required | Notes |
|---|---|---|
| `buyer_name` | Yes | `buyer_name_ar` is optional |
| `buyer_vat_number` | For a buyer in the UAE on a tax document, and for any reverse-charge line | The buyer's TRN |
| `buyer_address` | Yes | `street`, `city` and `country_code` are required (default `AE`). `building_number` and `postal_code` are optional |
| `buyer_subdivision` | Yes | The emirate code for a buyer in the UAE, or the region for a buyer abroad |
| `buyer_endpoint` | Except on a deemed supply or an export | `0235:` plus 10 digits, a reserved endpoint (see [RULES.md](../compliance/ae/RULES.md#seller-and-buyer-identity)), or another Peppol scheme, for example `9930:...` |
| `buyer_legal_registration` | On a commercial document | `{ "type": "TL", "id": "...", "authority": "..." }` |

#### Saved customers

The portal's customers keep two UAE buyer fields, which its UAE invoice form
fills in when a customer is picked. A customer is saved from the
**Customers** page, or by ticking **Save this buyer to Customers** on the UAE
invoice form. There is no customer API.

| Customer field | Invoice field | Rule |
|---|---|---|
| `peppol_endpoint` | `buyer_endpoint` | `0235:` followed by 10 digits, as the buyer's service provider issued it. The reserved `0235:9900000097`, `0235:9900000098` and `0235:9900000099` have that shape too, and picking such a customer selects that reserved address on the form. An endpoint in another scheme is not saved |
| `subdivision` | `buyer_subdivision` | For a customer in the UAE, one of `AUH`, `DXB`, `SHJ`, `UAQ`, `FUJ`, `AJM`, `RAK`. For a customer abroad, the region, up to 50 characters |

### Scenario and credit-note fields

| Field | When |
|---|---|
| `transaction_flags` | Always (default `00000000`). Eight characters of `0` or `1`, in this order: free zone, deemed supply, margin scheme, summary, continuous supply, disclosed agent, e-commerce, export |
| `beneficiary_id` | Free zone |
| `principal_id` | Disclosed agent. The principal's TRN |
| `delivery_address` | `{ street, city, subdivision, country_code, postal_code }`. If it is missing on an export or an e-commerce supply, the buyer's address is used |
| `billing_ref` | A credit note: the invoice number it corrects. Not needed when `credit_reason` is `VD` |
| `credit_reason` | A tax credit note: `DL8.61.1.A` to `DL8.61.1.E`, or `VD` |

### Line fields

| Field | Required | Notes |
|---|---|---|
| `item_name` | Yes | `description` defaults to it |
| `quantity` | Yes | Up to 3 decimals, and more than 0 |
| `unit_price` | Yes | The net price, up to 2 decimals. UAE prices never include VAT |
| `unit_code` | | UN/ECE recommendation 20 code |
| `tax_category` | Yes | `S`, `Z`, `E`, `O`, `AE` or `N`. A commercial document allows only `E`, `O` and `Z` |
| `tax_rate` | | Only `5` on an `S` line. If it is left out, an `S` line takes 5 and other lines take 0 |
| `exempt_code` | For an `E` line | `DL8.46.1` to `DL8.46.4`. `exempt_reason` is optional free text |
| `goods_type`, `item_id` | For an `AE` line | The reverse-charge goods code and the GTIN (8 to 14 digits) |
| `hs_code`, `class_code` | | Item classification |
| `discount_amount` | | The line's own discount (IBT-136), taken off its net. It may not exceed the line |
| `charges` | | The line's own charges (IBG-28), up to 5, each as below without a tax category: a line charge is taxed as its line is |

### Discounts and charges

A charge is an amount or a percentage of a base, never both, with its reason
in words and, optionally, as a UNCL 7161 code (`reason_code`, default `ABK`,
miscellaneous). Common codes: `DL` delivery, `ADR` other services (a service
charge), `PC` packing, `IAA` installation, `FC` freight.

| Charge field | Required | Notes |
|---|---|---|
| `reason` | Yes | Up to 255 characters |
| `reason_code` | | A UNCL 7161 code PINT AE accepts (IBR-CL-20) |
| `amount` | Unless `percent` is given | Up to 2 decimals |
| `percent` | Unless `amount` is given | Up to 2 decimals. The amount is the percentage of `base_amount`, rounded half up to the fils (IBR-146-AE) |
| `base_amount` | | With `percent` only. Defaults to the line's quantity times price, or for a document charge the lines' total |
| `tax_category` | Document charges | `S` (default), `Z`, `E` or `O`. A commercial document takes no `S` charge (IBR-122-AE), and a margin-scheme invoice takes no document charge |
| `tax_rate` | Document charges | `5` for `S` (default), `0` otherwise |
| `exempt_code` | An `E` document charge | `DL8.46.1` to `DL8.46.4` (IBR-169-AE). `exempt_reason` is optional |

<!-- example: POST /api/compliance/ae/invoices ae fragment -->
```json
"charges": [
  { "reason": "Delivery", "reason_code": "DL", "amount": "35.00" },
  { "reason": "Service charge", "reason_code": "ADR", "percent": "10" }
]
```

A document charge adds to the taxable amount of its tax category; one at a
category no line has is a VAT breakdown of its own, and takes no share of the
document discount.

### Recurring invoices

`POST /api/schedules` makes the same invoice again each period, with the
invoice as `POST /api/compliance/ae/invoices` takes it, less its number and
dates, and `due_days` for each run's due date (IBT-009). Each run is a
continuous supply (the fifth transaction flag, BTAE-02), states its period
as its invoicing period (IBG-14) and how often it is billed (BTAE-06:
`WKY`, `MTH`, `QTR` or `YRL`; a school term is `OTH`, said in the invoice
note as IBR-160-AE requires). The same request with `billing_frequency` and
`billing_note` states them on a single invoice. An instalment plan
(`"instalment_model": "periodic"`, or `"delivered"` for one invoice on delivery
and the instalments tracked as payments) spreads the total over the periods; the UAE
has no prepayment invoice, so the advance model is not offered here.

<!-- example: POST /api/schedules ae -->
```json
{
  "name": "Noor maintenance",
  "frequency": "monthly",
  "start_date": "2026-11-01",
  "due_days": 30,
  "auto_issue": false,
  "invoice": {
    "document_type": "invoice", "document_category": "tax", "currency": "AED", "payment_means_code": "30",
    "buyer_name": "Noor Electronics LLC", "buyer_vat_number": "100765432100003",
    "buyer_address": { "street": "Khalifa Street", "city": "Abu Dhabi", "country_code": "AE" },
    "buyer_endpoint": "0235:1007654321", "buyer_subdivision": "AUH", "transaction_flags": "00000000",
    "lines": [{ "item_name": "Maintenance", "quantity": 1, "unit_code": "MON", "unit_price": "1200.00", "tax_category": "S", "tax_rate": 5 }]
  }
}
```

## 3. Send it

```http
POST /api/compliance/ae/invoices/{invoice}/send
Authorization: Bearer <token>
```

Sending does the following:

1. Issues the draft. It takes the next number in the organization's sequence
   and records its moment of issue.
2. Builds the PINT AE document and checks it.
   The document gets a UUID of its own (`document_uuid`, written as
   `cbc:UUID`). This is not the invoice's id: the UUID is what makes each
   e-invoice unique (UAE Electronic Invoicing Guidelines v1.1, p11 row 7).
   The documents API returns it, with `provider_reference`, the service
   provider's own id for the document where it keeps one.
3. Looks the recipient's Peppol identifier up on the network, through the
   service provider, as the provider does before transmitting (UAE
   Electronic Invoicing Guidelines v1.1, p11 rows 5-6). An identifier that
   is not registered stops the issue, with the reason. A reserved endpoint
   is not looked up. When no provider is connected, or none answers,
   nothing is stopped.
4. Stores the document in status `ready` and queues it for the service
   provider.

```json
{
  "success": true,
  "message": "Invoice issued",
  "data": {
    "invoice_id": "9d3c...",
    "invoice_number": "INV-000123",
    "invoice_status": "issued",
    "document_id": "9d3d...",
    "status": "ready",
    "warnings": []
  }
}
```

If any check fails, the answer is `422` with every problem in
`error.details.problems`. The invoice stays a draft and keeps no number, so
you can correct it and send it again. A problem can be a missing mandatory
field, a scenario rule, stored totals that differ from the document's, or a
recipient that is not on the Peppol network. A buyer that is not on
e-invoicing yet uses `0235:9900000098` (Guidelines p23).

Sending is idempotent. A second call on an issued invoice issues nothing and
answers with the document the first call stored. A document is queued once:
the call queues it only if it is `ready` and was never queued, which is the
case for an invoice issued through the pipeline with `auto_submit` false.
A document already queued, or past `ready`, is never queued again.

### Issued late

A UAE e-invoice is due within 14 days of the transaction (see
[RULES.md](../compliance/ae/RULES.md#issuing-and-transmission)). An invoice
issued later than that is still issued and sent, and `warnings` says so:

```json
"warnings": ["Issued late: more than 14 days after the date of supply. A UAE e-invoice is issued and sent within 14 days of the transaction (Ministerial Decision No. 243 of 2025, Article 6)."]
```

The same warning is in the status answer below and in the pipeline's
`warnings`. It is an answer about timing, not an error: nothing needs to be
sent again.

### Through the pipeline

`POST /api/v1/pipeline/submit` drafts, issues and sends a UAE organization's
invoice in one call, authenticated with the licence's `X-API-Key` and
`X-API-Secret`. The invoice is checked by the same rules as
`POST /api/compliance/ae/invoices`, so its body is that endpoint's body (see
[2. Draft an invoice](#2-draft-an-invoice) for every field) with the
pipeline's own fields beside it:

| Field | Required | Meaning |
|---|---|---|
| `org_id` | Yes | The organization, which must be the licence's |
| `auto_submit` | No, default `true` | Queue the document for the service provider once issued |
| `erp_reference_id` | No | Your ERP's reference. Sent without an `invoice_number` or `Idempotency-Key`, a second call with the same reference answers with the first invoice |
| `branch_id` | No | A branch of the organization |
| `invoice_type` | No | Taken as `type`, which is only ever `standard` |
| `due_date` | Unless a credit note or a deemed supply | The payment due date (IBR-127-AE). It stays the due date; it is not taken as the supply date |

The UAE fields are the endpoint's: `document_category`, `transaction_flags`,
`buyer_endpoint`, `buyer_subdivision`, `buyer_legal_registration`,
`credit_reason`, `beneficiary_id`, `principal_id`, `delivery_address`, and on
each line `item_name`, `tax_category`, `exempt_code`, `goods_type`, `item_id`
and `hs_code`. Left out, `document_type` is `invoice`, `document_category`
`tax`, `currency` `AED`, `transaction_flags` `00000000` and the buyer's
`country_code` `AE`.

<!-- example: POST /api/v1/pipeline/submit ae -->
```json
{
  "org_id": "9d3a...",
  "erp_reference_id": "ERP-AE-1",
  "issue_date": "2026-10-10",
  "due_date": "2026-11-09",
  "payment_means_code": "30",
  "buyer_name": "Noor Electronics LLC",
  "buyer_vat_number": "100765432100003",
  "buyer_address": { "street": "Khalifa Street", "city": "Abu Dhabi" },
  "buyer_endpoint": "0235:1007654321",
  "buyer_subdivision": "AUH",
  "lines": [
    { "item_name": "Consulting", "quantity": 2, "unit_code": "DAY", "unit_price": 500, "tax_category": "S", "tax_rate": 5 }
  ]
}
```

The answer is the pipeline's usual shape. `zatca_response.submission_id` is
the document's id and `zatca_response.state` its status. A field that fails
the rules answers `422 VALIDATION_ERROR` with `error.details` mapping each
field to its messages, as the endpoint does, and nothing is drafted. While
the UAE is switched off on this deployment, every call answers
`422 JURISDICTION_UNAVAILABLE` before any field is checked. A document that
would be refused once drafted (a seller detail missing, say) answers `207`
with the invoice still a draft and the problems in `errors`; retry under the
same `Idempotency-Key` once it is fixed.

Once the invoice is issued:

- `auto_submit` `true`, or left out: the invoice is issued and its document
  queued for the service provider.
- `auto_submit` `false`: the invoice is issued and its document built,
  checked and stored as `ready`, but not queued. Send it later with
  `POST /api/compliance/ae/invoices/{invoice}/send`, or by repeating the
  pipeline call with the same `Idempotency-Key` and `auto_submit` `true`.
  The 14-day deadline covers sending too, so do not hold it back for long.

## 4. Follow its status

```http
GET /api/compliance/ae/invoices/{invoice}/status
Authorization: Bearer <token>
```

```json
{
  "success": true,
  "data": {
    "invoice_id": "9d3c...",
    "invoice_status": "issued",
    "document_id": "9d3d...",
    "status": "delivered",
    "message_id": "provider-message-id",
    "mls_code": "AP",
    "errors": [],
    "last_error": null,
    "reporting_status": "reported",
    "authority_reference": "authority-reference",
    "reporting_errors": [],
    "warnings": []
  }
}
```

The document fields are `null` until the invoice has been sent. `warnings`
holds the late-issue warning when it applies. Each entry in `errors` and
`reporting_errors` is an object:

```json
{ "code": "ibr-003", "severity": "fatal", "field": "/Invoice[1]", "message": "An Invoice MUST have an Invoice issue date (ibt-002)." }
```

`code` is the rule or error code, and `severity` is `fatal`, `error` or
`warning`. `field` says where in the document, when the provider says.
`code` and `field` may be null.

The service provider delivers the document to the buyer's provider and, in
parallel, reports its tax data to the authority. It confirms each one
separately (UAE Electronic Invoicing Guidelines v1.1, p10 steps 4, 5 and 8;
p11 step 10). The status therefore has two tracks, and neither hides the
other.

**Delivery** (`status`):

| `status` | Meaning | What to do |
|---|---|---|
| `ready` | Built, checked and stored, and waiting for the provider. With no provider configured, it stays here | Nothing. Without a provider, download the XML and upload it by hand |
| `sending` | One attempt is with the provider now | Nothing |
| `sent` | The provider accepted it for transport and gave it `message_id` | Nothing. The delivery confirmation follows |
| `delivered` | The buyer's provider validated it (Peppol message level response `AB` or `AP`) | Nothing |
| `not_exchanged` | The provider took it for a reserved endpoint: a deemed supply (`0235:9900000097`), a buyer not yet on e-invoicing (`0235:9900000098`), or an export buyer with no identifier (`0235:9900000099`). These are reported to the authority and never exchanged (UAE Electronic Invoicing Guidelines v1.1, p23, p26, p28) | Nothing. The document is done once `reporting_status` is `reported` |
| `rejected` | The provider or the buyer's provider refused the document. The reasons are in `errors` | The platform never sends a rejected document again. Correct the cause and issue a new invoice. Agree with your provider and tax adviser how to record the rejected one |
| `failed` | Every attempt had no verdict: the provider was unreachable, throttled, or refused the credentials. `last_error` says why | An operator fixes the cause and runs `peppol:resend` |

**Report to the authority** (`reporting_status`), from the moment the
provider takes the document:

| `reporting_status` | Meaning | What to do |
|---|---|---|
| `null` | Not handed to the provider yet | Nothing |
| `pending` | With the provider, and the authority has not answered yet | Nothing |
| `reported` | The authority confirmed the tax data. `authority_reference` is its reference, where the provider relays one | Nothing |
| `report_failed` | The tax data did not reach the authority. The reasons are in `reporting_errors` | Nothing at first. The platform asks the provider to report it again every hour (`peppol:retry-reports`), and an operator can ask from the admin console's Peppol page. Watch for `e_invoice.reported` |

Each track only moves forward:

```
delivery:   ready -> sending -> sent -> delivered
            sending -> not_exchanged     (a reserved, report-only endpoint)
            sending -> ready     (no verdict yet: retried with back-off)
            sending -> rejected  (refused by the provider)
            sent -> rejected     (refused by the buyer's provider)
            sending, ready -> failed     (retry window passed, no outage)
            failed -> ready      (operator: peppol:resend)

reporting:  pending -> reported
            pending -> report_failed -> pending (asked again) -> reported
```

Every move, provider callback and status lookup is also kept on the
document's transmission record, append-only, with the SHA-256 of the body as
it arrived. This includes the ones that changed nothing (UAE Electronic
Invoicing Guidelines v1.1, p12). The portal shows the record on the
invoice's page.

A `reported` that arrives before `delivered` leaves the delivery to be
recorded when it comes. A late `sent` moves nothing back. Each outcome is
also a webhook event: `e_invoice.sent`, `e_invoice.delivered`,
`e_invoice.rejected`, `e_invoice.failed`, `e_invoice.reported` and
`e_invoice.report_failed` (see [WEBHOOKS.md](../WEBHOOKS.md)).

## 5. Documents and their XML

```http
GET /api/compliance/ae/documents?direction=out&status=rejected&per_page=50
GET /api/compliance/ae/documents/{document}
GET /api/compliance/ae/documents/{document}/xml
```

- **The list** holds every document the organization sent (`direction=out`)
  and received (`direction=in`), newest first, with optional `status`
  filtering. List items and `data.document` carry the metadata only: `id`,
  `invoice_id`, `direction`, `country`, `customization_id`, `profile_id`,
  `document_type`, `asp_message_id`, `status`, `mls_code`, `errors`,
  `sender_endpoint`, `receiver_endpoint`, `attempts`, `last_error`,
  `created_at` and `updated_at`.
- **The `/xml` route** returns the document as an `application/xml`
  attachment, byte for byte as it was sent or received. This is the record
  the taxpayer keeps.

### Received documents

Documents that other suppliers send to the organization's endpoint arrive
through the provider's inbound callback (below). They are stored with
`direction` `in`, status `received` and `invoice_id` `null`, exactly as they
arrived.

List them with `GET /api/compliance/ae/documents?direction=in` and fetch each
one's XML. A received document never changes status. It is stored, not turned
into a purchase record.

## Service provider callbacks

The provider calls two public routes. It holds no credential of ours, so each
request is signed instead, and the signature is checked before the body is
read.

| Route | Purpose |
|---|---|
| `POST /api/peppol/webhooks/status` | What became of a document we sent |
| `POST /api/peppol/webhooks/inbound` | A document another supplier sent to one of our organizations |

The routes are limited to 300 requests a minute and sit outside the tenant
API's rate limit.

### Signature

Callbacks are signed as follows:

- **`X-Signature-Timestamp`**: Unix time in seconds. Refused when it is more
  than 300 seconds from the server's clock.
- **`X-Signature`**: hex-encoded HMAC-SHA256 of `"{timestamp}.{raw body}"`,
  keyed with `PEPPOL_ASP_WEBHOOK_SECRET`. A `sha256=` prefix is accepted.

```
signature = hex(hmac_sha256(PEPPOL_ASP_WEBHOOK_SECRET, timestamp + "." + raw_body))
```

An unsigned, mis-signed or stale request answers `401`. With no secret
configured, or no provider, every callback answers `401`.

A replay within the 300 seconds changes nothing. Applying a status and
storing an inbound document are both idempotent.

### Bodies

Until a provider is chosen, these are the generic bodies
(`WebhookPayload`).

```json
{ "message_id": "provider-message-id", "status": "delivered", "mls_code": "AP", "errors": [] }
```

Each entry in `errors` may be a string or an object with `code` (or
`rule_id`), `severity`, `field` (or `location`) and `message`. A string that
starts with its code (`[ibr-003]-...` or `BV: ...`) is split into code and
message.

`status` is one of `sent`, `delivered` or `rejected` (delivery), or
`reported` or `report_failed` (the report to the authority, which may carry
`authority_reference`). It may be left out if `mls_code` is given: `AB` and
`AP` mean delivered, and `RE` means rejected. A status the platform does not
recognise changes nothing.

```json
{ "message_id": "provider-message-id", "status": "reported", "authority_reference": "authority-reference" }
```

```json
{ "message_id": "provider-message-id", "document": "<base64 XML>", "sender": "0235:1007654321", "receiver": "0235:1001234567" }
```

`receiver` may carry the `iso6523-actorid-upis::` prefix.

| Answer | Status callback | Inbound callback |
|---|---|---|
| `200` | Recorded. This includes a status that is not a forward move, which is logged and ignored | Stored, or already stored under that `message_id` |
| `401` | Signature refused | Signature refused |
| `404` | No outbound document has that `message_id`. The provider should retry | No organization holds the `receiver` endpoint, or two do. Nothing is stored |
| `422` | The body does not match the shape above | The body does not match, or `document` is not base64 XML |

## Operator commands and queue

| Command | What it does |
|---|---|
| `php artisan peppol:poll-status [--minutes=30] [--limit=200]` | Asks the provider about `sent` documents that have had no callback for at least `--minutes` minutes, up to 14 days old. It is scheduled every ten minutes on one server, only when `PEPPOL_ASP_BASE_URL` is set or the simulator is the gateway, and logs to `storage/logs/peppol-status.log` |
| `php artisan peppol:retry-reports [--limit=200]` | Asks the provider to report again every document whose report to the authority failed, at most once an hour each, for up to 30 days. It is scheduled hourly on one server when a provider or the simulator is connected. The admin console's **Peppol** page has the same action as **Retry report** |
| `php artisan peppol:recover-sending [--minutes=15] [--limit=200]` | Settles documents that have been in `sending` for at least `--minutes` minutes. It asks the provider what it holds under each document's own reference: a document the provider took is recorded as sent, and one it never received is sent again. It is scheduled every ten minutes on one server |
| `php artisan peppol:resend {document}` | Queues a `failed` document again, from its first attempt. It refuses any other status. The document keeps its id, which is the idempotency key sent to the provider, so a resend cannot file it twice. The admin console's **Peppol** page (`/admin/peppol`) has the same action as **Resend** |
| `php artisan peppol:simulate-inbound {endpoint} [--file=]` | Simulator only ([SIMULATOR.md](SIMULATOR.md)). Delivers another supplier's document to the organization that holds `endpoint`, through the signed inbound callback |

Outgoing documents are sent by the `SendPeppolDocument` job, on the
`PEPPOL_QUEUE` queue (worker `laravel-worker-peppol`):

- **No verdict** (the provider is unreachable, answers `401`, `403`, `408`,
  `409`, `425`, `429`, a redirect or a `5xx`): the document returns to
  `ready`. It is retried after 1, 5, 15 and 60 minutes, then hourly. It is
  marked `failed` only once its retry window has passed:
  `PEPPOL_RETRY_WINDOW_HOURS` (72 by default), counted from when it was first
  queued.
- **An outage**: three documents in a row with no verdict. While the outage
  lasts, the retry window stops, so no document is failed. A critical log
  entry and the admin console's **Peppol** page say since when, and the
  invoice page tells the seller its e-invoice is waiting. When the provider
  answers again, every waiting document is sent at once. The provider must
  notify the authority of the disruption, and exchange and report pending
  invoices once service resumes (UAE Electronic Invoicing Guidelines v1.1,
  p45-46).
- **Any other `4xx`**: this is a refusal, and the document becomes
  `rejected`.

**A document left in `sending`** by a worker that died during the call may
already be with the provider, so it is never sent again blindly. A critical
log entry names it. Every ten minutes, `peppol:recover-sending` asks the
provider what it holds under the document's own id, which is the
idempotency key. A document the provider took is recorded as sent, with its
message id. A document the provider never received is sent again (UAE
Electronic Invoicing Guidelines v1.1, p46).
