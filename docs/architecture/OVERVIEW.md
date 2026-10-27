# System overview

Audience: anyone new to the system — engineers, reviewers and auditors' technical
advisors. A one-page picture; each section links to the page with the detail.

## What it is

A compliance service. Callers — an ERP, a point of sale, the portal, a billing
system — send it invoices. It applies the tax authority's content rules,
renders the document the authority requires, signs or prepares it, files it by
the route that document needs, and keeps the result. It also keeps a tenant's
light books (receivables, purchases, a VAT return draft) and runs the
operator's own billing.

It does not decide what is taxable or at what rate: it applies the rules to
what it is given and refuses what breaks them.

One deployment serves many organizations. Every tenant row carries `org_id`
and the tenant scope applies it in the data layer
([TENANT-DATABASES.md](TENANT-DATABASES.md)).

## The parts

```
 Caller (API, pipeline, portal, import, edge till)
        │ authenticated; rate limited; feature access checked (Access)
        ▼
 Invoice domain ── draft → issue (IssuingLifecycle, one lock per chain)
        │                │
        │         ComplianceEngine → the organization's country module
        │                ├─ SaudiArabia: ICV + PIH chain, UBL XML, ECDSA
        │                │  signature, QR; clearance or reporting to ZATCA
        │                └─ UnitedArabEmirates: PINT AE document, checked,
        │                   sent through the Peppol network to the
        │                   organization's accredited service provider
        ▼
 Queued filing (never inside the request) — retries, idempotency, circuit
 breaker, offline queue, per-device pause, kill switch
        ▼
 Database (documents, chain, submissions, network documents, audit log)
```

| Layer | Where | Page |
|---|---|---|
| Domains and what each may depend on | `app/Domains/*` | [DOMAINS.md](DOMAINS.md) |
| Engine, country modules, network | `app/Domains/Compliance/{Engine,Countries,Networks}` | [JURISDICTION-ROUTING.md](JURISDICTION-ROUTING.md), [ADDING-A-JURISDICTION.md](ADDING-A-JURISDICTION.md) |
| Invoice, submission and network states | — | [FILING-STATES.md](FILING-STATES.md) |
| The life of one invoice, and how to verify it | — | [DATA-FLOW.md](DATA-FLOW.md) |
| Organizations, branches, tills, profiles | `app/Domains/Organization` | [ORGANIZATION-MODEL.md](ORGANIZATION-MODEL.md) |
| Feature access, approvals, customization | `app/Domains/Platform` | [ACCESS.md](ACCESS.md), [APPROVALS.md](APPROVALS.md), [CUSTOMIZATION.md](CUSTOMIZATION.md) |
| Platform finance and tenant Books | `app/Domains/{Billing,Books}` | [BOOKS-AND-FINANCE.md](BOOKS-AND-FINANCE.md) |
| Who did what | `app/Domains/Audit` | [AUDIT-SCHEMA.md](AUDIT-SCHEMA.md) |

What each country requires, and the rules as implemented, are in
[../compliance/README.md](../compliance/README.md).

## Signing keys and certificates (Saudi Arabia)

- Obtained by onboarding each device (EGS unit): a CSR, a compliance
  certificate, signed compliance samples checked by ZATCA, then a production
  certificate.
- Stored **encrypted** on a configured disk by `CredentialStore` — never in
  the source tree, never in the documents' tables, never logged. Each
  credential records the ZATCA environment that issued it, and one from
  another environment than the organization's is never used.
- One secret (`fatoora.signing.key`, falling back to the application key)
  covers every tenant; `platform:rotate-credential-key` re-encrypts them all.
  A per-tenant key held in a key service would narrow this and is not built.
- Expiry is checked on a schedule (`fatoora:check-certificate`); an expired
  certificate cannot stamp or file.

<!-- app/Domains/Compliance/Countries/SaudiArabia/Signing/CredentialStore.php -->

The UAE has no signing here: the accredited service provider handles
transport and reporting ([../compliance/ae/README.md](../compliance/ae/README.md)).

## What is stored

| Table | Holds |
|---|---|
| `invoices`, `invoice_lines` | Documents as issued: hash, signed XML, ZATCA's cleared copy |
| `hash_chain_history`, `hash_chain_heads` | The ICV and PIH chain, one per device |
| `invoice_submissions`, `submission_state_logs`, `submission_idempotency` | Every Saudi filing attempt, every state move, and the answer |
| `peppol_documents`, `peppol_document_events` | Every UAE network document, both status tracks, and every move on them |
| `audit_logs` | Who acted, how and from where |

Compliance records are never deleted (no `deleted_at`). Retention is in
[../compliance/POLICIES.md](../compliance/POLICIES.md).

## Operational posture

- Containers: web, dedicated queue workers, and a scheduler for the periodic
  commands (`routes/console.php`).
- The authority is assumed to fail: a circuit breaker, an offline queue
  (`fatoora:process-offline`), recovery sweeps (`fatoora:recover-submissions`,
  `peppol:recover-sending`), and the 24-hour reporting deadline tracked while
  a document waits.
- A kill switch halts submission, clearance, reporting or issuance, globally
  or per organization, without stopping the platform.
- Verification: the authority's SDK, the PINT AE schematron and a PDF/A
  validator run over generated documents in CI; see the developers' testing
  guide.
