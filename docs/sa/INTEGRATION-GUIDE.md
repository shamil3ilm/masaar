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

A pharmacy, clinic or hospital often bills a sale that an insurer pays for,
wholly or in part. The rules here follow ZATCA's General VAT Guideline
Healthcare (January 2023), section 5.

**One supply has one recipient, and gets one invoice in that recipient's
name.** The guideline says: "A tax invoice cannot be issued showing the name
of insurer as recipient, if it is not the customer" (§5.2, p24). Who the
recipient is depends on the contract (§5.1, pp20-22).

**The patient is the recipient (the default).** ZATCA presumes it "even where
the provider of the healthcare issues the invoice to a third party, or it is
the third party who is responsible for paying" (p21). The platform issues one
simplified invoice to the patient for the whole sale:

- It states what is "for the account of" the insurer and what is for the
  account of the patient (§5.2, p24; Example 10, p23).
- That statement is in the invoice's note (BT-22), with the claim, policy and
  member numbers. Your copy to the insurer is this invoice.
- The insurer cannot deduct its VAT (p25).
- A citizen's private healthcare (`VATEX-SA-HEA`) is zero-rated on the whole
  invoice, whoever pays. "The State will bear the VAT for Saudi nationals
  benefiting from private health care services" (§5.3.3, p26). The invoice
  names the patient by national ID (`NAT`, BR-KSA-49).

**The insurer is the recipient.** This applies when the insurer contracted
with you for the care and the patient "has no contractual rights against the
centre … nor any obligation to pay" (Example 11, p23). Set
`"recipient": "insurer"`:

- The invoice is a standard tax invoice to the insurer, with its VAT number
  (BR-KSA-44) and national address (BR-KSA-63).
- The insurer deducts its VAT (p25).
- The claim goes in BT-13, the policy in BT-12, and the member in BT-10.
- The patient is named in the note (Example 11, p24).
- A `VATEX-SA-HEA` line is standard-rated, because the recipient is the
  insurer, not a Saudi national.
- A patient who pays a share has an obligation to pay, so that sale is the
  patient's. A patient share with `"recipient": "insurer"` is refused.

A medicine zero-rated as `VATEX-SA-35` stays zero-rated whoever buys it
(§6.1, p29). One invoice takes one zero-rating reason per category, so issue
a citizen's `VATEX-SA-HEA` care and `VATEX-SA-35` medicines on separate
invoices.

For an invoice to the patient, `split.method` sets the patient's share:

| `split.method` | The patient's share, with VAT |
|---|---|
| `percent` | `split.percent` of the invoice total, rounded half up to the halalah |
| `amount` | `split.amount`, such as a deductible |
| `lines` | the lines marked `"payer": "patient"`; lines marked `"payer": "insurer"` are the insurer's |

The insurer's share is the rest. The two come to the invoice total exactly.

Enter a price agreed with the insurer as the price, because "VAT should be
calculated based on the net discounted price" (§5.3.1, p25). A split sale
takes no document discount, charges or prepayments; line discounts are fine.

Name the insurer as a saved customer marked as an insurer
(`split.insurer_customer_id`), or give its details in `split.insurer`. The
insurer's VAT number and address are needed only when it is the recipient.

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
    "insurer": { "name": "Gulf Health Insurance", "name_ar": "الخليج للتأمين الصحي" },
    "claim_reference": "CLM-2026-0042",
    "policy_number": "POL-77",
    "member_id": "MEM-1234"
  }
}
```

`POST /api/invoices/splits` answers `201`:

- `data.invoices` holds the draft under its role, `patient` or `insurer`.
- `data.split` holds the `recipient`, `patient_share`, `insurer_share`, the
  references and `status`.

Issue the draft as you would any other. `GET /api/invoices/splits/{id}`
reads the split back. An `Idempotency-Key` header makes a retry answer with
the split the first request made.

The pipeline drafts, issues and files the invoice in one call. Send the same
body, with `org_id`, to `POST /api/v1/pipeline/split`. This example is a
contract with the insurer, so the insurer is the recipient:

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
      "name": "Gulf Health Insurance",
      "name_ar": "الخليج للتأمين الصحي",
      "vat_number": "311111111100003",
      "address": { "street": "King Abdullah Road", "building_number": "7788", "district": "Al Malqa", "city": "Riyadh", "postal_code": "13521", "country_code": "SA" }
    },
    "claim_reference": "CLM-2026-0043"
  }
}
```

The answer has `data.split` and `data.invoice`. `data.invoice` and the status
are what `/pipeline/submit` would give for that invoice.

**Corrections.** Correct the invoice with a credit or debit note against it,
as you would any invoice. When the insurer has decided, record the decision
with `POST /api/invoices/splits/{id}/settle`. What happens depends on the
recipient (§5.3.2, p25).

When the patient is the recipient, nothing is corrected. `approved` is what
the insurer pays of its share, with VAT. The supply and its VAT stand as
invoiced. ZATCA says the provider "should seek repayment from the individual
patient of the value of the service, in addition to VAT". The patient owes
the rest.

```json
{ "approved": "300.00" }
```

When the insurer is the recipient, `approved` is the value it accepts,
**before VAT**, because VAT follows the value (§5.3.1; Example 12, p26).
`decision` says why it accepts less:

- `price`: its agreed price. A credit note on its invoice for the
  difference. The credit note carries the original invoice's number (BT-25)
  and the reason "Amendment of the supply value which is pre-agreed upon
  between the supplier and consumer" (KSA-10).
- `not_covered`: the care is not covered, so "the supply of services will
  have been provided directly to the individual patient and VAT is
  chargeable". Two documents are issued:
  - a credit note on the insurer's invoice, with the reason "Cancellation or
    suspension of the supplies after its occurrence either wholly or
    partially";
  - a simplified invoice to the patient for the same items at the same
    values, taxed as the supply to the patient. A citizen's `VATEX-SA-HEA`
    care is zero-rated again, with their national ID.

```json
{ "approved": "400.00", "decision": "not_covered" }
```

The value declined is spread over the insurer's lines in proportion to their
net values, one line each. Invoices are never cancelled, only corrected by a
note (FAQ p63). The decision is recorded once.

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
