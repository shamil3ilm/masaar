# Feature access

This page describes how the platform answers one question: may this
organization, and this person's role in it, use feature X in its country
right now, and if not, why?

The answer comes from one place, `App\Domains\Platform\Access\Access`. It is
not a new switch system. It reads the mechanisms that already decide, in a
fixed order, and the first one that says no gives the answer. The only new
switches are a platform admin's overrides: for the organization, and below
it for one role in it or one person.

## The decision

`Access::decide(Organization $organization, ?User $user, Feature $feature)`
returns a `Decision`:

| Field | Meaning |
|---|---|
| `allowed` | Whether the feature can be used now |
| `reason` | Why, in words a person can act on. Translated |
| `source` | The level that decided (`Level`): `platform`, `country`, `plan`, `organization`, `role` or `user`. Null when nothing refused. `plan` on an allowed answer means a platform admin granted the feature beyond the plan |
| `code` | A stable name for the reason, for code that branches on it (see the table below) |
| `detail` | The facts behind it: the kill switch and its reason, the country code, the licence feature and tiers, the status, the override, the role |

`Access::allows()` is the same answer as a boolean. `Access::all()` answers
every feature for an organization with no person, which is what the admin
console shows.

## The levels

Each level is asked in this order. The first one that refuses decides.

| Level | What it reads | Codes |
|---|---|---|
| Platform | Global kill switches (`KillSwitch`): the ones every country obeys for the feature, plus the ones the country adds | `kill_switch` |
| Country | The organization's module (`JurisdictionRegistry`). No module for the country; the country switched off (`JurisdictionAvailability`), only for features that start new work with the authority; the feature not part of the country (`FeatureSupport::supportsFeature()`) | `no_module`, `country_off`, `not_in_country` |
| Plan | The organization's active, unexpired licences (`LicenseValidationService`). Their features grant the feature; the ERP pipeline needs a licence with the `invoice.submit` scope | `plan_excludes`, `scope_missing`, `no_licence`, `no_plan` |
| Organization | Its review status (`FilingClearance::assertMayFile()`), for features that start new work; a kill switch set for this organization only; its account frozen for an overdue payment (`AccountStanding`, see below); a platform admin's override that switches the feature off | `status`, `org_kill_switch`, `account_frozen`, `override_off` |
| Role | The person's role in the organization (`User::roleIn()`): viewer, member or admin. Then a platform admin's switch for that role in the organization. Skipped when there is no person, as on the partner API | `not_member`, `role`, `role_override_off` |
| User | A platform admin's switch for this one person in the organization | `user_override_off` |

An allowed answer has the code `allowed`, or `override_on` when a platform
admin granted the feature beyond the plan.

A switch for a role or a person is off only: nothing below the organization
grants what a broader level refused. It exists only for features every path a
person takes asks Access about with the person (`Feature::canSwitchForPeople()`,
issuing): the portal's issue action asks `decide()`, and the API's issuing
routes ask the person's levels alone through `access.person`
(`Access::personDecision()`), keeping their own error codes for the rest.

Two rules keep the answer the same as before:

- **No licence.** The partner API cannot be reached without a licence key, so
  API access and the ERP pipeline answer `no_licence`. Issuing needs a plan
  only when `licensing.filing_requires_licence` is on, and then only once the
  company is approved: a company in review gets its plan when it is approved,
  so the organization level says why it waits. Every other feature is
  unaffected by having no licence, as it was before.
- **Plan features.** A licence's feature list is enforced on the partner API
  only (`license.feature`). The portal issues every kind of document whatever
  the plan, as it always has. The portal's issue button therefore asks for
  `issue_invoices`, which no plan restricts, not for the document's type.

Quotas are not part of the answer. A quota is a count checked on each API
call (`license.quota`), not a switch.

## The features

`App\Domains\Platform\Access\Feature`:

