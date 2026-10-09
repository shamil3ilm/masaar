# System architecture

**Written for a tax auditor or compliance reviewer, and their technical
advisor.** A technical overview at the level an audit needs: what the parts
are, where the authority is contacted, where signing keys live, and what is
stored. Engineers wanting detail should read `docs/architecture/` for the
jurisdiction model and `docs/sa/` for the Saudi rules.

---

## 1. What this platform is

A compliance service. Other systems — an ERP, a point of sale, a billing
application — send it invoices. It renders each one as the authority's required
XML, signs it, places it in a tamper-evident sequence, transmits it to ZATCA by
the route that document type requires, and keeps the result.

It is not an accounting system. It does not decide what is taxable or at what
rate; it applies the authority's content rules to what it is given and refuses
what breaks them.

One deployment can serve more than one taxpayer. Every record carries the
taxpayer it belongs to, and that scoping is enforced in the data layer rather
than left to each query — a test suite sweeps for any route or query that could
return another taxpayer's rows.

---

## 2. The parts

```
    Caller (ERP, POS, billing)
        │  HTTPS, authenticated
        ▼
┌───────────────────────────────────────────────┐
│  API                                          │
│   · identifies the request and the taxpayer   │
│   · rate limits per taxpayer                  │
│   · validates against the authority's rules   │
└───────────────┬───────────────────────────────┘
                ▼
┌───────────────────────────────────────────────┐
│  Document pipeline                            │
│   · allocates ICV, takes the previous hash    │
│   · renders UBL 2.1 XML                       │
│   · signs; builds the QR code                 │
└───────────────┬───────────────────────────────┘
                ▼
┌───────────────────────────────────────────────┐
│  Submission (queued, never in the request)    │
│   · clearance for standard, reporting for     │
│     simplified                                │
│   · retries, idempotency, circuit breaker     │
│   · offline queue when ZATCA is unreachable   │
└───────┬───────────────────────────┬───────────┘
        ▼                           ▼
   ZATCA Fatoora API          Database + logs
```

Submission is queued rather than done inside the caller's request. The caller
gets its document and its QR code immediately; transmission happens behind it
and is retried. That is why a document can exist, correctly signed and
sequenced, while its submission is still in flight — and why
`invoice_submissions` records the state of the attempt separately from the
invoice.

---

## 3. Signing keys and certificates

The taxpayer's private key and certificate are what make a document legally
theirs, so where they live matters to an audit.

- Obtained from ZATCA by onboarding: a certificate request is generated, the
  authority issues a compliance certificate, six specimen documents are
  checked, and a production certificate is issued.
- Stored **encrypted**, on configured storage, never in the source tree and
  never in the database alongside the documents.
- Never written to a log, in any form, at any level.
- Expiry is monitored on a schedule, so a certificate lapsing is noticed before
  it stops documents being signed.

**A current limitation, stated plainly.** One encryption secret covers every
taxpayer in a deployment. For a deployment serving a single taxpayer this is
immaterial. Before a second taxpayer is onboarded it should become a key per
taxpayer held in a managed key service, and the credential store should be on
storage shared by all application instances rather than local to one. This is
recorded as an open item in `docs/PRODUCTION-READINESS.md` section 4.4.

---

## 4. How a document is made tamper-evident

Each document is given a sequence number (ICV) and the hash of the document
before it (PIH), both recorded in `hash_chain_history`, with database
constraints that make a duplicate number or a second row for one document
impossible. The constraints do not make the numbering contiguous — a number is
taken when an invoice is drafted, so an abandoned draft leaves a gap — and the
chain linkage rather than the numbering is what detects a removal.

The consequence: altering or removing an issued document breaks the chain at
that point and at every point after it, and the break is arithmetic rather than
a matter of trust. `docs/DATA-FLOW.md` section 3 gives the queries to verify it.

---

## 5. What is stored

| Table | Holds |
|---|---|
| `invoices`, `invoice_lines` | The documents as issued, their hash, their signed XML, and the authority's cleared copy where there is one |
| `hash_chain_history`, `hash_chain_state` | The sequence and the chain |
| `invoice_submissions`, `submission_idempotency` | Every transmission attempt and its outcome |
| `audit_logs` | Who acted — see `docs/AUDIT-SCHEMA.md` |
| `organizations`, `users` | Taxpayers and credentials |

Compliance tables are partitioned by period and a monthly task creates
partitions ahead of need. Nothing is detached: archiving past seven years is
available but not scheduled, so records are retained indefinitely and storage
grows.

---

## 6. Operational posture

- **Deployment**: containers, with a web tier, dedicated queue workers for
  submissions and for outbound notifications, and a scheduler for the periodic
  tasks (certificate expiry, offline queue drain, partition maintenance).
- **Availability of the authority is assumed to fail**: a circuit breaker stops
  the platform hammering an endpoint that is down, and an offline queue holds
  documents until it returns. The 24-hour reporting deadline for simplified
  invoices continues to be tracked while a document waits.
- **A stop control** exists to halt submissions deliberately without stopping
  the platform, for use if a defect is suspected.
- **Verification**: the authority's own validator — the SDK ZATCA publishes —
  is run over documents this platform generates, covering the XML schema, the
  European standard EN 16931, ZATCA's own rules, the certificate, the QR code
  and the hash chain. See the conformance section of the repository README for
  what it covers and the one check that is excluded, and why.

---

## 7. Reference documents

| Document | Audience | Subject |
|---|---|---|
| `docs/DATA-FLOW.md` | Auditor | What happens to an invoice, and how to verify it |
| `docs/AUDIT-SCHEMA.md` | Auditor | Reading the activity records |
| `docs/COMPLIANCE-POLICIES.md` | Auditor | Policy decisions taken |
| `SECURITY.md` | Auditor, engineer | Security controls |
| `docs/PRODUCTION-READINESS.md` | Operator | Go-live state and open items |
| `docs/sa/COMPLIANCE-RULES.md` | Engineer | The Saudi rules as implemented |
| `docs/sa/HASHING-AND-SIGNING.md` | Engineer | Every hash and encoding, and how each was established |
| `docs/architecture/` | Engineer | Jurisdiction model and routing |

`docs/audit/` is an engineering audit of the source code. It is not a record of
invoicing activity.
