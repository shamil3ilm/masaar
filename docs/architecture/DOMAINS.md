# Domains

Audience: engineers deciding where new code goes and what it may depend on.

Code lives in `app/Domains/<Domain>`. A domain owns its models; another
domain's data arrives through that domain's services. Two ratchets enforce
this, and both fail on any change — a new dependency until it is listed, a
removed one until it is delisted — so dependencies are seen to shrink:

- `tests/Feature/Architecture/CrossDomainModelTest.php` — which files import
  another domain's Eloquent models. `Organization` and `User` are the shared
  kernel and may be imported by anyone. Each country module and the Peppol
  network count as domains of their own.
- `tests/Feature/Architecture/ModuleBoundaryTest.php` — which shared files
  name a country or network class; countries never name each other; the
  network and the engine name no country. See
  [JURISDICTION-ROUTING.md](JURISDICTION-ROUTING.md).

## The domains

| Domain | Purpose | Key services | Model imports it is allowed (beyond Organization, User) |
|---|---|---|---|
| **Audit** | Who did what: `audit_logs`, actor and channel attribution (`Attribution\Channel`, `Origin`) | `AuditService`, `ActivityLog` | none |
| **Auth** | Sign-in, two-factor, JWT, self sign-up, platform admins and ownership | `JwtAuthenticator`, `TwoFactor`, `SelfSignup`, `PlatformOwnership`, `SignInThrottle` | `User` imports `Organization` (memberships pivot) |
| **Billing** | The platform's own finance: plans, receivables, payments, refunds, expenses, payables, exchange rates, reconciliation, freeze — see [BOOKS-AND-FINANCE.md](BOOKS-AND-FINANCE.md) | `Billing`, `PlatformInvoices`, `Receivables`, `Payments`, `Refunds`, `Statements`, `ExchangeRates`, `Freeze`, `Reconciliation\Services\Reconciliation` | Invoice (its invoices are issued as ordinary invoices), Licensing (plans and renewals) |
| **Books** | A tenant's own books: receivables, customer payments, purchases, supplier payments, vouchers, deemed supplies, bad-debt relief, sales reports, VAT return page | `Receivables`, `CustomerPayments`, `Purchases`, `PurchaseLedger`, `PurchasePayments`, `Vouchers`, `DeemedSupplies`, `SalesBadDebts`, `SalesReports` | Invoice (read-only: what an issued invoice owes; a voucher pays one) |
| **Branding** | Themes, palettes and brand assets per organization | `ThemeService`, `Palette`, `BrandAssets` | none |
| **Compliance\Engine** | Country-agnostic ports (`Contracts\Jurisdiction` and the rest) and their routing | `ComplianceEngine`, `JurisdictionRegistry`, `JurisdictionResolver`, `VatReturnTally` | Invoice (the documents it routes). Names no country or network |
| **Compliance\Countries\SaudiArabia** | ZATCA: document, signing, chain, onboarding, clearance and reporting, offline queue | `IssuingStep`, `DocumentBuilder`, `SubmissionLedger`, `SubmissionGuard`, `OfflineQueue`, `CredentialStore`, `CertificateService` | Invoice, Organization (tills), Audit (pilot rehearsal only) |
| **Compliance\Countries\UnitedArabEmirates** | PINT AE: document, rules, e-invoicing start date, provider appointment | `IssuingStep`, `PintMapper`, `PeppolPreparer`, `EinvoicingStart`, `IssueDeadline` | Invoice, Organization, Compliance\Networks\Peppol |
| **Compliance\Networks\Peppol** | The Peppol network any country sends through: sending, receiving, status tracks, simulator | `PeppolSender`, `DocumentStatus`, `DocumentHistory`, `InboundDocuments`, `AspGatewayResolver` | Organization (the receiving profile). Names no country |
| **Invoice** | Invoices and notes from draft to issue, numbering, PDFs, catalogue, schedules, imports, returns and exchanges, summary invoices from delivery notes, contracts billed in stages | `InvoiceDrafter`, `IssuingLifecycle`, `NoteIssuing`, `InvoiceNumbering`, `InvoicePdf`, `VatSummary`, `ScheduleRunner`, `InvoiceAdjustments`, `SummaryInvoices`, `ProgressBilling` | Organization (the `Invoice` model's branch, till and profile), Platform (note approvals). A country's filing record is read through `Invoice\Contracts\FilingRecord` (`Invoice::latestFiling()`); the module registers the relations |
| **Licensing** | Partner licences, API keys, usage metering, request log | `ApiKeys`, `LicenseValidationService`, `UsageMeteringService`, `RequestLog` | Invoice (licence feature middleware), Platform (approval requests for keys) |
| **Organization** | Organizations, groups, branches, tills, memberships, compliance profiles, setup checklist | `Registrar`, `BranchService`, `Tills`, `Membership`, `OrganizationStatus`, `FilingClearance`, `TenantResolver` | Invoice |
| **Pipeline** | One-call create-issue-file endpoint for integrations | `PipelineService`, `PipelineResult` | Invoice, Organization (branch routing) |
| **Platform** | Operator console and cross-cutting rules: feature access, approvals, customization, kill switch, status, public website | `Access\Access`, `Approvals\Approvals`, `Customization\Customizations`, `KillSwitch`, `PlatformStatus`, `IssueDetector` | Licensing (plan level, approved API keys) |
| **Referral** | Referral codes and rewards | `Referrals`, `ReferralRewards`, `SelfReferral` | none |
| **Support** | Support requests, feature board, paid customizations | `Requests`, `SupportDesk`, `FeatureBoard` | none |
| **Webhook** | Outbound webhooks: subscriptions, signed deliveries, history | `WebhookService`, `Subscriptions`, `DeliveryHistory` | none |

The "allowed" column summarises the ratchet's `DECLARED` list by domain; the
list itself, file by file and with a reason for each, is the authority.

## Rules that hold across domains

- **Country behaviour sits behind a port.** Shared code asks
  `ComplianceEngine` for the organization's jurisdiction rather than testing a
  country (`CountryCheckTest` holds such checks at zero). The shared files
  still naming a module are listed in `ModuleBoundaryTest::SHARED`.
- **Tenancy.** Tenant-owned models carry `org_id` and the tenant scope;
  `RawTenantQueryTest` lists the few files allowed raw queries on tenant
  tables. See [TENANT-DATABASES.md](TENANT-DATABASES.md).
  - With a tenant (a request's, or one a command or job declares with
    `TenantResolver::runAs()`), every query is held to it.
  - A request with no tenant matches nothing (`1 = 0`).
  - A queued job, or a command of the application's own, that declared no
    tenant is refused its first tenant-scoped query (`TenantNotDeclaredException`)
    rather than handed every organization's rows. It declares one with
    `runAs()` (a job with the `ActsFor` middleware), or, for work that spans
    organizations by design, carries `#[RunsAcrossTenants('reason')]`;
    `TenantDeclarationTest` lists those classes as an equality ratchet.
  - Console outside such work - a seeder, tinker, the framework's own
    commands - stays unscoped.
  - A query that must read across tenants says so itself:
    `Model::acrossTenants()`, or `->acrossTenants()` on any builder of a
    tenant model, which is `withoutGlobalScope(TenantScope::class)`. It covers
    that query only. An eager load or `whereHas` on another tenant model says
    so for its own query.
  - Work for one organization from a context that has none (a platform admin
    screen, a command) runs inside `runAs()` instead.
  - `TenantBypassTest` counts the bypasses in `app/` by domain, as an
    equality ratchet, and holds the old callback form at zero.
- **Attribution.** Actor, channel and request columns are written by the
  `RecordsActors` concern and `Origin`, never by hand; every table declares its
  timestamps class and attribution in `tests/Feature/Architecture/TableTimestamps.php`.
- **Services, not locators.** Dependencies are constructor-injected
  (`ServiceLocatorTest`, `WiredDependencyTest`); controllers stay thin
  (`ControllerQueryTest`).