| Feature | Value | Kill switches (every country) | Plan feature | Smallest role | Per-organization switch |
|---|---|---|---|---|---|
| Issue invoices | `issue_invoices` | `issuance` | | member | off |
| Simplified (B2C) invoices | `issue_simplified` | `issuance` | `simplified_invoices` | member | off, on |
| Standard (B2B) invoices | `issue_standard` | `issuance` | `standard_invoices` | member | off, on |
| Credit notes | `credit_notes` | `issuance` | `credit_notes` | member | off, on |
| Debit notes | `debit_notes` | `issuance` | `debit_notes` | member | off, on |
| Filing and sending | `file_with_authority` | | | member | |
| Receiving invoices | `receive_invoices` | | | viewer | |
| PDF and email | `pdf_and_email` | | | member | |
| API access | `api_access` | | licence key | | |
| ERP pipeline | `erp_pipeline` | | licence key with `invoice.submit` | | off |
| Batch submission | `batch_submission` | | licence key | | off |
| Webhooks | `webhooks` | | `webhook_notifications` | admin | on |
| Offline mode | `offline_mode` | | `offline_mode` | | |
| Adding people | `add_users` | | | admin | |

The issuing features and filing start new work with the authority. They are
refused while the country is switched off and while the organization is in
review, suspended or turned down. Reading, downloading and the API's read
routes stay open in every case.

Each country answers through the engine's `FeatureSupport` port, returned by
`Jurisdiction::features()`. The port has two methods:

- `supportsFeature()` says whether the feature exists in the country at all.
- `killSwitchesFor()` names the kill switches the country's own steps obey
  beyond the shared ones. For example, a country whose issuing signs adds
  `signing`, and a country that files with its authority adds `submission`.

Shared code never names a country. Each country's own module says what it
supports.

## Per-organization overrides

A platform admin can switch a feature off for one organization, or grant it
beyond the plan. The switch lives in `organization_feature_overrides` (model
`Platform\Models\FeatureOverride`, service `Platform\Access\FeatureOverrides`),
with one row per organization and feature. Each row stores the state (`off`
or `on`), the reason and who set it. Having no row means the default.

- **Off** refuses the feature at the organization level, whatever the plan
  says. It can only be set on features where every path that uses the
  feature honours it:
  - Issuing: each country's issuing step calls
    `FeatureOverrides::assertNotSwitchedOff()`, so the portal, the pipeline,
    the API and billing are all covered.
  - The ERP pipeline: `PipelineService` calls the same method before
    anything is drafted.
  - Batch submission: `PipelineService::assertMayBatch()`, before the
    batch's first invoice. No plan restricts batching while allowances are
    unlimited; it is each customer's unless switched off.

  On the API, the refusal is `403 AUTH_014` (`AUTH_FEATURE_SWITCHED_OFF`).
- **On** lifts the plan's feature list, and nothing else. A kill switch, the
  country, the organization's status and a role still refuse. It can only be
  set on features whose plan check goes through Access, which is the partner
  API's `license.feature` check.
- Filing an invoice that was already issued cannot be switched off per
  organization. Reporting or clearing an issued invoice is the taxpayer's
  obligation, as `FilingClearance::assertMayFile()` explains. The
  per-organization `submission` kill switch remains for an incident.

The switches are cached per organization (`access.overrides.<id>`). The cache
is cleared when a switch changes, and again after the write commits.

## The overdue-payment freeze

The organization level also asks where the account stands with the platform
(`AccountStanding`, answered by `Billing\Services\Freeze`): the policy in
Admin › Finance › Settings - shipped off - or a platform admin's hand
(`accounts.freeze`, `accounts.unfreeze`, under their approval policies). A
customer whose oldest unpaid invoice is past due is reminded, given its grace,
then restricted (read-only: issuing new invoices, adding people, the ERP
pipeline and batch submission refused), then frozen (webhooks and offline
mode too). Each stage has its days; a customer can have its own, or be
exempt. Every refusal is one decision, code `account_frozen`, reason
"Account frozen: payment overdue.", with the stage in `detail`.

