# The life of an invoice

**Written for a tax auditor or compliance reviewer, and their technical
advisor.** It describes what this platform does to an invoice, what it keeps,
and where to look to confirm any of it. It is not an engineering guide; for
that, see `docs/architecture/` and `docs/sa/`.

A note on one word. `docs/audit/` in this repository is an *engineering* audit
of the source code, not records of invoicing activity. The records this
document describes are in the database tables and log channels named below.

---

## 1. What is kept, and where

Five places hold the evidence for any one invoice. Everything else is derived
from them.

| Where | What it holds |
|---|---|
| `invoices` | The invoice as issued: parties, dates, amounts, its ICV, its hash, the signed XML and - for a standard invoice - the authority's cleared copy |
| `invoice_lines` | The individual lines, with their own tax |
| `hash_chain_history` | One row per document: its ICV, its hash, and the hash of the document before it |
| `hash_chain_state` | The chain's current position for each taxpayer |
| `invoice_submissions` | Every attempt to send a document to ZATCA, and what came back |

Plus `audit_logs`, which records who did what — see `docs/AUDIT-SCHEMA.md`.

---

## 2. The sequence

### Step 1 — The invoice is drafted

A caller posts an invoice. It is validated against ZATCA's content rules before
anything is signed: a standard (B2B) invoice must carry the buyer's VAT number
and address, amounts must agree with their lines, and tax categories must be
ones the authority recognises. A document that fails is refused with the rule
it broke, and nothing is stored as issued.

`invoices.status` is `draft` at this point. A draft has no ICV, no hash and no
signature, and its amounts can still change.

### Step 2 — It takes its place in the chain

This is the step that matters most for an audit, because it is what makes the
sequence tamper-evident.

Each document is given:

- an **ICV** — a counter, allocated in sequence per taxpayer, shared across
  every document type: an invoice, a credit note and a debit note all draw
  from the one counter. Allocation holds a lock and a uniqueness constraint
  backs it, so **two documents can never carry the same number**. A number
  can, however, be consumed by a draft that is then abandoned, so the
  sequence may contain a gap — see section 3.
- a **PIH** — the hash of the document issued immediately before it. The first
  document in a chain carries the value the authority specifies for that
  position rather than a hash of its own.

Both are written to `hash_chain_history`, one row per document, with database
constraints that make a duplicate ICV and a second row for the same invoice
impossible rather than merely unlikely. Allocation holds a lock for the
duration, so two documents issued at the same instant cannot take the same
number.

**What this gives an auditor:** removing an issued document from the sequence,
or altering one after the fact, breaks the chain at that point and at every
point after it. The break is arithmetic, not a matter of trust — each row's PIH
must equal the previous row's hash.

### Step 3 — It is signed and given its QR code

The document is rendered as UBL 2.1 XML, hashed, and signed with the
taxpayer's certificate. A QR code is produced carrying the seller, the VAT
number, the timestamp, the totals, the hash, the signature and the public key.

The XML is kept in `invoices.signed_xml`. The hash is kept in `invoices.hash`
and is the value the next document's PIH will be.

Times are stated on the Kingdom's clock. The document's date, its time and the
timestamp inside its QR code are one statement, not three.

### Step 4 — It goes to the authority, by one of two routes

Which route depends on the document, not on a setting:

| Document | Route | When | What comes back |
|---|---|---|---|
| Standard (B2B) | **Clearance** | Before the invoice is given to the buyer | ZATCA's own stamped copy |
| Simplified (B2C) | **Reporting** | Within 24 hours of issue | An acknowledgement |

For a standard invoice, **the authority's cleared copy is the legal invoice**,
not the one submitted. It is stored in `invoices.cleared_xml`, and the platform
hands out that copy in preference to its own whenever anyone asks for the
document.

For a simplified invoice the customer already has it, which is why the QR code
carries the signature: the QR stands behind the document until the report is
accepted. The 24-hour deadline is tracked, and a document approaching it is
logged before it passes.

### Step 5 — The outcome is recorded, whatever it is

Every attempt is a row in `invoice_submissions`, which moves through:

```
draft → queued → pending_submission → submitted → cleared
                                                → reported
                                                → warning
                                                → rejected
                                      → failed
                                      → cancelled
```

The previous state and the moment it changed are kept alongside, so the history
of an attempt is readable and not only its conclusion. `cleared` and `reported`
are the accepted outcomes; `warning` means accepted with advisories;
`rejected` means the authority refused the document.

Attempts are idempotent: a resend carries the same key, and a duplicate reaches
the authority once. `submission_idempotency` holds the key, what was sent and
what came back.

### Step 6 — If the authority cannot be reached

A document is never silently dropped and never silently reissued. It goes to an
offline queue and is retried; the circuit breaker stops the platform hammering
an endpoint that is down; and the 24-hour reporting deadline continues to be
tracked while it waits. An operator can see the queue depth and the age of the
oldest item.

### Step 7 — Retention

Compliance tables are partitioned by period, and a monthly task creates
partitions ahead of need.

**Nothing is deleted or detached.** The maintenance command can archive
partitions past seven years, but the scheduled invocation does not ask it to —
it only creates. So records are retained indefinitely, which satisfies the
authority's retention period by never reaching the question, at the cost of
storage that only grows. An operator who later enables archiving must keep the
threshold at or beyond the retention period.

---

## 3. Questions an auditor is likely to ask

**"Show me this invoice as it was issued."**
`invoices.cleared_xml` if the authority cleared it, otherwise
`invoices.signed_xml`. The platform's own accessor prefers the cleared copy for
exactly this reason.

**"Prove this invoice has not been altered since."**
Recompute its hash from the stored XML and compare with `invoices.hash` and
with `hash_chain_history.invoice_hash`. Then check that the next document's
`previous_hash` equals it. An alteration fails both.

**"Prove no issued invoice is missing."**
Walk `hash_chain_history` for the taxpayer in ICV order: each row's
`previous_hash` must equal the preceding row's `invoice_hash`. That linkage is
what makes a removal detectable, and it is the check to rely on.

A uniqueness constraint guarantees no two documents share an ICV. It does
**not** guarantee the numbers are contiguous: a number is allocated when an
invoice is drafted, so a draft abandoned before issue consumes one and leaves a
gap. A gap is therefore something to ask about rather than evidence of a
missing document — the chain linkage either holds across it or it does not, and
that is the answer.

**"Who issued this, and when?"**
`audit_logs` — see `docs/AUDIT-SCHEMA.md`.

**"Was it accepted?"**
`invoice_submissions.state` and `clearance_status`, with the authority's full
response kept in `invoices.zatca_response`.

**"What if it was rejected?"**
The state is `rejected` and the authority's reason is in the response. A
rejected document is not treated as issued.

---

## 4. What this platform does not decide

It does not decide whether a transaction is taxable, what rate applies, or
whether a taxpayer is registered. It records and transmits what it is given,
applies the authority's content rules to it, and refuses what breaks them.
