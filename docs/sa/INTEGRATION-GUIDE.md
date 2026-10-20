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

### Schedules and instalment plans

A schedule makes the same invoice again each period - a school's terms, a
subscription, a monthly service. `POST /api/schedules` takes how often
(`weekly`, `monthly`, `quarterly`, `termly` with the terms' own dates, or
`yearly`), when it starts, when it ends (`end_date`, a number of
`occurrences`, or neither to run until stopped), whether each invoice is
issued as it is made (`auto_issue`) or left a draft to review, `due_days`,
and the invoice as `POST /api/invoices` takes it, less its number and dates.
The invoice is checked by these same rules when the schedule is made and
again for every run. Each run is made on its period's first day on the
Kingdom's clock - or, with `"bills_in": "arrears"`, on the day after the
period ends - and states its period as the supply date (KSA-5) and the
supply end date (KSA-24).

<!-- example: POST /api/schedules sa -->
```json
{
  "name": "Grade 5 - Fahad Al-Harbi",
  "frequency": "termly",
  "terms": [
    { "start_date": "2026-09-01", "end_date": "2026-12-17" },
    { "start_date": "2027-01-03", "end_date": "2027-03-25" },
    { "start_date": "2027-04-04", "end_date": "2027-06-24" }
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

`source_invoice_id` in place of `invoice` repeats an invoice the
organization already wrote, with its buyer, lines, charges and terms.

With `customer_id`, a saved customer, each run takes the buyer from that
customer as it stands on the day - a changed name or address reaches the
next invoice - and the invoice need not give the buyer itself. An invoice
already issued keeps the buyer it was issued to. A customer since removed
leaves the buyer the invoice was made with.

An instalment plan (`instalment_model`) spreads the invoice's total over the
schedule's periods instead of billing all of it every period: each part
takes the total over the number of parts, rounded, and the last what is
left. Which document each part is follows when the supply happens, since
tax is due on the date of supply, of the invoice or of payment, whichever
is first (GCC Unified VAT Agreement, Article 23(1) and (2)):

- `delivered` - the goods or the service delivered up front and paid in
  instalments. The whole value is supplied on delivery, so one tax invoice
  for the full value is made on the plan's first day, and each instalment
  is a payment due against it (`amount_due`), recorded paid with
  `POST /api/schedules/{id}/runs/{run}/paid`.
- `periodic` - a supply that runs over the periods, its consideration
  payable periodically under the contract: each instalment is a separate
  supply (Agreement 23(3); VAT Implementing Regulations Article 20(1)), a
  tax invoice for its share and its period.
- `prepayment` - paid ahead of the supply, as a year's school fees: each
  part but the last is a prepayment invoice (type 386), since receiving an
  advance needs a tax invoice, and the last is the final invoice for the
  whole supply settling them all (ZATCA's E-Invoicing Detailed Guidelines
  8.1, p40-43). The final invoice waits until every advance is issued.

Where it is not clear the supply runs over the periods, choose `delivered`:
it never declares the tax later than the law asks. A `periodic` or
`prepayment` plan's lines carry no charges or discounts: put them in the
prices.

<!-- example: POST /api/schedules sa -->
```json
{
  "name": "Beta annual maintenance",
  "frequency": "quarterly",
  "start_date": "2027-01-01",
  "occurrences": 4,
  "instalment_model": "prepayment",
  "auto_issue": true,
  "due_days": 15,
  "invoice": {
    "type": "standard",
    "buyer_name": "Beta Industries",
    "buyer_name_ar": "شركة بيتا للصناعات",
    "buyer_vat_number": "399999999800003",
    "buyer_address": { "street": "Olaya Street", "building_number": "4321", "district": "Al Murooj", "city": "Riyadh", "postal_code": "11564", "country_code": "SA" },
    "lines": [{ "description": "Annual maintenance contract", "description_ar": "عقد الصيانة السنوي", "quantity": 1, "unit_price": "48000.00" }]
  }
}
```

`GET /api/schedules/{id}` answers with its runs and the next ones as they
would be made today. `POST /api/schedules/{id}/pause`, `/resume`, `/cancel`
and `/skip` (the next period), and `PUT /api/schedules/{id}/prices` with a
price per line from the next period on, change it; each change is on the
organization's activity record. The webhooks `schedule.run_created` and
`schedule.failed` announce each run (docs/WEBHOOKS.md).

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
