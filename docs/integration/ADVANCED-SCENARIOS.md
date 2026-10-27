# Advanced integration scenarios

Audience: partners whose system serves several businesses, branches or
tills, sells across borders or in other currencies. The basics are in
[QUICKSTART.md](QUICKSTART.md) and [API.md](API.md); the invoice fields in
[SAUDI.md](SAUDI.md) and [UAE.md](UAE.md).

## Many businesses

Each business is its own organization, with its own onboarding and its own
credential: a key acts for one organization, and a pipeline body naming
another `org_id` is refused `403 FORBIDDEN`. Keep, per business, the
organization id, the key and the secret (encrypted at rest in your system).
Each business signs up on the portal and is approved by a platform
administrator, who issues its licence; its administrator can then make the
organization's own keys in Portal › Integrations. There is no registration
endpoint on `/api/v1`.

Before a business is connected to ZATCA, an invoice sent for it is drafted
and answered `207`; the same request under the same `Idempotency-Key` issues
and files it once the business is connected. `GET /api/v1/dashboard/health`
shows each business's readiness, the signing certificate under
`data.checks.certificates`.

## Branches and tills

ZATCA treats each signing device (EGS unit) separately: its own serial, its
own invoice counter (ICV) and hash chain (PIH), its own certificate. Here a
branch has one or more tills, each such a device. Branches and their
onboarding are the business administrator's (Portal › ZATCA, or the
bearer-token routes under `/api/organizations/branches`); a created branch's
id comes back in `data.branch.id`.

Send it with the invoices from that location:

- `branch_id`: a branch of the key's organization (any other is refused);
- `till_id`, or `till_number` beside `branch_id`: the till that made the
  sale. Naming neither issues on the branch's first till; a branch whose
  till is not yet onboarded signs with the head office's device; a retired
  till is refused.

<!-- example: POST /api/v1/pipeline/submit sa -->
```json
{
  "org_id": "5f0c2a9e-3b7d-4e1a-9c8f-2d6b4a1e7c30",
  "erp_reference_id": "BR2-SALE-451",
  "invoice_number": "BR2-000451",
  "type": "simplified",
  "issue_date": "2026-10-15",
  "payment_means_code": "10",
  "lines": [
    { "description": "Coffee beans 1 kg", "description_ar": "حبوب قهوة 1 كجم", "quantity": 2, "unit_code": "H87", "unit_price": "60.00", "tax_rate": 15 },
    { "description": "Paper filters", "description_ar": "فلاتر قهوة ورقية", "quantity": 1, "unit_code": "H87", "unit_price": "12.50", "tax_rate": 15 }
  ]
}
```

Add `"branch_id": "<the branch's id>"` to send it from a branch. A
simplified invoice names no buyer, so it needs no `buyer_name_ar`.

## Tax treatment by kind of business (Saudi Arabia)

| Business | Invoice | Lines |
|---|---|---|
| Retail (B2C) | `simplified` | `S`, 15 |
| Wholesale (B2B) | `standard` | `S`, 15 |
| Private healthcare or education to a citizen | names the buyer by national ID (`NAT`) | `Z` with `VATEX-SA-HEA` / `VATEX-SA-EDU` |
| Medicines, medical equipment | as the sale | `Z` with `VATEX-SA-35` |
| Qualifying metals | as the sale | `Z` with `VATEX-SA-36` |
| Real estate, financial services | `standard` | `E` with `VATEX-SA-30` / `VATEX-SA-29` |

Every `Z`, `E` or `O` line names its `exempt_code` and `exempt_reason`, under
the code's own category (BR-KSA-CL-04), and one invoice carries one code per
category: send lines under a second code in that category on another
invoice.

## Exports and other countries

| Sale | Treatment |
|---|---|
| Goods abroad | `standard`, `is_export`, `Z` with `VATEX-SA-32` |
| Services abroad | `standard`, `is_export`, `Z` with `VATEX-SA-33` |
| International transport | `Z` with `VATEX-SA-34-1` to `34-5`, on its own invoice |

An export is never simplified (BR-KSA-31) and names no buyer VAT number
(BR-KSA-46): the buyer is `buyer_id` with `buyer_id_scheme` (`OTH` and its
foreign tax number, letters and digits only), at its own address and
country. There are no customs fields; a declaration number can go in the
notes, in Arabic first.

<!-- example: POST /api/v1/pipeline/submit sa -->
```json
{
  "org_id": "5f0c2a9e-3b7d-4e1a-9c8f-2d6b4a1e7c30",
  "erp_reference_id": "SO-EXP-318",
  "invoice_number": "EXP-000318",
  "type": "standard",
  "is_export": true,
  "issue_date": "2026-10-15",
  "supply_date": "2026-10-14",
  "buyer_name": "Delta Imports Ltd",
  "buyer_name_ar": "شركة دلتا للاستيراد المحدودة",
  "buyer_id": "EG482915736",
  "buyer_id_scheme": "OTH",
  "buyer_address": { "street": "Corniche El Nil", "building_number": "12", "city": "Cairo", "postal_code": "11511", "country_code": "EG" },
  "lines": [
    { "description": "Steel pipes, 6 m", "description_ar": "أنابيب فولاذية بطول 6 أمتار", "quantity": 100, "unit_price": "45.00", "tax_category": "Z", "tax_rate": 0, "exempt_code": "VATEX-SA-32", "exempt_reason": "Export of goods" }
  ],
  "notes": "بيان جمركي رقم 40017893 - Customs declaration 40017893"
}
```

A business in the UAE is a UAE organization of its own; its invoices take
the UAE body ([UAE.md](UAE.md)).

## Other currencies

Send `currency` and, when it is not the country's, `exchange_rate` to the
country's currency. The totals are computed from the lines in the invoice's
currency; a Saudi invoice also states its VAT in SAR (BT-6 SAR, BT-111), so
1,000.00 USD at 3.75 carries 150.00 USD of VAT and 562.50 SAR. Never send
totals: they are computed.

## Onboarding a business, end to end

1. The business signs up on the portal, with its legal name in English and
   Arabic, VAT number, CR number and national address; a platform
   administrator approves it.
2. Its administrator connects it to ZATCA: an OTP from the Fatoora portal on
   the portal's ZATCA page (or the onboarding routes in
   [SAUDI.md](SAUDI.md)). The platform makes the key pair and CSR, obtains
   the compliance certificate, passes the compliance checks and obtains the
   production certificate.
3. You receive (or the administrator makes) the key, the secret and the
   organization id, and send invoices to `/api/v1/pipeline/submit`.
4. Subscribe to webhooks ([WEBHOOKS.md](WEBHOOKS.md)) for the outcome of
   each filing, and `certificate.expiring` for renewals.
