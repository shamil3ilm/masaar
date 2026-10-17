# Jurisdiction routing

This page describes how an invoice reaches the right country's rules. Each
country is a module plugged into one engine, and shared code asks the engine
rather than asking "which country is this?".

The engine is being built in phases. This page describes what exists now
(phase 1: the engine core and the issue and transmit routing) and marks what
later phases add.

## The engine

The engine lives in `app/Domains/Compliance/Engine/`. It names no country and
imports nothing from a country module. `CountryCheckTest` asserts both.

| Part | Class | What it does |
|---|---|---|
| Module contract | `Engine\Contracts\Jurisdiction` | A country's identity (`code()`, `name()`, `timezone()`, `currency()`), whether this deployment offers it (`enabled()`), and its ports |
| Issuing step | `Invoice\Contracts\IssuingJurisdiction` (returned by `issuingStep()`) | The country's steps inside `IssuingLifecycle`, described below |
| Transmission port | `Engine\Contracts\Transmission` | `issue()` issues a draft and, when asked, files or sends it. `resume()` handles an invoice that is already issued. `readyToFile()` says whether the organization can file now |
| Notices port | `Engine\Contracts\IssueNotices` | What the portal tells a person after issuing: `filed()` and `notFiled()` |
| Options | `Engine\DTOs\IssueOptions` | `file` (no default: filing is irreversible) and `idempotencyKey` |
| Outcome | `Engine\DTOs\IssueOutcome` | One neutral answer for every country: `issued`, `attemptedFiling`, `errors`, `warnings`, and the raw `submission` |
| Registry | `Engine\Services\JurisdictionRegistry` | The modules registered with this deployment, and which of them are enabled |
| Resolver | `Engine\Services\JurisdictionResolver` | `forOrganization()` and `forInvoice()` |
| Entry point | `Engine\Services\ComplianceEngine` | `issue()` and `resume()`, which resolve the country and delegate to its transmission. `jurisdictionFor()` gives a caller the module itself |
| Refusal | `Engine\Exceptions\JurisdictionUnavailableException` | No module for the country, or a module this deployment has switched off |

### How a country is chosen

`JurisdictionResolver::forOrganization()` reads the organization's `country`:

- A blank country means the default module. This covers rows from before
  the column was set, which were Saudi. The Saudi module registers itself
  as the default.
- A country with no registered module is refused with
  `JurisdictionUnavailableException`.
- A registered module whose `enabled()` is false is refused the same way.
  Saudi Arabia is always enabled. The UAE is enabled by `PEPPOL_AE_ENABLED`.

The pipeline API answers a refusal with `422 JURISDICTION_UNAVAILABLE`
before anything is drafted. The portal answers `404`, as it did while the
UAE was switched off.

### How modules register

Each module registers itself from its own service provider. The registry is
a singleton holding one factory per country code. A module is built from the
container each time it is asked for, so a test that swaps a collaborator
after boot still reaches the module.

| Country | Module | Registered by |
|---|---|---|
| Saudi Arabia (`SA`, default) | `Compliance\Fatoora\SaudiArabia` | `Compliance\Fatoora\FatooraServiceProvider` |
| United Arab Emirates (`AE`) | `Compliance\Peppol\UnitedArabEmirates` | `App\Providers\PeppolServiceProvider` |

Each module adapts the issuer it already had:

- **Saudi Arabia.** `issue()` calls the Fatoora `InvoiceIssuer` (sign, then
  file through `OfflineFallback`). `resume()` files an issued invoice that
  was not filed, unless it waits in the offline queue. `readyToFile()` is
  `zatca_onboarded`.
- **United Arab Emirates.** `issue()` and `resume()` call `UaeInvoiceIssuer`,
  which queues the Peppol document once when `file` is true. The document
  and any late-issue warnings are mapped into `IssueOutcome`. `readyToFile()`
  is always true, because the service provider carries every document.

### Log context

For the length of each `ComplianceEngine` call, every log line carries
`jurisdiction`, `step` (`issue` or `resume`), `org_id` and `invoice_id`. The
values are set with `Context::scope()`, which restores the previous context
when the call returns. A job queued during the call carries them too.

## Who calls the engine

| Caller | Call |
|---|---|
| `Pipeline\Services\PipelineService` | `issue()` for a draft. `resume()` for a retry that finds the invoice issued. `file` is the request's `auto_submit` |
| `Invoice\Http\Controllers\PortalInvoiceFilingController` | `issue()`, with `file` from the module's `readyToFile()` and the flash text from its notices |
| `Billing\Services\Billing` | `issue()` with `file: true`, for the billing organization's own invoice |

The country APIs keep their own routes. `/api/compliance/sa/*` reaches
Fatoora directly. `/api/compliance/ae/*` sits behind the `uae.invoicing`
middleware, which answers `404` while `PEPPOL_AE_ENABLED` is off and `403`
for an organization whose `country` is not `AE`.

An organization has one country. Issuing for a second jurisdiction from the
same organization is not supported.

## The seam: one lifecycle, one step per country

Issuing is written once, in `App\Domains\Invoice\Services\IssuingLifecycle`.
Each country supplies its part by implementing
`App\Domains\Invoice\Contracts\IssuingJurisdiction`, which its module returns
from `issuingStep()`.

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

Filing is not part of the seam. It belongs to the module's transmission. A
ZATCA clearance or report answers at once and runs after issuing, in
`InvoiceIssuer`, which also raises `invoice.issued` for Saudi invoices. A
Peppol document is handed to the accredited service provider and settled
later by callbacks.

## What every country shares

- **Drafting.** `InvoiceDrafter` makes the draft for both. Only the request
  rules and `jurisdiction_data` are country-specific.
- **Issuing.** `IssuingLifecycle`, described above, reached through the
  engine.
- **Numbering.** `InvoiceNumbering` allocates from the organization's
  sequence. The date in a number and the period a counter restarts at are read
  on the organization's own clock (`App\Support\CountryClock`: Riyadh for
  `SA`, Dubai for `AE`).
- **Clearance.** `Organization\Services\FilingClearance` decides whether an
  organization is approved, not suspended and on a plan. It still throws
  `FatooraException`, because its callers catch that type.
- **Notification.** `Invoice\Services\IssuanceNotifier` raises
  `invoice.issued`.

## Country checks still in shared code

`tests/Feature/Architecture/CountryCheckTest.php` lists every literal country
comparison (`country === 'AE'` and the like) left in `app/` outside the
country modules and the engine. The list must match exactly, so it can only
shrink: a new check fails the build, and a removed one fails until it is
delisted. The issuing callers above have none left.

## Later phases

These are not built yet. Each adds a port to `Jurisdiction` and moves the
matching country checks out of shared code:

- **Rules.** Drafting and request validation (`CreateInvoiceRequest`,
  `CompanyProfileRules`, signup and customer fields) ask the module.
- **Presentation.** Forms, invoice pages, the dashboard and the setup
  checklist. `IssueNotices` becomes part of this port.
- **Reports.** VAT summaries and the portal's reports.
- **Moves.** `CountryClock` and the remaining checks read the module's
  `timezone()` and `currency()`, and the country APIs route through the
  engine.

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