No stage ever refuses reading and exporting the organization's own records,
filing or sending what it already issued, receiving, PDFs, the API's reads, or
correcting an issued invoice with a credit or debit note
(`Standing::FEATURES_KEPT`). `FeatureOverrides::assertNotSwitchedOff()`, which
every issuing path asks, holds the freeze for new invoices the same way. The
ERP pipeline names a note beside `ErpPipeline` (and a batch holding one beside
`BatchSubmission`), so a credit or debit note goes through it as it does
through the portal and the API, while each new invoice is refused.

## Where Access is used

| Place | How |
|---|---|
| Portal issue (`PortalInvoiceFilingController::issue`) | `decide(..., IssueInvoices)` before issuing. A refusal is flashed with its reason. A country that is switched off keeps its old wording |
| Portal invoice page | `@canuse('issue_invoices')` shows the Issue button only when it is allowed. A viewer sees nothing; anyone else sees why it cannot be issued now |
| Partner API `license.feature` (`RequireLicenseFeature`) | `Access::licenceAllows()`, the plan level for the request's licence. It answers as the licence did, unless a feature was granted beyond the plan. The error code and message are unchanged |
| Every country's issuing step, `PipelineService` | `FeatureOverrides::assertNotSwitchedOff()` |
| Admin console, organization page | The Features section (`FeatureReport`) |

Some places still make their own checks, and Access reads the same
mechanisms:

- Each country's issuing step still checks its kill switches and calls
  `FilingClearance::assertCleared()`. These run on every path, including
  background work, so they stay where they are. Access calls the same
  `FilingClearance` methods and reads the same switches.
  `tests/Feature/Platform/AccessAgreementTest.php` checks that both give the
  same answer.
- `OrgWriter` and `IsOrgAdmin` still gate routes by role. The role level uses
  the same roles, and `AccessAgreementTest` checks that they agree.
- `jurisdiction.available` still guards each country's API write routes. It
  refuses exactly when the country level answers `country_off` or
  `no_module`.

## Adding a feature

1. Add a case to `Feature`. Give it a `label()`, and answer `issues()`,
   `killSwitches()`, `startsFiling()`, `licenceFeature()`,
   `needsLicenceKey()` and `minimumRole()` from the mechanisms that already
   govern it.
2. Answer `supportsFeature()` (and `killSwitchesFor()` where the country's
   own steps check a switch) in every country module.
3. Decide whether a platform admin may switch it off or grant it beyond the
   plan (`canSwitchOff()`, `canGrantBeyondPlan()`). Say yes only if every
   path that uses the feature asks `FeatureOverrides` or `Access`, and wire
   those paths.
4. Gate the action with `Access::decide()`, or `@canuse('<value>')` on a
   portal page.
5. Add its row to the tables above and its Arabic label.

## Why a customer cannot do X

Open the organization in the admin console (**Organizations**, then the
company) and read its **Features** section:

- **State** is the answer now.
- **Decided by** is the level that refused.
- **Reason** is what the customer is told, with its code under it.
- **Governed by** lists the mechanisms that can change the answer.

Then go to the place that matches the level:

| Decided by | Where to look |
|---|---|
| Platform | **Kill switches**. The reason shows who enabled it and why |
| Country | **Countries**, or the country's module (`supportsFeature`) when the feature is not part of that country |
| Plan | **Plan and billing**, or the company's licence under **Licences** |
| Organization | The review banner at the top of the page, a kill switch set for this company, or the **Switch for this company** column |
| Role | Not shown here, because the section has no person. The customer's administrator changes roles on the portal's Team page |

A person's own answer, including the role level, is
`Access::decide($organization, $user, $feature)`.

See also [JURISDICTION-ROUTING.md](JURISDICTION-ROUTING.md).
