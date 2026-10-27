# UAE: the invoice the API takes

Audience: developers sending invoices for an organization registered in the
UAE, which travel the Peppol network (PINT AE) through an accredited service
provider. Authentication, errors, idempotency and limits are in
[API.md](API.md); the rules behind each refusal are in the UAE compliance
documentation.

## Routes

| Route | Credential | Does |
|---|---|---|
| `POST /api/v1/pipeline/submit` (and `/batch`) | API key | Draft, issue and send in one call |
| `GET /api/v1/pipeline/status/{invoiceId}` | API key | The invoice now |
| `PUT /api/compliance/ae/profile` | Bearer, administrator | The seller details every document carries |
| `POST /api/compliance/ae/invoices` | Bearer, member | Draft |
| `POST /api/compliance/ae/invoices/{invoice}/send` | Bearer, member | Issue and queue for the provider |
| `GET /api/compliance/ae/invoices/{invoice}/status` | Bearer | Both tracks, below |
| `GET /api/compliance/ae/documents`, `/{document}`, `/{document}/xml` | Bearer | Documents sent (`direction=out`) and received (`in`), and each one's XML as sent or received |

`/api/v1/compliance/*` is Saudi only and answers a UAE organization
`422 JURISDICTION_ENDPOINT_MISMATCH`. Known gap: the document and XML routes
are not on `/api/v1` yet. The bearer routes answer `403` to an organization
not registered in the UAE; the routes that start work answer
`422 JURISDICTION_UNAVAILABLE` while the UAE is switched off on the
deployment (reads stay open). Split billing is not offered: sales to
consumers are outside UAE e-invoicing (Electronic Invoicing Guidelines v1.1,
§6.2).

## The seller

`PUT /api/compliance/ae/profile`: `name`, `street`, `city`, `emirate`
(`AUH`, `DXB`, `SHJ`, `UAQ`, `FUJ`, `AJM`, `RAK`), `trn` (15 digits) or `tin`
(10), `endpoint_id` (`0235:` and 10 digits, from the provider),
`legal_registration_type` (`TL`, `EID`, `PAS`, `CD`) and `_id`
(`_authority` for `TL` and `PAS`), and `payment_account` (IBAN, needed for
payment means `30`).

## The invoice

<!-- example: POST /api/v1/pipeline/submit ae -->
```json
{
  "org_id": "9d3a0c1e-5b7f-4c2d-8e9a-1f2b3c4d5e6f",
  "erp_reference_id": "ERP-AE-1",
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
    { "item_name": "Consulting", "quantity": 2, "unit_code": "DAY", "unit_price": 500, "tax_category": "S", "tax_rate": 5 }
  ]
}
```

| Field | Rule |
|---|---|
| `document_type` | `invoice` or `credit_note` (no debit note) |
| `document_category` | `tax` (default), `commercial` or `self_billing` (buyer fields then describe the seller) |
| `due_date` | Required except on a credit note or deemed supply; it stays the due date |
| `currency`, `exchange_rate` | ISO 4217 (IBR-CL-04); a rate to AED when not AED |
| `payment_means_code` | One of `1`, `10`, `20`, `21`, `30`, `49`, `54`, `55`, `68` (IBR-CL-16) |
| `buyer_vat_number` | The buyer's TRN, for a UAE buyer on a tax document and any reverse-charge line |
| `buyer_endpoint` | `0235:` and 10 digits; the reserved `0235:9900000097` (deemed supply), `…98` (buyer not yet on e-invoicing), `…99` (export without identifier) |
| `buyer_subdivision` | The buyer's emirate, or region abroad |
| `transaction_flags` | Eight `0`/`1`: free zone, deemed supply, margin scheme, summary, continuous supply, disclosed agent, e-commerce, export |
| `billing_ref`, `credit_reason` | A credit note: the invoice it corrects; `DL8.61.1.A`–`E` or `VD` |
| `lines.*.tax_category` | `S` (5%), `Z`, `E` (with `exempt_code` `DL8.46.1`–`4`), `O`, `AE` (with `goods_type`, `item_id`), `N` |
| `lines.*.unit_price` | Net, never with VAT |
| `discount_amount`, `charges` | As in Saudi Arabia, `reason_code` from PINT AE's list (IBR-CL-20) |
| `billing_frequency`, `billing_note` | Continuous supply frequency (BTAE-06) |

## Sending

Sending (the pipeline with `auto_submit` true, or `/send`) issues the draft
with the next number, builds and checks the PINT AE document, looks the
buyer's endpoint up through the provider (an unregistered one stops the
issue), stores it `ready` and queues it once. A failed check answers `422`
with every problem in `error.details.problems` and leaves a numberless draft
to correct; through the pipeline, `207` with the draft and `errors`. Sending
again is idempotent. An invoice issued more than 14 days after the supply is
still sent, with a warning in `warnings`.

**Before the mandate.** An invoice issued before the organization's
e-invoicing start date is an ordinary tax invoice: its document is built,
checked and kept as `not_transmitted`, and never sent, then or later.

## Following it: two tracks

The provider delivers the document to the buyer's provider and reports its
tax data to the authority, and confirms each separately; neither waits for
the other. `GET …/status` answers `status`, `reporting_status`,
`message_id`, `mls_code`, `errors`, `last_error`, `authority_reference`,
`reporting_errors` and `warnings`; each error is `{code, severity, field,
message}`.

| `status` (delivery) | Meaning |
|---|---|
| `ready` | Stored and waiting for the provider |
| `sending` / `sent` | With the provider / taken by it (`message_id`) |
| `delivered` | The buyer's provider accepted it (`AB`, `AP`) |
| `not_exchanged` | A reserved endpoint: reported, never exchanged; done once reported |
| `rejected` | Refused (`errors`); never sent again. Correct the cause and issue a new invoice |
| `failed` | No verdict within the retry window (72 hours by default); an operator resends it |
| `not_transmitted` | Issued before the start date; kept, never sent |
| `received` | An inbound document from another supplier |

| `reporting_status` | Meaning |
|---|---|
| `null` / `pending` | Not with the provider yet / awaiting the authority |
| `reported` | Confirmed (`authority_reference`) |
| `report_failed` | Not reported (`reporting_errors`); asked again hourly |

Each move is also an `e_invoice.*` webhook ([WEBHOOKS.md](WEBHOOKS.md)).

## Provider callbacks

The service provider calls `POST /api/peppol/webhooks/status` and
`/inbound` (300 a minute). Each is signed: `X-Signature-Timestamp` (Unix
seconds, within 300 seconds) and `X-Signature`, the hex HMAC-SHA256 of
`"{timestamp}.{raw body}"` with the provider's webhook secret; unsigned,
mis-signed or stale is `401`. With no provider connected, the
[simulator](SIMULATOR.md) plays the provider.
