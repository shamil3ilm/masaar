# Demo script: 20 minutes

Audience: sales staff and partners giving a live demo to a business owner or finance manager.

The aim is one honest story: *you issue an invoice the way you do today, and the platform makes it legal, files it and keeps it*. Show, don't list. Everything here works today in ZATCA's developer sandbox.

## Before the demo

- **Use the staging server, never production.** Its banner says the sandbox is not a legal filing; say so before they ask.
- **Prove the engine first.** The day before, the operator runs the rehearsal in [the pilot rehearsal runbook](../operations/runbooks/PILOT-REHEARSAL.md) with `--keep`. It onboards the dedicated rehearsal organization, files every document type and checks the QR and hash chain. Bring its PASS table: it is your evidence if asked "does it really work with ZATCA?".
- **Prepare a demo company** on the same server, following that runbook's browser checklist: a fictitious name with an Arabic legal name, the sandbox test VAT number (shared by design, so the rehearsal organization holding it does not block sign-up), connected with the sandbox OTP, a business customer with full address, two products (15% and zero-rated) with Arabic names, and a few invoices already issued so the dashboard is not empty.
- **Have ready:** a second browser tab signed in as an Accountant, a phone on the same Wi-Fi, and the developer page open.
- **Ask in advance:** their country, business type, number of branches and tills, and which ERP or till system they use. Tailor steps 3 and 5.

## The flow

| Min | Show | Say |
|---|---|---|
| 0-2 | **Home page**, switch to Arabic and back | "Hosted, bilingual, Saudi Arabia and the UAE. We set it up with you." |
| 2-4 | **Dashboard**: today, this month, cleared, rejected, latest submissions | "This is what the owner sees each morning: what was filed and what needs attention." |
| 4-9 | **The ZATCA moment.** New invoice → simplified, add a product (Arabic fills in) → save draft → Issue. Then open it: *Reported*, the counter, the hash, the QR. Print on 80 mm, then A4. Scan the QR with the phone. | "A draft has no number. When you issue, the rules are checked first, so a mistake never leaves a gap. Then it is signed, numbered, chained to the last one and reported to ZATCA in the background, well inside the 24 hours. A standard invoice is cleared before the buyer gets it." |
| 9-11 | **A refused invoice.** Standard invoice without the buyer's address → Issue | "It names the field and keeps what you typed. ZATCA never sees a bad invoice from you." |
| 11-12 | **Correct it.** Credit note from the issued invoice | "You cannot edit an issued invoice; the law forbids it. The note fills itself in from the original." |
| 12-15 | **Books** as the Accountant: purchases, receivables, VAT return box by box with the documents behind each figure, export | "Your accountant works in the same place and cannot issue or change invoices." |
| 15-18 | **API**: the developer page, an API key and webhook on Integrations | "Your ERP or till sends the invoice; we do the compliance and call you back. Send an idempotency key, and a retry never files twice." |
| 18-20 | **Next step**: the [pilot proposal](PILOT-PROPOSAL.md) | "We propose a pilot in ZATCA's simulation environment with your own VAT number, before anything is live." |

For a UAE prospect, replace minutes 4-11 with a tax invoice that passes the PINT AE rules, and say plainly that the provider connection is tested against a simulated provider (see [UAE e-invoicing](UAE-EINVOICING.md)).

## What not to say

- Not "we are certified" or "approved by ZATCA". Say: our documents pass ZATCA's official validator, and the full round trip is accepted in ZATCA's developer sandbox.
- Not "works offline". The offline edge node for counters is **planned**.
- Not "card payments accepted". Today the platform's own invoices are paid by bank transfer.
- No prices on the call. Pricing is agreed per customer, and the first month is free.
- Not "your data is in Saudi Arabia" as a flat promise. The hosting region is confirmed per deployment and in the contract.

## If something fails live

Stay calm and show it: the sandbox can be slow or down. Open the Submissions or Late reporting page and say "this is exactly what happens when ZATCA is unreachable: it is queued, retried and recorded". Then switch to the rehearsal's PASS table.

Related: [Objections](OBJECTIONS.md) · [Features](FEATURES.md) · [Overview](OVERVIEW.md)
