# ZATCA ICV and PIH Sequence Rules

## Overview

This document clarifies the critical rules for **Invoice Counter Value (ICV)** and **Previous Invoice Hash (PIH)** sequences in ZATCA Phase 2 e-invoicing.

## Key Rules (Verified from ZATCA)

### 1. ICV is Sequential Per CSID (Not Per Document Type)

**IMPORTANT**: ICV counter is shared across ALL invoice types for a single CSID:
- Standard Invoice
- Standard Credit Note
- Standard Debit Note
- Simplified Invoice
- Simplified Credit Note
- Simplified Debit Note

**Correct Example:**
```
ICV 1: Standard Invoice
ICV 2: Simplified Invoice
ICV 3: Standard Credit Note
ICV 4: Simplified Debit Note
ICV 5: Standard Invoice
```

**WRONG Example (separate counters):**
```
Standard Invoice: ICV 1, 2, 3...
Simplified Invoice: ICV 1, 2, 3...  ← WRONG!
```

### 2. PIH Chain is Also Per CSID

The PIH (Previous Invoice Hash) forms a continuous chain across ALL documents:

```
Invoice 1 (ICV=1): PIH = default hash
Invoice 2 (ICV=2): PIH = hash of Invoice 1
Invoice 3 (ICV=3): PIH = hash of Invoice 2
```

The chain must be unbroken regardless of:
- Invoice type (B2B, B2C)
- Document type (Invoice, Credit Note, Debit Note)
- Success/failure status (rejected docs still count!)

### 3. One Certificate Per Company is Sufficient

- You do NOT need individual certificates for each user
- One CSID per company/server is typically enough
- Multiple EGS devices = multiple CSIDs = multiple sequences

### 4. Branch Architecture

| Scenario | Certificate Approach |
|----------|---------------------|
| Single location | One CSID, one ICV sequence |
| Multiple branches, central server | One CSID, one ICV sequence, different "Other Seller ID" per branch |
| Multiple branches, independent | Separate CSID per branch, each with own ICV sequence |

**For central server with branches:**
- Use the same CSID
- Populate `cac:PartyIdentification` with branch-specific CR/MOMRAH license
- Populate `cac:PostalAddress` with branch address
- VAT number remains the same (company-wide)

### 5. Rejected Documents Still Count

**CRITICAL**: Even if ZATCA rejects an invoice:
- The ICV is consumed (don't reuse it)
- The document hash enters the PIH chain
- Next document must reference the rejected document's hash

```
Invoice 1 (ICV=1): ACCEPTED → hash = ABC123
Invoice 2 (ICV=2): REJECTED → hash = DEF456
Invoice 3 (ICV=3): PIH = DEF456 (hash of rejected Invoice 2)
```

## Implementation in This Codebase

### AtomicIcvManager (`app/Domains/Compliance/Fatoora/Services/AtomicIcvManager.php`)

- Manages ICV per organization (maps to CSID)
- Uses Redis for atomic increments (falls back to DB)
- Ensures strict monotonicity even at millisecond precision

### HashChainManager (`app/Domains/Compliance/Fatoora/Services/HashChainManager.php`)

- Manages PIH chain per organization
- Provides exclusive locking to prevent race conditions
- Tracks certificate transitions for audit

### Default PIH

For the first invoice in a chain, use the ZATCA SDK default PIH:
```
NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==
```

This is a base64-encoded SHA-256 hash of a zero value.

## Compliance Checklist

- [ ] ICV increments by 1 for every document (regardless of type)
- [ ] ICV never reused (even for rejected/void documents)
- [ ] PIH references the immediately previous document's hash
- [ ] PIH chain includes rejected documents
- [ ] One ICV sequence per CSID
- [ ] Branch invoices have correct "Other Seller ID" and address

## Sources

- [ZATCA E-invoicing Detailed Technical Guidelines](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing-Detailed-Technical-Guideline.pdf)
- [ICV & PIH for Invoice/Credit/Debit - Fatoora Community](https://zatca1.discourse.group/t/icv-pih-for-invoice-credit-debit/1460)
- [Clarification on ICV and Invoice Number - Fatoora Community](https://zatca1.discourse.group/t/clarification-on-icv-and-invoice-number/2877)
