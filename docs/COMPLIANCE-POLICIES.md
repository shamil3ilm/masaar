# Masaar Compliance Policies

This document defines the compliance policies for Masaar. These are **decision boundaries**, not code - they define how the system handles edge cases that require human judgment.

## 1. Retroactive Regulatory Changes

### Policy Statement

**An invoice is considered compliant based on the rules in effect at the time of issuance, not retroactively.**

### Rationale

ZATCA may issue clarifications or rule changes that affect interpretation of previous submissions. Without a clear policy, customers face legal uncertainty.

### Implementation

Each invoice stores:
- `rule_version`: ZATCA business rules version at issuance
- `schema_version`: UBL/ZATCA schema version at issuance
- `signature_algorithm`: Cryptographic algorithm used
- `hash_algorithm`: Hash algorithm used

### Decision Matrix

| Scenario | Action | Rationale |
|----------|--------|-----------|
| ZATCA clarifies a rule interpretation | No reprocessing | Compliant at time of issuance |
| ZATCA mandates new field for future invoices | Apply only to new invoices | Non-retroactive |
| ZATCA discovers security vulnerability | Mark affected invoices, do not resubmit | Audit trail preserved |
| Customer requests voluntary resubmission | Allowed with new ICV | Customer choice, tracked |

### Legal Statement (Generated per Invoice)

> This invoice (ID: {id}) was issued on {issue_date} and determined compliant under ZATCA rules version {rule_version} in effect at that time. Subsequent rule changes do not retroactively affect this determination.

---

## 2. Canonical Invoice Identity

### Policy Statement

**The canonical identity of an invoice is `(organization_id + issue_date + internal_uuid)`. External invoice numbers from ERPs are metadata, not identity.**

### Rationale

When organizations switch ERPs mid-year, invoice number collisions can occur (e.g., both ERPs generate `INV-1001`). Using internal UUIDs prevents disputes.

### Implementation

| Field | Purpose | Uniqueness |
|-------|---------|------------|
| `id` (UUID) | Canonical identity | Globally unique |
| `organization_id` | Tenant scope | Per-tenant |
| `invoice_number` | ERP reference | Metadata only |
| `icv` | ZATCA sequence | Unique per organization |
| `hash` | Content identity | Unique per content |

### Collision Handling

```
Scenario: ERP A issues INV-1001, ERP B (new system) issues INV-1001

Resolution:
- Internal IDs are different (UUID)
- ICVs are different (sequential)
- Hashes are different (content differs)
- Both are valid, distinct invoices
- ERP invoice_number treated as display label only
```

### Guidance for Integrators

1. Never assume `invoice_number` is unique across systems
2. Always use `id` (UUID) for references
3. Store ERP-specific IDs in your system, not ours
4. Use `icv` for ZATCA sequencing verification

---

## 3. Non-Compliant Export Policy

### Policy Statement

**Non-compliant invoices can be exported for audit purposes but MUST be clearly watermarked to prevent misuse.**

### Rationale

CFOs and auditors may need to review rejected invoices. Without proper watermarking, these could be misused for fraudulent tax deductions.

### Export Modes

| Mode | Watermark | Use Case |
|------|-----------|----------|
| `compliant` | None | Normal cleared/reported invoices |
| `draft` | `*** DRAFT - NOT SUBMITTED ***` | Pre-submission review |
| `audit` | `*** NON-COMPLIANT - NOT CLEARED BY ZATCA ***` | Audit of rejected invoices |

### Requirements for Non-Compliant Export

1. **Reason required**: Must provide justification
2. **Audit logged**: Who, when, why
3. **Watermark prominent**: Header and footer
4. **Disclaimer included**: Legal warning text

### Disclaimer Text (Audit Mode)

> This invoice export is for AUDIT PURPOSES ONLY. It has NOT been cleared by ZATCA and MUST NOT be used for tax deduction, reimbursement, or any official purpose. Using this document for tax purposes may constitute fraud.

---

## 4. Organization Lifecycle

### Policy Statement

**Organizations have defined lifecycle states that control what operations are permitted.**

### States

