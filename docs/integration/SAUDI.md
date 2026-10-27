# Saudi Arabia: the invoice the API takes

Audience: developers sending invoices for an organization registered in
Saudi Arabia (ZATCA Fatoora, Phase 2). Authentication, errors, idempotency
and filing states are in [API.md](API.md); this page is the invoice body and
the Saudi-only routes. Every field and its rule is in
[`openapi.yaml`](../openapi.yaml).

## Standard and simplified

| `type` | For | Filing |
|---|---|---|
| `standard` | A business buyer (B2B, B2G) | Cleared by ZATCA within the call, before it may reach the buyer |
| `simplified` | A consumer (B2C) | Reported from a queue, within the 24 hours ZATCA allows |

`document_type` is `invoice` (default), `credit_note`, `debit_note` or
`prepayment`.

A standard invoice to a VAT-registered buyer, filed straight away:

<!-- example: POST /api/v1/pipeline/submit sa -->
```json
{
  "org_id": "5f0c2a9e-3b7d-4e1a-9c8f-2d6b4a1e7c30",
  "erp_reference_id": "SO-1001",
  "invoice_number": "INV-1001",
  "type": "standard",
  "document_type": "invoice",
  "issue_date": "2026-10-15",
  "supply_date": "2026-10-15",
  "currency": "SAR",
  "buyer_name": "Beta Industries",
  "buyer_name_ar": "شركة بيتا للصناعات",
  "buyer_vat_number": "399999999800003",
  "buyer_address": { "street": "Olaya Street", "building_number": "4321", "district": "Al Murooj", "city": "Riyadh", "postal_code": "11564", "country_code": "SA" },
  "lines": [
    { "description": "Office chair", "description_ar": "كرسي مكتب", "quantity": 2, "unit_code": "H87", "unit_price": 450.00, "tax_rate": 15, "tax_category": "S", "discount_amount": 50.00 }
  ],
  "payment_means_code": "30",
  "notes": "يُرجى السداد خلال ثلاثين يوماً - Payment within thirty days"
}
```

## Fields worth knowing

| Field | Rule |
|---|---|
| `issue_date` | Required |
| `sold_at` | A till's sale time with its UTC offset; not more than 30 seconds in the future (BR-KSA-04). The sale time is the supply date |
| `supply_date`, `supply_end_date` | Supply period (KSA-5, KSA-24) |
| `currency`, `exchange_rate` | ZATCA's ISO 4217 list (BR-KSA-CL-01); a rate to SAR when not SAR. VAT is also stated in SAR |
| `payment_means_code` | A code ZATCA accepts (BR-KSA-16): `10` cash, `30` credit transfer, `48` card, … |
| `buyer_vat_number`, `buyer_address` | Required on a standard invoice; the address with street, building number, city and a five-digit postal code |
| `buyer_id`, `buyer_id_scheme` | Instead of a VAT number: `NAT`, `OTH`, … (an export names no VAT number, BR-KSA-46) |
| `is_export`, `is_summary`, `is_nominal`, `is_third_party`, `is_self_billed` | Transaction types (KSA-2) |
| `is_government`, `contract_reference` | A government invoice names its contract (BR-KSA-92) |
| `lines.*.tax_category`, `exempt_code`, `exempt_reason` | `S` standard; a `Z`, `E` or `O` line names its exemption code (BR-KSA-CL-04), one code per category per invoice |
| `prices_include_vat` | Unit prices and discounts with VAT in them; the net is backed out per category. Default from the organization |
| `customer_id`, `lines.*.product_id`, `lines.*.product_code` | Saved records; they supply the Arabic names when none are sent |
| `branch_id`, `till_id`, `till_number` | The device that signs it; each till has its own counter, hash chain and certificate |

