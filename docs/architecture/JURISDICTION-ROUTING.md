# Jurisdiction routing

This page describes how an invoice reaches the right country's rules: the
entry points that pick a country, and the one seam every country plugs into
when an invoice is issued.

## Each country has its own entry points

The platform files invoices in two jurisdictions, and each has its own entry
points.

| Jurisdiction | Entry points | What they call |
|---|---|---|
| Saudi Arabia (ZATCA) | `/api/compliance/sa/*`, the pipeline API, and the portal's filing actions | `PipelineService` and the Fatoora `InvoiceIssuer`, which issue through `Submitter` and then file through `OfflineFallback` |
| United Arab Emirates (Peppol PINT AE) | `/api/compliance/ae/*`, behind the `uae.invoicing` middleware, and the portal's filing actions | `UaeInvoiceController` drafts with the shared `InvoiceDrafter` and issues with `UaeInvoiceIssuer`, which then queues the document through `PeppolSender` |

The route decides the jurisdiction, and the portal decides from the
organization's `country`. The country check for the API happens in the
`uae.invoicing` middleware:

- It answers `404` while `PEPPOL_AE_ENABLED` is off.
- It answers `403` for an organization whose `country` is not `AE`.

An organization has one country. Issuing for a second jurisdiction from the
same organization is not supported by either flow.

## The seam: one lifecycle, one step per country

Issuing is written once, in `App\Domains\Invoice\Services\IssuingLifecycle`.
Each country supplies its part by implementing
`App\Domains\Invoice\Contracts\IssuingJurisdiction`.

| Jurisdiction | Implementation | Called from |
|---|---|---|
| Saudi Arabia | `Compliance\Fatoora\Services\ZatcaJurisdiction` | `Submitter::generate()` |
| United Arab Emirates | `Compliance\Peppol\Services\UaeJurisdiction` | `UaeInvoiceIssuer::issue()` |

`IssuingLifecycle::issue($invoice, $organization, $jurisdiction, $reissue)`
owns what every country shares:

- the guard against issuing an invoice that is no longer a draft;
- the transaction, holding the organization row's lock;
- the number, from `InvoiceNumbering`, when the draft has none;
- the moment of issue, set once;
- status `issued`, saved with the columns the country's seal returns.

It calls the jurisdiction at fixed points, in this order:

| Step | When | Saudi Arabia | United Arab Emirates |
|---|---|---|---|
| `refuseIssued()` | Before anything, for an invoice that is not a draft | `FatooraException` | `IssuanceRefusedException` |
| `prepare()` | Before the lock. Throwing refuses, and nothing is written | Country check, kill switches, clearance, ZATCA's rules, signing credentials | Kill switch, clearance |
| `refuseIssuedMeanwhile()` / `finished()` | Under the lock, when another request issued the draft first | Refuses, or returns the finished result on a reissue | Returns the stored document |
| `beforeNumbering()` | Under the lock, before the number | Allocates the counter for the EGS unit | Nothing |
| `seal()` | Once the number and moment exist | Hash, XAdES signature and QR code | Mandatory fields, scenarios and totals. Problems refuse and roll the number back |
| `afterIssued()` | After the invoice is saved as issued | Records the hash-chain entry | Stores the Peppol document |
| `afterCommit()` | After the transaction | Nothing | `invoice.issued`, then the queued send |

Filing is not part of the seam. A ZATCA clearance or report answers at once
and runs after issuing, in `InvoiceIssuer`, which also raises
`invoice.issued` for Saudi invoices. A Peppol document is handed to the
accredited service provider and settled later by callbacks.

## What every country shares

- **Drafting.** `InvoiceDrafter` makes the draft for both. Only the request
  rules and `jurisdiction_data` are country-specific.
- **Issuing.** `IssuingLifecycle`, described above.
- **Numbering.** `InvoiceNumbering` allocates from the organization's
  sequence. The date in a number and the period a counter restarts at are read
  on the organization's own clock (`App\Support\CountryClock`: Riyadh for
  `SA`, Dubai for `AE`).
- **Clearance.** `Organization\Services\FilingClearance` decides whether an
  organization is approved, not suspended and on a plan. It still throws
  `FatooraException`, because its callers catch that type.
- **Notification.** `Invoice\Services\IssuanceNotifier` raises
  `invoice.issued`.

## Routing key

`ComplianceProfile.jurisdiction` is an ISO 3166-1 alpha-2 code. An
organization has at most one profile per jurisdiction, because
`(org_id, jurisdiction)` is unique. Who must file is decided by each
country's law, not by this platform:

- **Saudi Arabia** applies to persons resident in Saudi Arabia (see
  [the Saudi README](../sa/README.md)).
- **The UAE** applies to anyone doing business in the UAE (see
  [the UAE README](../ae/README.md)).

See also [ADDING-A-JURISDICTION.md](ADDING-A-JURISDICTION.md) and
[ORGANIZATION-MODEL.md](ORGANIZATION-MODEL.md).
