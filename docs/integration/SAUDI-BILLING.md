# Saudi Arabia: split billing and schedules

Audience: developers billing for a pharmacy, clinic, hospital or school in
Saudi Arabia: sales an insurer pays for, and invoices that repeat each
period. The invoice body these flows take is in [SAUDI.md](SAUDI.md);
authentication, errors and filing states are in [API.md](API.md).

Dates are `YYYY-MM-DD`; in the examples, `<today>` and `<today + 7 days>`
stand for such dates.

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
  "issue_date": "<today>",
  "supply_date": "<today>",
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
    { "start_date": "<today + 7 days>", "end_date": "<today + 110 days>" },
    { "start_date": "<today + 125 days>", "end_date": "<today + 210 days>" }
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
