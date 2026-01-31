# CompliPay Compliance Policies

This document defines the compliance policies for CompliPay. These are **decision boundaries**, not code - they define how the system handles edge cases that require human judgment.

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

## 7. What We Do NOT Handle

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

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-01-31 | CompliPay Team | Initial release |

**Last Updated**: January 31, 2026
