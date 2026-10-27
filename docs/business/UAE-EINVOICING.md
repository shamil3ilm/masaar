# E-invoicing in the UAE, for business owners

Audience: owners and finance managers of businesses trading in the UAE, and the sales staff and partners who advise them.

This page is a plain-language summary, not tax advice. The official sources are named in brackets:

- **[GL]** Ministry of Finance and Federal Tax Authority, *UAE Electronic Invoicing Guidelines*, version 1.1
- **[MD 243]** Ministerial Decision No. 243 of 2025
- **[MD 244]** Ministerial Decision No. 244 of 2025

## Who is in scope

- **Business to business and business to government.** Every person doing business in the UAE is in scope for these transactions, whether or not they are registered for VAT, and whether or not they are established in the UAE [GL §6, §6.1].
- **Consumers are out of scope.** Sales to consumers need no e-invoice [GL §6.2].
- **Some activities are excluded,** for example certain sovereign activities, some airline supplies and some financial services [GL §7].

## The five-corner model

| Corner | Who | What they do |
|---|---|---|
| 1 | You, the supplier | Prepare the invoice and every value on it, and collect the buyer's Peppol identifier |
| 2 | Your accredited service provider (ASP) | Validates the invoice, sends it to the buyer's provider, and **reports the tax data to the FTA** |
| 3 | The buyer's ASP | Validates the invoice, confirms receipt, passes it to the buyer and reports it to the FTA |
| 4 | The buyer | Receives the invoice |
| 5 | The Federal Tax Authority (FTA) | Receives the tax data and confirms it was reported |

[GL §5.1, §5.2]

## Your ASP does more than store invoices

An ASP is the licensed gateway between your business and the network. The Ministry of Finance publishes the list of accredited providers [GL §13.2]. Your ASP:

- **validates** each invoice,
- **converts** it to the UAE's XML standard if it arrived in another format,
- **transmits** it, encrypted, to the buyer's ASP,
- **reports the tax data to the FTA**, and
- passes the confirmations back to you [GL §5.1, §5.2].

You appoint **one** ASP for both sending and receiving [GL §6.1]. You start that onboarding yourself, through **EmaraTax**, and the ASP issues your Peppol participant identifier [GL §9, §13.2]. Even with an ASP doing the work, **the legal obligation stays with you** [GL §5.2].

Invoices are XML in the Peppol PINT AE format. Unlike Saudi invoices, they have **no QR code** [GL §5.3].

## When you must start

The Ministry of Finance sets the dates by Ministerial Decision. MD 243 sets up the e-invoicing system, and MD 244 sets out the roll-out [GL §8]. MD 244 has since been amended, so the timetable can change.

- **Businesses are phased by annual revenue.** Larger businesses go first.
- **Government entities follow later.**
- **Each phase has two deadlines:** a last date to appoint an ASP, and a later date from which e-invoicing is mandatory [GL §8.3].
- **Anyone may start voluntarily before their phase.** E-invoicing penalties do not apply to invoices issued voluntarily before your mandatory date [GL §8.2, §11].

Find the current dates for your revenue band on the e-invoicing section of the Ministry of Finance's website.

## What to do before your date

1. **Choose and appoint your ASP** through EmaraTax, before the deadline for your revenue band.
2. **Collect your buyers' Peppol identifiers.** Collecting them is your job [GL §5.2].
3. **Test,** then go live [GL Appendix 1].

Until your start date, keep issuing **ordinary tax invoices**. On the platform, an invoice issued before the start date you record is a tax invoice that is kept and never transmitted. From that date, every invoice goes through your ASP. The platform will not issue an invoice if no ASP is recorded as appointed.

A buyer who is not yet on e-invoicing still needs a regular tax invoice, for example a PDF, alongside the e-invoice [GL §10.2.2]. The platform produces this copy from the same data as the e-invoice, so the figures on the two always match.

## Storage rules

- Keep e-invoice data for at least **5 years** after the tax period it relates to, and **7 years** for real-estate records. Add 4 years during a dispute or an audit [GL §5.4].
- Store invoices "within the State" [MD 243, Article 11]. The guidelines read this as being able to retrieve the records and provide them promptly when the FTA asks, wherever the servers are [GL §5.4].

## What the platform does

- Keeps your UAE details: TRN or TIN, Peppol identifier, emirate and legal registration.
- Drafts, numbers and issues tax, commercial and self-billed invoices and credit notes.
- Checks the Ministry's mandatory fields and the official PINT AE rules before anything is stored.
- Builds the XML and keeps it exactly as sent.
- Sends invoices through the provider, retries when no answer comes, and records each confirmation.
- Keeps invoices received from your suppliers.

**Current status.** The connection to an accredited service provider is built to a standard interface and tested end to end against a simulated provider that applies the official rules. Ask us about live provider availability for your start date.

Related: [Features](FEATURES.md) · [FAQ](FAQ.md) · [Glossary](GLOSSARY.md)
