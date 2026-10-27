# Objections and honest answers

Audience: sales staff and partners answering a prospect's concerns; prospects who want straight answers.

Answer the question asked, say what is not built yet, and offer to show it. Never promise beyond this page; if unsure, say "I will confirm in writing" and write to **shamil3ilm@gmail.com**.

## "We already have an ERP (or a till system)."

Good: keep it. The platform does not replace it. Your system sends each invoice to the platform's API; the platform checks it against the authority's rules, signs it, files it, and tells your system the result through a signed webhook. Your ERP stays the place where you sell; the platform is where invoices become compliant and are kept.

- If your system sends an idempotency key or its own reference, a retry after a network error never files an invoice twice.
- Who builds the link: your vendor, your IT team, or us by agreement. The [developer page and API reference](../integration/API.md) are public.
- If you have no ERP, your team issues invoices in the portal or imports them from CSV.

## "Is our data safe?"

What is in place today (details in [Security and trust](SECURITY-AND-TRUST.md)):

- Two-factor sign-in, which an organization can make mandatory; breached passwords refused.
- Signing keys encrypted with a separate key; API keys stored only as hashes; daily encrypted backups, checked after each run.
- Every query scoped to your organization, with automated tests that one organization cannot read another's records.
- An audit log of who issued, corrected and changed what, which you can export.
- Issued invoices are never deleted, and you can export your data even if your account is suspended.

What we do not claim: a security certification or an uptime guarantee. Service levels and the **hosting region are confirmed per deployment and in your contract**. A separate encryption key per organization is **planned**.

## "What if ZATCA is down?"

It happens, and the rules allow for it. Invoices are kept in a queue and retried automatically; every attempt is recorded as evidence. A standard (B2B) invoice is given to the buyer only once it is cleared, so a long outage delays those. Simplified (B2C) invoices print at once and are reported when ZATCA answers; anything near the 24-hour limit is flagged, administrators are emailed, and the Late reporting page gives you the evidence file and a place to record that you notified ZATCA.

## "We are in the UAE."

The platform supports UAE e-invoicing to the Peppol PINT AE standard: tax, commercial and self-billed invoices and credit notes, checked against the Ministry's mandatory fields and the official PINT AE rules before anything is stored. Before your start date it issues ordinary tax invoices.

Be clear about one thing: under the UAE model, invoices go through an accredited service provider (ASP) that **you** appoint through EmaraTax. The platform's connection to a provider is built to a standard interface and tested end to end against a simulated provider; a **live provider connection is not yet in service**. Ask us about live availability for your start date. See [UAE e-invoicing](UAE-EINVOICING.md).

## "We need our counters to work offline."

Honest answer: **not yet**. Today a till needs a connection to the platform. If the *authority* is unreachable, invoices queue on the platform and nothing is lost, but if the *store's internet* is down, the till cannot issue through the platform. An offline edge node that keeps store counters issuing during an outage is **planned** and designed; it is not available today. If offline counters are essential from day one, say so, and we will tell you plainly whether the timing fits.

## "Why not build it ourselves, or use a cheaper tool?"

You can. Building means digital signatures, device certificates, QR codes, invoice counters and hash chains, ZATCA's onboarding, and keeping up with rule changes. The platform's documents pass ZATCA's official validator, and the full onboarding and filing round trip is accepted in ZATCA's developer sandbox. Compare any tool on the same points: are rules checked *before* numbering, and what happens during an outage?

## "Are you approved by ZATCA?"

We do not claim a ZATCA certification. In ZATCA's model each taxpayer's solution must comply, and each device is onboarded with its own certificate. What we can show: our documents pass ZATCA's validator, and the sandbox round trip is accepted. Simulation and production are completed with each customer, because ZATCA issues those codes only to a registered taxpayer. That is what the [pilot](PILOT-PROPOSAL.md) is for.

## "How much does it cost?"

There is no fixed price list; pricing is agreed per customer, based on countries, branches, tills and integrations. The first month is free.

Related: [FAQ](FAQ.md) · [Demo script](DEMO-SCRIPT.md)