**Arabic is required** (ZATCA E-Invoicing Detailed Guidelines p60, p63):
`lines.*.description_ar` always; `buyer_name_ar` on a standard invoice and its
notes; and `notes`, `adjustment_reason`, `payment_terms` and charge reasons,
when sent, contain Arabic (a translation may follow). Missing Arabic is a 422
naming each field, unless a platform administrator gave the organization a
grace period, when it is accepted and listed in `warnings`.

**Discounts and charges.** A line's own `discount_amount` and `charges`, and
document `charges`: each an `amount`, or a `percent` of `base_amount`, with a
`reason` and a UNCL 7161 `reason_code` (default `ABK`; `DL` delivery, `ADR`
service, `PC` packing, `IAA` installation, `FC` freight). A document charge
has its own `tax_category` and `tax_rate` (default `S`, 15).

<!-- example: POST /api/invoices sa fragment -->
```json
"lines": [
  { "description": "Sofa", "description_ar": "أريكة", "quantity": 1, "unit_price": "2499.99", "discount_amount": "250.00",
    "charges": [{ "reason": "تركيب - Installation", "reason_code": "IAA", "amount": "150.00" }] }
],
"charges": [
  { "reason": "توصيل - Delivery", "reason_code": "DL", "amount": "25.00" },
  { "reason": "رسوم خدمة - Service charge", "reason_code": "ADR", "percent": "12.5" }
]
```

**Prepayments.** An advance is a `prepayment` invoice; the final tax invoice
settles it by number (BR-KSA-73), in the same currency:

<!-- example: POST /api/invoices sa fragment -->
```json
"prepayments": [{ "invoice_number": "ADV-1" }]
```

## Credit and debit notes

