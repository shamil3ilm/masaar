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
POST /api/compliance/onboarding/ccsid
Authorization: Bearer <token>
{ "otp": "123456", "common_name": "<a name for this device>", "serial_number": "1-<solution>|2-<model>|3-<serial>" }

POST /api/compliance/onboarding/compliance-check
POST /api/compliance/onboarding/pcsid
GET  /api/compliance/onboarding/status
```

The compliance CSID (`ccsid`) is requested with the OTP and the device's
`common_name` and `serial_number`; the compliance checks then run against it,
and the production CSID (`pcsid`) is requested once they pass. A branch with
its own device is onboarded the same way under
`/api/organizations/branches/{branch}/onboarding/`.

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

### Arabic text

A Saudi invoice is in Arabic: its data "must be in Arabic" and "any
information contained in the human readable form of the Tax Invoice must be
in Arabic including notes. Any additional translation is also allowed"
(ZATCA E-Invoicing Detailed Guidelines, p60 and p63). So a draft is refused,
on `POST /api/invoices`, the pipeline, each item of a batch and the portal,
when it lacks:

| Field | When |
|---|---|
| `lines.*.description_ar` | always, in Arabic letters |
| `notes` | when given: Arabic text, with any translation after it |
| `buyer_name_ar` | on a standard invoice and the credit and debit notes against one |
| `adjustment_reason` | on a credit or debit note: Arabic text, with any translation after it |
| `payment_terms` | when given: Arabic text, with any translation after it |
| `charges.*.reason`, `lines.*.charges.*.reason` | when given: Arabic text, with any translation after it |

References and identifiers (`order_reference`, `contract_reference`,
`buyer_reference`, VAT and CR numbers), dates, amounts and addresses are not
free text and are not checked. Coded values print their Arabic beside them:
an exemption code ZATCA's own Arabic, a unit its Arabic unit.

The organization's own Arabic name is asked for at sign-up and on the company
page. Each refusal names the field and the rule in English and Arabic:
`Arabic description required (ZATCA: the invoice must be in Arabic) / الوصف بالعربية مطلوب ...`.

The Arabic is filled in from what the organization has saved or what ZATCA
publishes, never translated from what was sent:

- a line naming a saved product by `product_id` or by its code
  (`product_code`, the product's SKU) takes the product's Arabic name;
- an invoice naming a saved customer by `customer_id` takes the customer's
  Arabic name, and its payment terms when the invoice gives none;
- a credit or debit note reason sent as one of the five reasons ZATCA's data
  dictionary lists (KSA-10) takes the dictionary's Arabic beside it;
- a charge whose reason has no Arabic takes the platform's Arabic for its
  reason code beside it, for `ABK`, `ADR`, `CG`, `DL`, `FC`, `IAA`, `PC` and
  `SAA`. ZATCA publishes no Arabic for these codes, so for any other code send
  the Arabic yourself.

A platform administrator can give an organization a grace period while it
fills in its Arabic names. Until the date it names, a draft without the
Arabic is taken, and the reply lists what is missing in `warnings`
(`data.warnings` on `/api/invoices`, `warnings` on a pipeline result).

Nothing new is added to the signed XML: where the Arabic is given, it is
what the document already carries as the seller name, the buyer name and
each item name.

### Discounts and charges

`POST /api/invoices` and the ERP pipeline take a line's own `discount_amount`
and `charges`, and `charges` on the whole invoice. A charge is an `amount` or
a `percent` of a `base_amount` (by default the line's quantity times price,
or for a document charge the lines' total; rounded half up to the halalah,
BR-KSA-EN16931-03), with its `reason` and a UNCL 7161 `reason_code` ZATCA
accepts (BR-KSA-CL-06; default `ABK`). Common codes: `DL` delivery, `ADR` a
service charge, `PC` packing, `IAA` installation, `FC` freight.

A document charge is made at its own `tax_category` and `tax_rate` (default
`S` at 15); one that is not standard-rated has a zero rate and names its
`exempt_code` and `exempt_reason` from that category (BR-KSA-CL-04). A line
charge is taxed as its line is. On an invoice priced with VAT in it, every
amount is given with VAT in it too.

<!-- example: POST /api/invoices sa fragment -->
```json
"lines": [
  { "description": "Sofa", "description_ar": "أريكة", "quantity": 1, "unit_price": "2499.99", "discount_amount": "250.00",
    "charges": [{ "reason": "Installation", "reason_code": "IAA", "amount": "150.00" }] }
],
"charges": [
  { "reason": "Delivery", "reason_code": "DL", "amount": "25.00" },
  { "reason": "Service charge", "reason_code": "ADR", "percent": "12.5" }
]
```

The same request takes `payment_terms` (KSA-22), `due_date` (kept and shown;
ZATCA's document has no due date), `supply_end_date` (KSA-24), the
transaction types `is_export`, `is_summary`, `is_nominal`, `is_third_party`
and `is_self_billed` (KSA-2), and the buyer's `order_reference` (BT-13),
`contract_reference` (BT-12) and `buyer_reference` (shown, not in the
document).

### Prepayments

An advance is invoiced when it is received, as a prepayment invoice
(`"document_type": "prepayment"`, type 386). The final invoice names the
prepayment invoices it settles:

<!-- example: POST /api/invoices sa fragment -->
```json
"prepayments": [{ "invoice_number": "ADV-1" }]
```

Each must be the organization's own, issued, in the same currency and not
settled already, and only a final tax invoice settles them (BR-KSA-73). The
final invoice states the whole supply and its VAT, a line per prepaid tax
category naming the prepayment invoice (KSA-26 to KSA-34), the prepaid
amount (BT-113, `prepaid_amount`) and what is left to pay; its QR's total is
that amount. The VAT summary takes the prepaid VAT off the final invoice, as
it was declared on the prepayment invoice. The portal's form takes the
numbers separated by commas.

### Split billing: patient and insurer

A pharmacy, clinic or hospital often bills one sale to two payers: the
patient pays a share (a co-payment or deductible) and the insurer pays the
rest. Enter the sale once with a `split`, and the platform drafts two
invoices, each a full invoice with its own number, counter (ICV) and place
in the chain:

- **The patient's share** is a simplified tax invoice (KSA-2 `02`). It is
  reported within 24 hours. ZATCA describes a simplified invoice as "generally
  issued for a B2C transaction" (E-Invoicing Detailed Guidelines §2.3, p8).
- **The insurer's share** is a standard tax invoice (KSA-2 `01`) to the
  insurer as a registered buyer, with its VAT number (BT-48, BR-KSA-44) and
  national address (BR-KSA-63). It is cleared before it is handed over. A
  simplified invoice to a business is allowed below SAR 1,000 (p17), but the
  insurer's share is always a tax invoice.

The sale's own fields describe the patient, who is its buyer. The split says
how it is divided:

| `split.method` | The patient pays |
|---|---|
| `percent` | `split.percent` of each unit price, rounded half up to the halalah. The insurer pays the rest of each unit. |
| `amount` | `split.amount`, spread over the lines in proportion to them. A share that does not divide by a line's quantity is stated as two prices a halalah apart. |
| `lines` | the lines marked `"payer": "patient"`. Lines marked `"payer": "insurer"` go to the insurer. |

Each share lists the sale's items at the part of the price its payer pays.
Its VAT is computed on that part, line by line and category by category. On
prices with VAT in them the two totals add up exactly to the sale. On prices
without VAT the net amounts add up exactly, and each share's VAT is rounded
on its own.

Rules for the line's VAT treatment:

- A medicine zero-rated as `VATEX-SA-35` stays zero-rated in both shares.
- A citizen's private healthcare (`VATEX-SA-HEA`) is zero-rated on the
  patient's share. That share names the patient by national ID
  (`buyer_id_scheme` `NAT`, BR-KSA-49) and by name (BR-KSA-25).
- The insurer's share of a `VATEX-SA-HEA` line is standard-rated. Its buyer
  is the insurer, which has no national ID to satisfy BR-KSA-49.
- A split sale takes no document discount, charges or prepayments, and no
  line discounts or line charges. Enter the prices after them.

Name the insurer as a saved customer marked as an insurer
(`split.insurer_customer_id`), or give its details in `split.insurer`. Its
references go on its invoice:

- the claim or approval number as the order reference (BT-13,
  `order_reference`);
- the policy as the contract reference (BT-12, `contract_reference`);
- the member number as the buyer's reference (BT-10, `buyer_reference`).
  BT-10 is kept and shown, but ZATCA's document has no place for it.

<!-- example: POST /api/invoices/splits sa -->
```json
{
  "issue_date": "2026-10-20",
  "supply_date": "2026-10-20",
  "prices_include_vat": true,
  "buyer_name": "Sara Al Qahtani",
  "buyer_name_ar": "سارة القحطاني",
  "lines": [
    { "description": "Insulin pen", "description_ar": "قلم إنسولين", "quantity": 2, "unit_price": "115.00", "exempt_code": "VATEX-SA-35" },
    { "description": "Glucose meter", "description_ar": "جهاز قياس السكر", "quantity": 1, "unit_price": "230.00" }
  ],
  "split": {
    "method": "percent",
    "percent": 20,
    "insurer": {
      "name": "Gulf Health Insurance",
      "name_ar": "الخليج للتأمين الصحي",
      "vat_number": "311111111100003",
      "address": { "street": "King Abdullah Road", "building_number": "7788", "district": "Al Malqa", "city": "Riyadh", "postal_code": "13521", "country_code": "SA" }
    },
    "claim_reference": "CLM-2026-0042",
    "policy_number": "POL-77",
    "member_id": "MEM-1234"
  }
}
```

`POST /api/invoices/splits` answers `201` with `data.split` and
`data.invoices.patient` and `data.invoices.insurer`, both drafts:

- `data.split` holds the method, the sale's `sale_subtotal`, `sale_tax` and
  `sale_total`, the references, `status` and `billed`.
- `billed` is what the split's invoices bill, net of the notes against them.

Issue each draft as you would any other. `GET /api/invoices/splits/{id}` reads
the split back. An `Idempotency-Key` header makes a retry answer with the
split the first request made.

To draft, issue and file both shares in one call, send the same body to the
pipeline, with `org_id`:

<!-- example: POST /api/v1/pipeline/split sa -->
```json
{
  "issue_date": "2026-10-20",
  "supply_date": "2026-10-20",
  "lines": [
    { "description": "Specialist consultation", "description_ar": "استشارة أخصائي", "quantity": 1, "unit_price": "450.00" },
    { "description": "Arm sling", "description_ar": "حمالة ذراع", "quantity": 2, "unit_price": "35.00" }
  ],
  "split": {
    "method": "amount",
    "amount": "120.00",
    "insurer": {
      "name": "Gulf Health Insurance",
      "name_ar": "الخليج للتأمين الصحي",
      "vat_number": "311111111100003",
      "address": { "street": "King Abdullah Road", "building_number": "7788", "district": "Al Malqa", "city": "Riyadh", "postal_code": "13521", "country_code": "SA" }
    },
    "claim_reference": "CLM-2026-0043"
  }
}
```

The patient's share is issued first and the insurer's second, so both are in
the same chain. Each answer is under `data.patient` and `data.insurer`, in
the same shape `/pipeline/submit` uses for one invoice:

- `201` when both shares went through cleanly;
- `207` when either did not. The insurer's share is left a draft if the
  patient's could not be issued, and a retry with the same `Idempotency-Key`
  carries on from there.

ZATCA's data dictionary has no field linking two invoices. The billing
reference (BT-25) is "NA" on tax invoices and simplified invoices. So each
share says which share it is in a note (BT-22, Arabic with English beside
it), and the insurer's note names the patient's invoice number. Both carry
the split's id on the platform, which links them both ways.

**Corrections.** Correct either share with a credit or debit note against it,
as you would any invoice (`billing_ref` set to that share's number). A note
follows the type of the invoice it corrects (Detailed Guidelines p19).

When the insurer rejects its share, or approves only part of it, record its
decision. `approved` is what the insurer pays, with VAT:

```json
{ "approved": "300.00" }
```

`POST /api/invoices/splits/{id}/settle` then does three things:

1. It issues a credit note on the insurer's invoice for the part it declined.
   The reason (KSA-10, BR-KSA-17) defaults to "Cancellation or suspension of
   the supplies after its occurrence either wholly or partially".
2. It issues a new simplified invoice to the patient for the same items at
   the same prices.
3. It settles the split. `billed` still comes to the sale.

The declined part is spread over the insurer's lines in proportion. Both new
documents keep the VAT treatment the insurer's invoice gave each line. An
invoice is never cancelled; it is corrected by a note (FAQ p63). The
decision is recorded once.

### 5. Check Status

```http
GET /api/compliance/sa/status/{invoice_id}
```

---

## Error Codes

| Code | Meaning |
|------|---------|
| `FATOORA_REJECTED` | ZATCA rejected the invoice — check `errors` array |
| `FATOORA_UNAVAILABLE` | ZATCA API unreachable — invoice queued for offline retry |
| `CERT_EXPIRED` | CSID certificate expired — run `fatoora:check-certificate`, then onboard again for a new CSID |
| `INVALID_VAT` | VAT number format invalid (must be 15 digits, start+end with 3) |
