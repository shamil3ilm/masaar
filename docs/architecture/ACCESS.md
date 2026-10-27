# Feature access

Audience: engineers changing who may use what, and platform support
answering "why can't this customer do X".

One question, one place: may this organization, and this person in it, use
feature X in its country now, and if not, why? `Platform\Access\Access`
reads the mechanisms that already decide, in a fixed order, and the first
that refuses gives the answer. The only switches of its own are a platform
admin's overrides.

## The decision

`Access::decide(Organization, ?User, Feature)` returns a `Decision`:
`allowed`, `reason` (translated, actionable), `source` (the `Level` that
decided; null when nothing refused), `code` (stable, for code that branches)
and `detail` (the facts behind it). `allows()` is the boolean; `all()`
answers every feature for an organization with no person (the console's
view); `licenceAllows()` is the plan level for a partner-API licence;
`personDecision()` is the role and user levels alone (`access.person` on the
API's issuing routes).

| Level, in order | Reads | Codes |
|---|---|---|
| Platform | Global kill switches (`KillSwitch`): the feature's own plus the country's (`FeatureSupport::killSwitchesFor()`) | `kill_switch` |
| Country | The module (`JurisdictionRegistry`); the switch (`JurisdictionAvailability`, only for features that start new work); `FeatureSupport::supportsFeature()` | `no_module`, `country_off`, `not_in_country` |
| Plan | Active licences (`LicenseValidationService`); the ERP pipeline needs the `invoice.submit` scope | `plan_excludes`, `scope_missing`, `no_licence`, `no_plan` |
| Organization | Review status (`FilingClearance::assertMayFile()`, for new work); its own kill switch; the overdue-payment freeze; an admin's override off | `status`, `org_kill_switch`, `account_frozen`, `override_off` |
| Role | `User::roleIn()` (viewer, member, admin), then an admin's switch for that role. Skipped with no person | `not_member`, `role`, `role_override_off` |
| User | An admin's switch for this person | `user_override_off` |

An allowed answer is `allowed`, or `override_on` when an admin granted the
feature beyond the plan. Issuing needs a plan only when
`licensing.filing_requires_licence` is on, and only once the company is
approved. A licence's feature list is enforced on the partner API only
(`license.feature`); the portal issues every document kind whatever the
plan. Quotas (`license.quota`) are counts, not part of the answer.

## The features

`Platform\Access\Feature`:

| Feature | Value | Plan feature | Smallest role | Admin may switch |
|---|---|---|---|---|
| Issue invoices | `issue_invoices` | | member | off; also per role and person |
| Simplified (B2C) invoices | `issue_simplified` | `simplified_invoices` | member | off, on |
| Standard (B2B) invoices | `issue_standard` | `standard_invoices` | member | off, on |
| Credit notes | `credit_notes` | `credit_notes` | member | off, on |
| Debit notes | `debit_notes` | `debit_notes` | member | off, on |
| Filing and sending | `file_with_authority` | | member | |
| Receiving invoices | `receive_invoices` | | viewer | |
| PDF and email | `pdf_and_email` | | member | |
| API access | `api_access` | licence key | | |
| ERP pipeline | `erp_pipeline` | licence key with `invoice.submit` | | off |
| Batch submission | `batch_submission` | licence key | | off |
| Webhooks | `webhooks` | `webhook_notifications` | admin | on |
| Offline mode | `offline_mode` | `offline_mode` | | |
| Adding people | `add_users` | | admin | |
| Books | `books` | | viewer (recording: not a viewer) | off |

The five issuing features obey the `issuance` kill switch. Issuing and
filing start new work with the authority: refused while the country is off
and while the organization is in review, suspended or turned down. Reading,
downloading and the API's reads stay open in every case.
<!-- app/Domains/Platform/Access/Feature.php: killSwitches(), minimumRole(), canSwitchOff(), canGrantBeyondPlan(), canSwitchForPeople() -->

## Overrides

`organization_feature_overrides` (model `Platform\Models\FeatureOverride`,
service `Platform\Access\FeatureOverrides`) holds one row per organization,
feature and subject: empty for the organization, `role:<role>` or
`user:<id>`. Each row has the state (`off` or `on`), the reason and who set
it; no row means the default. Changing one is the `features.override`
approval action. Cached as `access.overrides.<id>`, cleared on write and
after commit.
<!-- app/Domains/Platform/Access/FeatureOverrides.php; database/migrations/0890_feature_override_subjects.php -->

- **Off** refuses at the organization level whatever the plan says, and only
  where every path honours it: each country's issuing step and
  `PipelineService` call `FeatureOverrides::assertNotSwitchedOff()`; batches
  go through `PipelineService::assertMayBatch()`; every Books route sits
  behind `BooksAccess`. On the API the refusal is `403 AUTH_014`
  (`AUTH_FEATURE_SWITCHED_OFF`).
- **On** lifts the plan's feature list only; a kill switch, the country, the
  status and a role still refuse.
- **Role and person** switches are off-only, and only for `issue_invoices`.
- Filing an invoice already issued cannot be switched off per organization:
  it is the taxpayer's obligation. The per-organization `submission` kill
  switch remains for incidents.

## The overdue-payment freeze

The organization level asks `AccountStanding` (answered by
`Billing\Services\Freeze`): the policy in Admin › Finance › Settings,
shipped off, or an admin's hand (`accounts.freeze`, `accounts.unfreeze`).
Stages run reminders → grace → restricted (read-only: new invoices, adding
people, the pipeline and batches refused) → frozen (webhooks and offline
mode too), each with its days; a customer can have its own or be exempt.
Every refusal is `account_frozen`, with the stage in `detail`. No stage
takes away `Standing::FEATURES_KEPT`: filing, receiving, PDFs, the API's
reads, and credit and debit notes.
<!-- app/Domains/Platform/Access/Standing.php -->

## Where Access is used

| Place | How |
|---|---|
| Portal issue (`PortalInvoiceFilingController::issue`) | `decide(..., IssueInvoices)`; a refusal is flashed with its reason |
| Portal pages | `@canuse('<value>')` |
| Partner API `license.feature` (`RequireLicenseFeature`) | `Access::licenceAllows()` |
| API issuing routes | `access.person` (`PersonAccess`) |
| Issuing steps, `PipelineService` | `FeatureOverrides::assertNotSwitchedOff()` |
| Admin console, organization page | The Features section (`FeatureReport`) |

The issuing steps still check their kill switches and
`FilingClearance::assertCleared()` on every path, background work included;
`OrgWriter` and `IsOrgAdmin` still gate routes by role; and
`jurisdiction.available` refuses exactly when the country level says
`country_off` or `no_module`. `tests/Feature/Platform/AccessAgreementTest.php`
keeps each of these agreeing with Access.

## Adding a feature

1. Add a case to `Feature`, with `label()`, `issues()`, `killSwitches()`,
   `startsFiling()`, `licenceFeature()`, `needsLicenceKey()` and
   `minimumRole()`.
2. Answer `supportsFeature()` (and `killSwitchesFor()`) in every country.
3. Allow `canSwitchOff()` or `canGrantBeyondPlan()` only if every path asks
   `FeatureOverrides` or `Access`.
4. Gate the action with `Access::decide()` or `@canuse`, and add the Arabic
   label and a row above.

## Why a customer cannot do X

Open the organization in the admin console and read **Features**: State,
Decided by, Reason (with its code) and Governed by. Then: Platform →
**Kill switches**; Country → **Countries**, or the module's
`supportsFeature`; Plan → **Plan and billing** or **Licences**; Organization
→ the review banner, the company's kill switch or **Switch for this
company**; Role → the customer's own Team page.
