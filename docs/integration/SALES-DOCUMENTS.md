# Returns, vouchers, summaries and contracts

Audience: developers of ERPs, POS systems and partner platforms that record
returns, vouchers, deliveries or staged contracts through the API. Keys,
scopes, idempotency and errors are in [API.md](API.md); every path and body is
in [`docs/openapi.yaml`](../openapi.yaml).

## Returns, exchanges and corrections

`POST /api/v1/invoices/{invoice}/adjustments` (and `/api/invoices/{invoice}/adjustments`
with a person's token) records what happened to an issued invoice and makes
the documents it calls for, in one call. Send an `Idempotency-Key`: a retry
answers the first call's answer. Needs `invoice.submit` and the
`credit_notes` licence feature; it counts up to two documents.

| `kind` | Also send | Makes |
|---|---|---|
| `return` | - | A credit note for the whole invoice |
| `partial_return` | `lines: [{line, quantity}]` (`line` from 1) | A credit note for what came back |
| `discount` | `discount_amount` (before VAT) or `discount_percent`; optional `lines: [{line}]` | A credit note for the discount |
| `exchange` | `lines`, `items: [{description, description_ar, quantity, unit_price}]` | A credit note, and a draft invoice for the items |
| `price_difference` | `lines`, `items` | A debit note (UAE: a tax invoice) or a credit note for the difference |
| `replacement` | `lines`, `note` | Nothing: the record only |
| `buyer_correction` | `buyer: {buyer_name, buyer_vat_number, buyer_address, …}` | A credit note for the whole invoice, and a draft invoice to the corrected buyer |

`note` (up to 500 characters) is kept on the record. Items are at net
prices and taxed as the first line returned unless they give `tax_category`
and `tax_rate`. `preview: true` answers `200` with `data.preview` - each
document's role, type, lines and totals - and makes nothing.

Made, it answers `201`:

<!-- example: response -->
```json
{"adjustment": {"id": "…", "kind": "partial_return", "reason": "In case of goods or services refund. / عند ترجيع السلع أو الخدمات", "details": {"lines": [{"line": 1, "description": "Office chair", "quantity": "2"}]}},
 "documents": [{"role": "credit", "invoice_id": "…", "document_type": "credit_note", "invoice_number": "…", "status": "issued", "total": "575.00", "approval": null, "message": null}]}
```

`status` is `issued`, `pending_approval` (the organization holds notes;
`approval` is the request, as under [Notes held for approval](PIPELINE.md#notes-held-for-approval))
or `draft` (a new invoice, for you to issue). The rules of a note apply: in
Saudi Arabia the invoice must be accepted by ZATCA, and credit notes never
come to more than it and its debit notes; a quantity already returned cannot be returned
again. A refusal answers `422` and makes nothing.

## Disbursements

On `POST /api/v1/invoices` (and the pipeline), a line with
`"kind": "disbursement"` is a cost paid in the customer's name and for their
account (Saudi Arabia, GCC VAT Agreement Art 26(6)(c)). Send
`"disbursements_confirmed": true` with it - the seller confirms the
conditions - or the request answers `422` on `disbursements_confirmed`. The
line is drafted out of scope (`tax_category` `O`, `exempt_code`
`VATEX-SA-OOS`, a bilingual `exempt_reason` unless you give one) whatever
VAT it was sent with, and keeps `kind` on the invoice. A UAE invoice refuses
such a line (`422` on `lines.N.kind`).

## Vouchers

| Call | Does |
|---|---|
| `POST /api/v1/vouchers` | Records a voucher sold: `face_value`, `sold_on`, `payment_method` (`cash`, `card`, `bank_transfer`, `cheque`, `other`), optional `code` (made if left out), `expires_on`, `customer_id`, `holder_name`, `reference`. No invoice is made: selling a voucher is not a supply. `sold_for` other than the face value is refused (`422`) |
| `GET /api/v1/vouchers`, `GET /api/v1/vouchers/{code}` | Each with `balance`, `status` (`active`, `used`, `expired`) and its `redemptions` |
| `POST /api/v1/vouchers/{code}/redeem` | Pays an issued invoice with it: `invoice_id` or `invoice_number`, optional `amount` (default as much as both allow) and `redeemed_on`. Recorded as the invoice's payment, method `voucher` |

Reading needs `invoice.read`, writing `invoice.submit`.

## Delivery notes and summary invoices

A month's deliveries to one customer on one summary tax invoice.

| Call | Does |
|---|---|
| `POST /api/v1/delivery-notes` | Records a delivery: `customer_id`, `delivered_on`, optional `number` and `reference`, `lines: [{description, description_ar, quantity, unit_code, unit_price, tax_category, tax_rate, exempt_code, exempt_reason}]` at net prices. Not a tax document |
| `GET /api/v1/delivery-notes` | Lists them under `delivery_notes`, with `meta` (`total`, `page`, `per_page`); `?customer_id=`, `?invoiced=0` or `1` |
| `DELETE /api/v1/delivery-notes/{id}` | Removes one no invoice has taken (`422` otherwise) |
| `POST /api/v1/invoices/summary` | Drafts one invoice for a customer's delivery notes not yet invoiced: `customer_id`, `from`, `to` (within one calendar month), optional `issue_date` (not before `to`) and `due_date` |

The summary answers `201` with `invoice` (a draft; issue it as any draft),
the `delivery_notes` it took and `issue_by`, the last day the law allows
(Saudi Arabia: the 15th of the next month; UAE: 14 days after the month).
An `issue_date` after `issue_by` is still drafted - a late invoice is still
owed - with `late: true` and the reason under `warnings`; on time, `late` is
`false`.
Each line names its delivery and date. In Saudi Arabia it is a standard
invoice with KSA-2 position 6 set; in the UAE a tax invoice with
`transaction_flags` `00010000`, the period as its invoicing period. Writing
needs `invoice.submit` (the summary also `standard_invoices`); reading
`invoice.read`. `/api/...` with a person's token is the same.

## Contracts billed in stages

| Call | Does |
|---|---|
| `POST /api/v1/contracts` | Records a contract: `customer_id`, `reference` (unique; BT-12 on its invoices), `title`, `title_ar`, `value` before VAT, optional `retention_percent` (at most 50), `tax_category`, `tax_rate`, `exempt_code`, `exempt_reason`, and `stages: [{name, name_ar, percent or amount}]` (together at most the value) |
| `GET /api/v1/contracts`, `GET /api/v1/contracts/{id}` | Lists them under `contracts`; one with its `statement` |
| `POST /api/v1/contracts/{id}/stages/{stage}/invoice` | Drafts the stage's invoice, its value less the retention; optional `issue_date`, `due_date` |
| `POST /api/v1/contracts/{id}/release` | Drafts the invoice releasing retention: `amount` (default all that is held), optional `issue_date`, `due_date` |

Each write answers with `contract` and `statement` (a stage or a release
also with the drafted `invoice`); the statement holds `value`, `billed`,
`retained`, `released`, `retention_held`, `remaining`, `invoiced` (all before
VAT), `retention_due_by` (when the tax on retention still held falls due:
12 months in Saudi Arabia, one year in the UAE), `stages` and `releases`
with their invoices. An issued credit note naming a stage invoice (by number
or id) takes that stage's value and retention off `billed` and `retained` in
the share of its net it credits. The invoices are drafts; issue them as any draft. In
the UAE they carry `transaction_flags` `00001000`. Writing needs
`invoice.submit` and `standard_invoices`; reading `invoice.read`.