`document_type` `credit_note` or `debit_note`, the original's number in
`billing_ref`, the reason in `adjustment_reason` (BR-KSA-17). A note takes
its original's `type`, and is accepted only once the original is cleared or
reported; against a rejected original it is refused (there is nothing on
ZATCA's record to correct).

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
  "buyer_address": { "street": "Olaya Street", "building_number": "4321", "district": "Al Murooj", "city": "Riyadh", "postal_code": "11564", "country_code": "SA" },
  "lines": [
    { "description": "Office chair, returned", "description_ar": "كرسي مكتب مُرتجع", "quantity": 1, "unit_code": "H87", "unit_price": 450.00, "tax_rate": 15, "tax_category": "S" }
  ]
}
```

## Split billing: patient and insurer

A pharmacy, clinic or hospital bills a sale an insurer pays for, in whole or
in part, as one invoice in the recipient's name (ZATCA General VAT Guideline
Healthcare, §5):

- **the patient** (default): a simplified invoice stating the amounts for the
  account of the insurer and of the patient. `split.method` sets the
  patient's share: `percent`, `amount`, or `lines` marked `"payer"`;
- **the insurer** (`"split": {"recipient": "insurer"}`), when it contracted for
  the care and the patient pays nothing: a standard invoice to the insurer,
  with its VAT number and address.

Routes: `POST /api/invoices/splits` or `/api/v1/invoices/splits` (draft,
takes `Idempotency-Key`), `POST /api/v1/pipeline/split` (draft, issue, file),
`GET …/invoices/splits/{split}`, and `POST …/invoices/splits/{split}/settle`
with the insurer's decision (`approved`, and for an insurer recipient
`decision` `price` or `not_covered`, which issues a credit note and, for care
not covered, an invoice to the patient). The licence needs
`simplified_invoices` and `standard_invoices`; settling needs `credit_notes`
and room for two documents in the allowance.

<!-- example: POST /api/v1/pipeline/split sa -->
```json
{
  "issue_date": "2026-10-20",
  "supply_date": "2026-10-20",
  "buyer_name": "Fahad Al Harbi",
  "buyer_name_ar": "فهد الحربي",
  "buyer_id": "1047392816",
  "buyer_id_scheme": "NAT",
  "lines": [
    { "description": "Specialist consultation", "description_ar": "استشارة أخصائي", "quantity": 1, "unit_price": "450.00", "exempt_code": "VATEX-SA-HEA" },
    { "description": "Arm sling", "description_ar": "حمالة ذراع", "quantity": 2, "unit_price": "35.00" }
  ],
  "split": {
    "recipient": "insurer",
    "insurer": {
      "name": "Gulf Health Insurance", "name_ar": "الخليج للتأمين الصحي", "vat_number": "311111111100003",
      "address": { "street": "King Abdullah Road", "building_number": "7788", "district": "Al Malqa", "city": "Riyadh", "postal_code": "13521", "country_code": "SA" }
    },
    "claim_reference": "CLM-2026-0043"
  }
}
```

The answer has `data.split` and `data.invoice` (as `/pipeline/submit`
answers).

## Schedules

`POST /api/schedules` or `/api/v1/schedules` (scope `schedule.manage`) makes
the same invoice each period: `frequency` (`weekly`, `monthly`, `quarterly`,
`termly` with `terms`, `yearly`), `start_date`, `end_date` or `occurrences`,
`auto_issue`, `due_days`, `bills_in` (`advance` or `arrears`), an optional
`instalment_model` (`delivered`, `periodic`, `prepayment`), and the invoice
as `POST /api/invoices` takes it, less its number and dates. Runs are
announced by `schedule.run_created` and `schedule.failed`.

<!-- example: POST /api/schedules sa -->
```json
{
  "name": "Grade 5 - Fahad Al-Harbi",
  "frequency": "termly",
  "terms": [
    { "start_date": "2026-09-01", "end_date": "2026-12-17" },
    { "start_date": "2027-01-03", "end_date": "2027-03-25" }
  ],
  "auto_issue": false,
  "invoice": {
    "type": "simplified",
    "buyer_name": "Fahad Al-Harbi",
    "buyer_name_ar": "فهد الحربي",
    "lines": [{ "description": "Grade 5 tuition", "description_ar": "رسوم الصف الخامس", "quantity": 1, "unit_price": "9000.00" }]
  }
}
```

`GET /schedules/{id}` shows runs and the next ones; `/pause`, `/resume`,
`/cancel`, `/skip`, `PUT /prices`, and `/runs/{run}/paid` and `/rerun`
change it.

## Saudi routes on the bearer-token API

| Route | Does | `data` |
|---|---|---|
| `POST /api/compliance/sa/generate/{invoiceId}` | Issue a draft: number, hash, signature, QR | `hash`, `qr_code`, `signed_xml` |
| `POST /api/compliance/sa/validate/{invoiceId}` | Check with ZATCA without filing | `valid`, `status`, `warnings`, `errors` |
| `POST /api/compliance/sa/submit/{invoiceId}` | File an issued invoice | `status` (ZATCA's clearance or reporting status), `warnings` |
| `GET /api/compliance/sa/status/{invoiceId}` | Its state | `invoice_id`, `status`, `hash`, `qr_code`, `zatca_response`, `warnings`, `rejections` |

The same four are on `/api/v1/compliance/…` with an API key. A rejection
answers `422` with each broken rule (`code`, `category`, `message`) in
`error.details`.

**Onboarding a device** is an administrator's, in Portal › ZATCA, or on this
API: `POST /api/compliance/onboarding/ccsid` with `otp` (six digits from the
Fatoora portal), `common_name` and `serial_number`
(`1-<solution>|2-<model>|3-<serial>`), then
`POST /api/compliance/onboarding/compliance-check` and `/pcsid`;
`GET /api/compliance/onboarding/status`. A branch's own device uses
`/api/organizations/branches/{branch}/onboarding/…`. An OTP is spent whether
the request succeeds or not.

**Environment.** Each organization files with one ZATCA environment, chosen
by a platform administrator: `sandbox` (the developer portal: any OTP, and
only ZATCA's test taxpayer `399999999900003`), `simulation` (OTP from the
Fatoora simulation portal) or `production`. Certificates belong to the
environment that issued them.