| State | Can Issue? | Can Submit? | Hash Chain | Certificates |
|-------|------------|-------------|------------|--------------|
| `active` | Yes | Yes | Active | Valid |
| `suspended` | No | No | Frozen | Valid but unused |
| `legally_replaced` | No | No | Closed | Revoked |
| `archived` | No | No | Read-only | Expired |
| `legal_hold` | No | No | Preserved | Preserved |

### Transitions

```
active → suspended (temporary halt)
suspended → active (resume operations)
active → legally_replaced (merger, VAT change)
legally_replaced → archived (after transition period)
any → legal_hold (government request)
```

### Legal Entity Change Handling

When a company merges or changes VAT number:

1. Mark old organization as `legally_replaced`
2. Create new organization with new VAT
3. Transfer users (optional)
4. Old invoices remain under old organization
5. New invoices under new organization
6. Hash chain is NOT continued (new entity = new chain)

---

## 5. Cryptographic Obsolescence

### Policy Statement

**All cryptographic parameters are versioned and stored with the invoice for future audit verification.**

### Stored Parameters

| Parameter | Current Value | Stored With Invoice |
|-----------|---------------|---------------------|
| Signature Algorithm | ECDSA-secp256k1 | Yes |
| Hash Algorithm | SHA256 | Yes |
| Canonicalization | C14N | Yes |
| Key Size | 256-bit | Yes (via certificate) |

### Migration Path

When ZATCA deprecates an algorithm:

1. New invoices use new algorithm
2. Old invoices retain old algorithm metadata
3. Verification uses stored algorithm, not current
4. Audit reports show algorithm distribution

### Future-Proofing Checklist

- [ ] Monitor ZATCA announcements for algorithm changes
- [ ] Maintain algorithm version registry
- [ ] Test verification with historical algorithms
- [ ] Plan 6-month migration windows

---

## 6. Legal Hold

### Policy Statement

**When under legal hold, all data for the specified scope is preserved indefinitely with no deletions or modifications.**

### Triggers

- Government investigation request
- Court order
- Internal legal review
- Regulatory audit notice

### Effects

| Normal Operation | Under Legal Hold |
|------------------|------------------|
| Soft delete allowed | No deletions |
| Certificate rotation | Certificates preserved |
| Retention policy applies | Indefinite retention |
| Data can be modified | Read-only (audit additions only) |

### Implementation

```
legal_hold_scope:
  - organization_id: specific tenant
  - date_range: optional time bounds
  - invoice_ids: optional specific invoices

legal_hold_metadata:
  - hold_id: unique identifier
  - requested_by: legal/authority name
  - requested_at: timestamp
  - reason: documented reason
  - expires_at: null (indefinite) or date
```

### Release Process

1. Written authorization required
2. Legal review of release request
3. Audit log of release
4. Normal retention resumes

---

## 7. Timestamp Authority

### Policy Statement

**An invoice is dated when the platform generates the final invoice: its issue date, issue time and QR timestamp are the system clock at issuance, on the Kingdom's clock. A till's own sale time (`sold_at`) is the supply, never the issue time, and a sale is never refused for being late. No timestamp authority is used: ZATCA's security features standard requires XAdES baseline level B-B, which carries no signature timestamp, so signatures are never upgraded to XAdES-T.**

### Sources

ZATCA's *Detailed Guidelines for E-Invoicing* (version 2, May 2023). Page
numbers are the printed ones.

| Rule | Where | What it says |
|---|---|---|
| Issue date | p60 | "Invoice Issue Date must be the date on which final Tax Invoice is generated." |
| Reporting deadline | p12, p18, p32 | Simplified invoices are reported "within (24) hours from its issuance". |
| Issue date not in the future | p55 | "The document issue date (BT-2) must be less or equal to the current date." The SDK checks it as BR-KSA-04. |
| Keep trying, keep evidence | p46, p47, p49 | Continue attempting "in regular intervals until successful" (~every 15 minutes, p47); "TP should keep evidence of trying to clear the invoice to ZATCA (e.g. API log)". |
| Past 24 hours | p48 | "In case the seller does not manage to share the invoice within 24 hours, he should notify ZATCA via a dedicated form on ZATCA's website"; "Once the connection is restored, merchant should notify ZATCA again" and "Seller should immediately report the transaction to ZATCA". |
| No cancellation | p63 | "Invoices cannot be cancelled." A late invoice is reported, never withdrawn. |

