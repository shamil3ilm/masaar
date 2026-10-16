# Jurisdiction routing

This page describes how an invoice reaches the right country's rules today,
and the design that is planned to replace it. The two are kept apart below,
so you can see what exists and what is only planned.

## Today

### Each country has its own routes and services

The platform files invoices in two jurisdictions, and each has its own entry
points.

| Jurisdiction | Entry points | What they call |
|---|---|---|
| Saudi Arabia (ZATCA) | `/api/compliance/sa/*`, the pipeline API, and the portal's filing actions | The ZATCA services directly. `PipelineService` and the Fatoora `InvoiceIssuer` issue, hash, sign and submit through `Submitter` and `OfflineFallback` |
| United Arab Emirates (Peppol PINT AE) | `/api/compliance/ae/*`, behind the `uae.invoicing` middleware | `UaeInvoiceController` drafts with the shared `InvoiceDrafter` and issues with `UaeInvoiceIssuer`. That class uses the shared `InvoiceNumbering` and `IssuanceNotifier`, then the Peppol `PeppolPreparer` and `PeppolSender` |

The route decides the jurisdiction. The country check happens in the
`uae.invoicing` middleware:

- It answers `404` while `PEPPOL_AE_ENABLED` is off.
- It answers `403` for an organization whose `country` is not `AE`.

An organization has one country. Issuing for a second jurisdiction from the
same organization is not supported by either flow.

### ComplianceRouter exists, but no flow uses it

`app/Domains/Compliance/ComplianceRouter.php` resolves a `ComplianceEngine`
from a `ComplianceProfile`'s `jurisdiction`. It asks each registered engine
`supports($jurisdiction)` and throws `UnsupportedJurisdictionException` when
none matches. Engines are registered in
`app/Providers/ComplianceServiceProvider.php`:

```php
$this->app->tag([FatooraEngine::class, PeppolEngine::class], 'compliance.engines');
```

| Engine | Jurisdiction | What `submit()` does |
|---|---|---|
| `Fatoora\FatooraEngine` | `SA` | Files with ZATCA |
| `Peppol\PeppolEngine` | `AE` | Prepares and stores the Peppol document in status `ready`, where the transport picks it up. There is no authority to submit to: the submission id is the document id, and `retry()` reports status rather than sending again |

```php
interface ComplianceEngine
{
    public function supports(string $jurisdiction): bool;
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult;
    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult;
    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult;
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult;
}
```

**No controller, job or service in `app/` calls the router.** Only its tests
use it. The Saudi flows call the ZATCA services directly, and the UAE flow
calls the Peppol services directly. So a new engine registered here is not
reachable by any request until a flow is written for it.

The interface also fits the two countries poorly. It assumes one
`submit` that returns an answer. A ZATCA clearance does answer at once, but a
Peppol document is handed to a provider and settled later by callbacks.

### What the two countries already share

Some parts are already common to both countries:

- **Drafting.** `InvoiceDrafter` makes the draft for both. Only the request
  rules and `jurisdiction_data` are country-specific.
- **Numbering.** `InvoiceNumbering` allocates from the organization's
  sequence.
- **Notification.** `IssuanceNotifier` raises `invoice.issued`. It still
  lives in the Fatoora namespace.

The issue lifecycle itself is written twice:

- The Fatoora `InvoiceIssuer` interleaves it with the counter, hash chain and
  signature.
- `UaeInvoiceIssuer` keeps it apart from the UAE step, so that the step can be
  lifted out.

## Planned: a common issuer with per-jurisdiction steps

> **Not built.** This is the intended direction. No class described in this
> section exists yet.

The plan has one issuer that runs the lifecycle every country shares, and
calls two steps that each jurisdiction supplies.

| Common to every jurisdiction | Supplied by each jurisdiction |
|---|---|
| Drafting (`InvoiceDrafter`) | **Seal / document:** turn the issued invoice into the country's record. For Saudi Arabia: counter, previous hash, XAdES signature and QR code. For the UAE: map to PINT, check the mandatory fields and scenarios, check the totals, and build the UBL. Problems are reported before anything is written |
| Numbering (`InvoiceNumbering`) | **Transmit:** hand the record over. For Saudi Arabia: clearance or reporting with ZATCA. For the UAE: queue to the accredited service provider and follow its callbacks |
| The issue lifecycle: under the organization's lock, a draft takes its number and its moment of issue and becomes issued. A refusal leaves it a draft. A repeated call issues nothing new | |
| Notifications (`invoice.issued`) | |

Each jurisdiction would be selected from the organization's compliance
profile, which could reuse `ComplianceRouter`, with the engine contract
reshaped around those two steps.

These questions are still open:

- **How the Saudi step is extracted.** Issuing and signing share a transaction
  and a chain position there, and a submission failure must never roll back
  issuance.
- **How status is exposed.** Status is synchronous in Saudi Arabia and driven
  by callbacks in the UAE, and the API needs to show both consistently.

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
