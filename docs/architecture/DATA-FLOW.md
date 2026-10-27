# The life of an invoice

Audience: tax auditors, compliance reviewers and their technical advisors.
What the platform does to an invoice, what it keeps, and where to look to
confirm it. State tables are in [FILING-STATES.md](FILING-STATES.md); the
Saudi rules in [../compliance/sa/README.md](../compliance/sa/README.md), the
UAE's in [../compliance/ae/README.md](../compliance/ae/README.md).

## 1. Where the evidence is

| Where | What it holds |
|---|---|
| `invoices`, `invoice_lines` | The invoice as issued: parties, dates, amounts, ICV, hash, signed XML and, for a cleared invoice, ZATCA's stamped copy (`cleared_xml`) |
| `hash_chain_history` | One row per Saudi document: `icv`, `invoice_hash`, `previous_hash`, the certificate that signed it |
| `hash_chain_heads` | Each device's current position in its chain |
| `invoice_submissions`, `submission_state_logs` | Every attempt to file with ZATCA, every state move, and the answer |
| `peppol_documents`, `peppol_document_events` | Every UAE network document and every move on it |
| `audit_logs` | Who did what — [AUDIT-SCHEMA.md](AUDIT-SCHEMA.md) |

## 2. The sequence

1. **Drafted.** A caller posts an invoice (`InvoiceDrafter`). A draft has no
   ICV, hash or signature and can still change; one that is abandoned is
   deleted.
2. **Checked.** On issue the country's rules run before anything is signed
   (`IssuingStep`); a document that breaks one is refused with the rule id and
   nothing is stored as issued. A Saudi credit or debit note is refused until
   its original is accepted (VAT Implementing Regulations Art 54(4),
   BR-KSA-56).
3. **Placed in the chain (Saudi Arabia).** Under one lock per chain
   (`issuing_locks`) and in one transaction, the document takes the next
   **ICV** of its device (EGS unit) and the **PIH** — the hash of that device's
   previous document — and its row is written to `hash_chain_history`
   (`ChainRecorder`). Unique indexes make a second row for one invoice, or a
   duplicate ICV, impossible. ICV and PIH are per device, shared across
   invoices and notes ([../compliance/sa/ICV-PIH.md](../compliance/sa/ICV-PIH.md)).
4. **Signed.** Rendered as UBL 2.1, hashed, signed with the device's
   certificate, and given its QR code. `invoices.signed_xml` and
   `invoices.hash` are fixed from here: an issued invoice never changes, it is
   corrected by a note.
5. **Filed, outside the request.**

   | Document | Route | What comes back |
   |---|---|---|
   | Saudi standard (B2B) | Clearance, before it is given to the buyer | ZATCA's stamped copy — the legal invoice, kept in `cleared_xml` and served in preference |
   | Saudi simplified (B2C) | Reporting, within 24 hours | An acknowledgement |
   | UAE, from the e-invoicing start date | Sent to the accredited service provider, which delivers it and reports it | Delivery and reporting confirmations |
   | UAE, before the start date | Not sent (`not_transmitted`) | — it is an ordinary tax invoice, kept |

   The route follows the buyer, not a setting; a note follows its original.
6. **Recorded, whatever the answer.** Only an authority's verdict moves the
   invoice to `accepted` or `rejected`; an outage, a 401 or a timeout leaves it
   `issued`. Resends carry the same idempotency key
   (`submission_idempotency`), so a duplicate reaches the authority once.
7. **If the authority cannot be reached.** The document waits in the offline
   queue, the circuit breaker stops repeated calls, and the 24-hour reporting
   deadline keeps being tracked. Nothing is dropped and nothing is reissued.

## 3. Questions an auditor is likely to ask

**Show me this invoice as issued.** `invoices.cleared_xml` if ZATCA cleared
it, otherwise `invoices.signed_xml`.

**Prove it has not been altered.** Recompute its hash from the stored XML and
compare with `invoices.hash` and `hash_chain_history.invoice_hash`; then check
that the device's next document's `previous_hash` equals it.

**Prove no issued invoice is missing.** Walk `hash_chain_history` for the
device in ICV order: each `previous_hash` must equal the preceding row's
`invoice_hash`. `fatoora:verify-hash-chain` does this weekly and on demand,
comparing what each document carried with what the surviving rows would
derive. A rejected document keeps its place: ZATCA counts it in the chain.

**Who issued it, and when?** `audit_logs` (`invoice.issued`), and
`invoices.issued_by`.

**Was it accepted?** `invoices.status`, `invoice_submissions.state` and the
authority's full answer in `invoices.zatca_response`. For the UAE,
`peppol_documents.status` and `reporting_status`.

## 4. What the platform does not decide

Whether a transaction is taxable, what rate applies, or whether a taxpayer is
registered. It records and files what it is given, applies the authority's
content rules, and refuses what breaks them.
