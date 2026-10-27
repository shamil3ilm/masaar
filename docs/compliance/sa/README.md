# Saudi Arabia — Fatoora (ZATCA Phase 2)

Audience: engineers, operators and compliance reviewers who need the shape of
the Saudi module. The rules are in [RULES.md](RULES.md), the cryptography in
[HASHING-AND-SIGNING.md](HASHING-AND-SIGNING.md), the counter and chain in
[ICV-PIH.md](ICV-PIH.md), what has been proven against the authority in
[STATUS.md](STATUS.md), and the invoice and submission states in
[FILING-STATES.md](../../architecture/FILING-STATES.md).

Authority: ZATCA (Zakat, Tax and Customs Authority), https://fatoora.zatca.gov.sa.
Code: `app/Domains/Compliance/Countries/SaudiArabia/`.

## Scope

E-invoicing applies to taxable persons **resident in the Kingdom**, and to a
customer or third party issuing a tax invoice on their behalf; non-residents
are not required to issue electronic invoices (E-Invoicing Detailed
Guidelines, 3.1). The module issues only for an organization whose country is
`SA` (blank is read as Saudi); any other is refused before a counter is spent.
<!-- Issuing/IssuingStep.php prepare() -->

Documents are UBL 2.1 as profiled by the ZATCA XML Implementation Standard,
signed with XAdES (ECDSA, secp256k1, SHA-256), with a nine-tag TLV QR code.

## Routes

The buyer decides the route, not the amount:

| Document | Buyer | Route |
|---|---|---|
| Standard tax invoice (388, KSA-2 `01…`) | Business or government | **Clearance**: ZATCA validates and stamps it before the buyer receives it |
| Simplified tax invoice (388, KSA-2 `02…`) | Consumer | **Reporting**: given to the buyer at once, reported within 24 hours (Detailed Guidelines 4.2) |
| Credit note (381), debit note (383) | — | The route of the invoice it adjusts (Detailed Guidelines 4.3) |

A note is issued only against an original ZATCA has accepted
([RULES.md](RULES.md#credit-and-debit-notes)).

## Onboarding

Each device (EGS unit, a till) is onboarded on its own, in its organization's
ZATCA environment:

1. A CSR is generated with the environment's certificate template.
2. A compliance CSID is requested with an OTP from that environment's portal.
3. The six compliance samples (standard and simplified invoice, credit and
   debit note) are submitted signed with the compliance CSID, the simplified
   ones with their QR (BR-KSA-27 to 30, BR-KSA-60).
   <!-- Onboarding/SampleStamp.php, Onboarding/ComplianceSampleSet.php -->
4. A production CSID is requested; it signs every production document.

Real organizations onboard through the portal or the tenant API
(`/api/compliance/onboarding/{status,ccsid,compliance-check,pcsid}`, and
`/api/organizations/branches/{branch}/onboarding/*`). `fatoora:onboard` runs
the steps only for ZATCA's test taxpayer (`--target=sandbox|local`). The
sandbox accepts the fixed OTP `123345`; simulation and production need an OTP
the Fatoora portal issues only against TIN credentials.

## Environments

| Environment | Gateway (`fatoora.endpoints.*`) |
|---|---|
| `sandbox` | `https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal` |
| `simulation` | `https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation` |
| `production` | `https://gw-fatoora.zatca.gov.sa/e-invoicing/core` |

The environment is **per organization**; `ZATCA_ENVIRONMENT`
(`fatoora.environment`) is only the default. A platform admin sets it at
`/admin/organizations/{id}/zatca-environment` (permission
`platform.configure`). Each stored credential records the environment that
issued it and is never used in another. A switch is **refused** while
anything signed in the current environment is unfiled; one that goes through
archives (never deletes) the credentials, returns every till to pending, and
is audited (`zatca.environment.changed`, or `zatca.environment.refused`).
Only `production` is live (`Transmission::live()`), and a sandbox API key
never reaches a live organization (`SandboxGuard`). A log of
sandbox-versus-production differences is not built.
<!-- Rules/FatooraEnvironment.php, Onboarding/EnvironmentSwitch.php, Signing/CredentialStore.php -->

## Switching the country on and off

The admin console's **Countries** page (`/admin/countries`, permission
`platform.configure`) wins over `ZATCA_ENABLED` (`fatoora.enabled`, default
on), and each switch is audited. While Saudi Arabia is off, nothing new is
issued (the portal says so; `POST /api/compliance/sa/generate|validate|submit`
and the pipeline answer `422 JURISDICTION_UNAVAILABLE`), sign-up does not offer
it, and platform billing stops if the billing organization
(`BILLING_ORGANIZATION_ID`) is Saudi. What was already issued is still filed,
recovered and retried, and status reads stay open.

## Records

- **Retention:** at least six years from the end of the tax period, longer for
  capital assets (VAT Implementing Regulations, Article 66(1)); fifteen years
  for real estate (Guideline for Tax Invoicing and Records, 8.3.3). Issued
  documents are never deleted; `config/backup.php` keeps yearly backups for six
  years.
- **Location:** in the Kingdom; a resident taxable person's server must be
  there (Article 66(3); Guideline for Tax Invoicing and Records, 8.3.1). This is
  a deployment obligation — the database, credential disk and backups of a
  deployment serving Saudi taxpayers belong in the Kingdom.

## Commands

| Command | What it does |
|---|---|
| `fatoora:generate-csr` | CSR and private key |
| `fatoora:onboard --step=info\|ccsid\|compliance\|pcsid\|submit\|full` | Sandbox onboarding of the test taxpayer; `submit` clears and reports the six documents |
| `fatoora:sandbox-test`, `fatoora:sandbox-filing`, `platform:rehearse` | Sandbox walk-through, filing through the platform's path, full rehearsal |
| `platform:simulation-preflight {organization}` | Read-only readiness check for simulation |
| `fatoora:validate` | Generates a **sample** invoice XML (does not validate stored invoices) |
| `fatoora:check-certificate`, `fatoora:check-revocation` | Expiry; revocation lists |
| `fatoora:verify-hash-chain` | Verifies a device's chain |
| `fatoora:process-offline`, `fatoora:recover-submissions`, `fatoora:overdue-submissions` | Offline queue, in-flight recovery, reporting deadline |
| `fatoora:finish-retirements` | Retires tills with nothing left to file |
| `compliance:cleanup-offline-queue` | Prunes finished offline items |
| `platform:rotate-credential-key` | Re-encrypts stored credentials |

## Tenant API

| Method | Path | What it does |
|---|---|---|
| POST | `/api/compliance/sa/generate/{invoiceId}` | Hash and QR |
| POST | `/api/compliance/sa/validate/{invoiceId}` | Validate without submitting |
| POST | `/api/compliance/sa/submit/{invoiceId}` | Submit now through the submission tracker; a repeat after an answer replays it (`Idempotent-Replayed: true`) |
| GET | `/api/compliance/sa/status/{invoiceId}` | Status and ZATCA's response (the route names the parameter `submissionId`; it is read as an invoice id) |

The full API guide is the Saudi integration guide in the integration docs.