The supply date is KSA-5, `cac:Delivery/cbc:ActualDeliveryDate`. ZATCA's data
dictionary marks it conditional for every document type, simplified invoices
included, and no BR-KSA rule compares it with the issue date. BR-KSA-15 makes
it required on a standard tax invoice; BR-KSA-35 and BR-KSA-36 tie a supply
end date to it.

### Timestamp Hierarchy

| Source | Priority | Use |
|--------|----------|-----|
| System clock at issuance | 1 (highest) | IssueDate, IssueTime, the QR's timestamp (tag 3), the start of the 24-hour reporting clock, and the XAdES signing time |
| Till sale time (`sold_at`; `issued_at` is still accepted as its old name) | 2 | The supply date (KSA-5) when the invoice gives none; the daily close (Z-report) and the VAT summary count the sale on it |
| ERP timestamp | 3 (lowest) | Informational only |

A sale time further ahead of the system clock than
`ZATCA_SALE_TIME_MAX_AHEAD_SECONDS` (default 30) is refused before the invoice
takes a counter, with the reason: a supply cannot be in the future, and
neither can an issue date (p55). Any sale in the past is issued, however late.

### When a simplified invoice cannot be reported in time

1. The platform keeps trying: the offline queue retries a document ZATCA
   could not be reached for with a growing interval that stops at
   `ZATCA_OFFLINE_RETRY_INTERVAL` (default 900 seconds, ZATCA's "~every 15
   minutes"), and never gives up on a retryable failure. Only an answer that
   retrying cannot change ends it.
2. Each attempt that got no answer, and the one that filed it, is a row of
   `reporting_attempts`: the evidence p46 and p49 ask for. The portal's Late
   reporting page downloads it as CSV.
3. Past 24 hours from issuance, the hourly `fatoora:overdue-submissions --alert`
   emails the organization's administrators, in Arabic and English, which
   invoices are late and that they must notify ZATCA through the form on its
   website (p48). Once those invoices are reported, it emails them again to
   notify ZATCA again.
4. On the Late reporting page an administrator records that ZATCA was
   notified, of the delay and again once reported. Each record is in the audit
   log with who did it.

### Dispute Resolution

```
Scenario: ZATCA questions invoice timestamp

Resolution Path:
1. The system clock at issuance is authoritative
2. Compare against ZATCA's received timestamp (clearance response)
3. Clock drift tolerance: ±30 seconds
```

### Configuration

`ZATCA_SALE_TIME_MAX_AHEAD_SECONDS` for the till's sale time,
`ZATCA_REPORTING_DEADLINE_HOURS` (24) and `ZATCA_OFFLINE_RETRY_INTERVAL`
(900). Keep the host clock synchronised (see the deployment guide's time
synchronisation section).

---

## 8. Certificate Versions and Renewal

### Policy Statement

**A device signs new documents with its active production certificate only, and files every document with the certificate that signed it.**

### Rationale

ZATCA authenticates every clearance and reporting call with the device's CSID and its secret, and each document carries the certificate it was stamped with. A renewal gives the device a new certificate while documents signed with the old one may still be queued, retried, or reported late. Filing those with the new CSID pairs a document with a certificate that did not sign it, and replacing the stored certificate on renewal lost the old secret entirely.

### How It Works

Every production certificate a device has held is a version, kept per device (head office, or a branch with its own certificate) in `device_certificates`:

| Field | Meaning |
|-------|---------|
| `version` | 1 for the first certificate, incremented by each renewal |
| `certificate_id` | SHA-256 of the certificate - the same digest every chain entry records |
| `serial_number`, `issuer` | read from the certificate |
| `valid_from`, `valid_to` | the certificate's validity window |
| `status` | `active`, `superseded` or `revoked` |

The certificate, its secret and its private key stay in the encrypted credential store, under the same key as before; the table holds nothing secret. Versions are never deleted.

| Event | Effect |
|-------|--------|
| Renewal (a new production certificate stored) | The new one becomes `active`; the old one becomes `superseded` and is kept, with its secret |
| Branch reset for re-onboarding | The held certificate becomes `superseded` and is kept |
| Revocation recorded | `revoked`; if it was the active one, the device stops signing until it is onboarded again |
| Credential stored before versions were kept | Becomes version 1, unchanged, the first time it is read |

An organization renews by onboarding again: a new OTP and compliance CSID on a new key, then the production step, which requests a new certificate because the held one was issued on another key.

### Filing

Every filing path - the submission tracker, the queued job and its recovery, the offline queue, and a direct submit, for clearance and for reporting - files with `CredentialStore::forInvoice()`, which returns the version named by the document's chain entry. A document with no chain entry is matched on the certificate it carries.

| Signing version | Filed? |
|-----------------|--------|
| `active` | Yes |
| `superseded` | Yes, with its own CSID and secret |
| expired | Yes - the platform does not block it; ZATCA's response decides |
| `revoked` | No - refused with `CERT_REVOKED` before anything is sent |

### Revocation Check Before Stamping

Security Features Implementation Standard v1.2, section 2.2.1, requirement 14: "Before using the certificate for stamping, EGS/ZATCA e-invoicing platform shall check certificate's validity against any of the above mentioned sources" (the CA's CRLs or OCSP responders), and "CRLs shall be valid for seven (7) days which would allow EGSs to work fully offline for seven (7) days".

| Question | Answer |
|----------|--------|
| What is checked | The device certificate that stamps - the XML signature at issue, the offline path's signature, and the PAdES stamp on a PDF. Path validation up the chain is the verifier's, as of the signing time (requirement 17). |
| Against what | The CRL distribution points the certificate names (the profile in section 2.2.2 lists them as HTTP URLs); `ZATCA_CRL_URLS` when it names none. OCSP is not used: the profile marks it optional, and requirement 14 is met by either source. |
| Which lists count | One issued by the certificate's issuer whose signature verifies against the issuing CA from `ZATCA_CA_CHAIN_PATH`. Without the CA certificate, only a list fetched over https counts. |
| Caching | A list is refreshed hourly while the CA is reachable and relied on up to its next update, never more than seven days after it was issued. |
| CA unreachable | Stamping continues on the held list for up to seven days, then stops (`CERT_REVOCATION_UNVERIFIED`, retryable) until a current list is obtained. |
| No list named or configured | Stamping continues and the certificate is reported as unverified, in the readiness report and on the certificates page. |
| Revoked | Stamping is refused with `CERT_REVOKED` before a counter is spent. |

### Detecting Revocation

The CA publishes a revocation in its CRL, and the EGS "reads periodically from CRL to verify which Identifiers are still active" (Security Features Implementation Standard v1.2, section 2.1.3). `fatoora:check-revocation` runs daily and checks every `active` and `superseded` version with the same check as above. A version found revoked is recorded as `revoked`: nothing more is filed with it, and if the device signs with it, the device stops signing. Its organization's administrators are mailed, each in their own language, and the platform's operators are alerted through the alerts channel.

A device whose certificate was revoked refuses to sign with `CERT_REVOKED` and says why. A branch in that state does not fall back to head office's certificate, which would sign as a different device.

A platform admin can mark a version revoked from the console's Certificates page, with a reason, before the CA's list says so. The action is audited and passes through the `RevocationApprovals` hook, which approves at once until an approval policy is bound to it.

### What ZATCA's Documents Say

- The Security Features Implementation Standard (section 2.1.2) describes renewal through the taxpayer portal: the CA "validates that the existing certificate is not revoked or renewed before then revoke existing certificate", and issues a new one. Section 2.1.3 describes revocation and says the revocation status is published as CRLs and through OCSP. Section 2.2.1, requirement 14, requires the certificate's validity to be checked against those sources before it is used for stamping, and lets a CRL stand for seven days.
- The E-Invoicing Detailed Guidelines answer HTTP 401 with "Check authentication certificate and secret and resubmit".
- Neither the Detailed Guidelines nor the SDK documentation says how a document stamped with a since-renewed, revoked or expired certificate is to be reported late. The platform therefore files it with the certificate that signed it and records ZATCA's answer, rather than re-signing it (which would change its hash and break the chain) or filing it under a certificate that did not sign it.

---

## 9. Sandbox vs Production Variance Tracking

### Policy Statement

**All behavioral differences between ZATCA sandbox and production environments must be tracked and documented to support customer disputes and debugging.**

### Rationale

Invoices frequently pass sandbox validation but fail in production due to undocumented enforcement differences. Customers say "But it worked in sandbox." We need evidence.

### Tracked Variances

| Variance Type | Example |
|---------------|---------|
| `sandbox_only_pass` | Invoice accepted in sandbox, rejected in production |
| `production_only_fail` | Same payload, different error in production |
| `validation_difference` | Different error codes for same issue |
| `timing_difference` | Timeouts differ between environments |

### Implementation

Database table: `environment_variance_log`

```sql
CREATE TABLE environment_variance_log (
    id UUID PRIMARY KEY,
    organization_id UUID NOT NULL,
    invoice_id UUID,
    variance_type VARCHAR(50) NOT NULL,
    sandbox_result JSONB,
    production_result JSONB,
    payload_hash VARCHAR(64),
    rule_code VARCHAR(50),
    notes TEXT,
    reported_to_zatca BOOLEAN DEFAULT FALSE,
    zatca_ticket_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT NOW()
);
```

### Workflow

```
1. Invoice fails in production
2. Check if identical payload passed sandbox
3. If yes → Log as variance
4. Generate variance report for customer
5. Optionally report to ZATCA
```

### Customer Communication Template

```
Your invoice {id} was rejected in production with error {code}.
This same payload was accepted in sandbox on {date}.

Variance ID: {variance_id}
This has been logged for ZATCA review.

Please contact support with this variance ID for further assistance.
```

---

## 10. Webhook Replay Protection

### Policy Statement

**Webhook payloads include replay protection fields. Consumers MUST implement duplicate detection. Masaar does NOT enforce this server-side but provides the tools.**

### Rationale

If a customer's webhook endpoint is compromised, attackers could replay old webhook payloads internally. We provide protection mechanisms; consumers must use them.

### Payload Security Fields

Every webhook body is this envelope, signed as a whole:

```json
{
  "id": "delivery uuid",
  "event": "invoice.cleared",
  "timestamp": "YYYY-MM-DDTHH:MM:SS.sssZ",
  "data": { }
}
```

The same values arrive as headers: `X-Webhook-ID`, `X-Webhook-Event`, `X-Webhook-Timestamp`, and `X-Webhook-Signature` (`sha256=` followed by the HMAC-SHA256 of the raw body, keyed with the webhook secret). Because `id` and `timestamp` are inside the signed body, neither can be altered without breaking the signature.

### Retries

A delivery the receiver does not answer (a connection failure, a timeout, 408, 429 or 5xx) is retried up to four more times over about forty minutes. **Every retry carries the same `id`**, with a fresh `timestamp`. Any other non-2xx status is treated as the receiver's answer and is not retried.

An endpoint is disabled after ten failed deliveries in a row with nothing delivered for 24 hours. Re-enabling it clears the count.

### Consumer Requirements

| Requirement | Implementation |
|-------------|----------------|
| Verify signature | HMAC-SHA256 of the raw body with the webhook secret |
| Discard repeats | Store processed `id`s; a retry repeats the `id` |
| Validate timestamp freshness | Reject if `timestamp` is more than 5 minutes old |
| Answer quickly | Return 2xx once the event is stored, and process it afterwards |

Verification in PHP, Node.js, Python, Java, C# and Go, with the receiving
rules above, is in [WEBHOOKS.md](WEBHOOKS.md).

---

## 11. What We Do NOT Handle

### Explicitly Out of Scope

| Topic | Reason |
|-------|--------|
| Tax interpretation correctness | Customer/accountant responsibility |
| ERP data field correctness | Source system responsibility |
| Legal advice | Requires licensed professionals |
| Government policy prediction | Impossible to predict |
| Business accounting decisions | Customer domain |

### Customer Responsibilities

1. Ensure invoice data accuracy before submission
2. Understand applicable tax categories
3. Maintain proper exemption documentation
4. Comply with retention requirements
5. Respond to ZATCA inquiries
