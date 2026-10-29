# Frequently asked questions

Audience: prospective customers, partners and sales staff.

## Cost and contract

**How much does it cost?**
There is no fixed price list. We agree pricing with each customer, based on your countries, branches, tills and integrations. Write to shamil3ilm@gmail.com for a quote.

**Is there a free period?**
Yes. A new customer's first month is free, counted from the day your account is approved.

**How do we pay?**
The platform invoices you through itself, as a proper tax invoice, in Saudi riyals, or in UAE dirhams where enabled. Today you pay by bank transfer. Card payment is built behind a switch but is not yet connected to a live card gateway.

**Do you have a referral programme?**
Yes. Administrators get a referral link and code in the portal. Rewards are agreed in the programme's terms.

## Getting started

**How long does setup take?**
It depends on your data and on any systems you connect. Connecting to ZATCA takes minutes once you have the one-time code (OTP) from the Fatoora portal, and that code is valid for about an hour. Loading customers and products takes as long as preparing a CSV file. ERP or till integrations depend on your vendor. We agree a timetable with you at the start.

**Do you set it up for us?**
Yes. We set it up with you: company details, branches and tills, the authority connection, printing, and any integrations. After that, the support desk in the portal handles questions and customization requests.

**Can I bring my existing customers and products?**
Yes. Import them from CSV files. Each file is checked row by row before anything is saved. You can also import invoices; they arrive as drafts for you to review, and nothing is issued automatically.

## Day to day

**Are invoices in Arabic?**
Yes. Saudi invoices require Arabic seller and item names, as ZATCA requires, and the platform lists any that are missing. The portal itself works fully in Arabic or English.

**Which kinds of invoice can I issue?**
- **Saudi Arabia:** standard (business-to-business) and simplified (business-to-consumer) invoices, and credit and debit notes.
- **UAE:** tax, commercial and self-billed invoices, and credit notes. The UAE has no e-invoicing debit note.

**We deliver several times a month. Can we send one invoice?**
Yes. Record each delivery as a delivery note, then put a customer's calendar month on one summary tax invoice. Issue it by the 15th of the next month in Saudi Arabia, or within 14 days of the month's end in the UAE.

**We bill contracts in stages and hold back retention. Is that covered?**
Yes. Each stage is invoiced less the retention withheld, and releasing the retention makes its own invoice. The contract's statement shows what is still held.

**Can I print on my till printer?**
Yes. The platform prints on 80 mm and 58 mm rolls, as well as on A4, A5 and Letter. Each branch, and each person, can choose their own paper.

**Can I correct or cancel an issued invoice?**
Not directly. The law forbids changing or deleting an issued invoice. Instead, you issue a credit or debit note from the original, which the platform fills in for you. In Saudi Arabia, a note can be issued only once ZATCA has accepted the original.

**What about returns and exchanges?**
On an issued invoice, choose what happened: a full or partial return, damaged goods kept at a discount, an exchange, a replacement or a corrected buyer. The platform shows the notes and any new draft invoice it will make, then makes them when you confirm.

**What happens if ZATCA is down?**
Invoices are queued, retried automatically and recorded as evidence. See [What if ZATCA is down?](OBJECTIONS.md#what-if-zatca-is-down).

**Can our tills work without internet?**
Not yet. If the authority is unreachable, the platform queues your invoices. But the till itself must be able to reach the platform. An offline edge node for store counters is **planned**.

**Does it work on a phone?**
Yes. The portal adapts to phones and tablets and can be added to the home screen. There is no separate app to install.

## Integrations

**Can I keep my ERP or POS system?**
Yes. Your system sends invoices to the platform's API, one at a time or in batches. It receives back the result, and signed webhooks tell it when an invoice is cleared, reported or rejected. If your system sends an `Idempotency-Key` header, or an ERP reference on the pipeline route, a retry returns the first answer and never files the invoice twice. Without either, a retry counts as a new invoice, so ask your integrator to send one.

**Who builds the integration?**
Your ERP or POS vendor, your IT team, or us by agreement. The developer page offers a quick start and the full API reference.

## UAE

**What is an ASP, and do I need one?**
An ASP is an accredited service provider. If you are in scope, yes, you need one. Your ASP validates your invoices, sends them to the buyer's provider and reports the tax data to the FTA. You appoint one ASP through EmaraTax. See [UAE e-invoicing](UAE-EINVOICING.md).

**My UAE start date is next year. Can I use the platform now?**
Yes. Until your start date, the platform issues ordinary tax invoices that are kept and never transmitted. From the start date you record, invoices go through your ASP.

**Is the UAE provider connection live?**
The connection is built to a standard interface and tested end to end against a simulated provider. Ask us about live provider availability before your start date.

## Data and switching

**Who owns the data?**
Ownership and data-processing terms are set out in your customer agreement. In the platform itself, you can export your invoices and data as CSV and PDF at any time, and you can still read and export them even if your account is suspended.

**Where is our data kept?**
ZATCA requires a Saudi taxpayer's records to be kept on a server inside the Kingdom; UAE rules accept any location from which records can be handed to the FTA promptly. The hosting region is a deployment setting, so we confirm it per deployment and in your contract. See [Security and trust](SECURITY-AND-TRUST.md).

**Can we switch to the platform from another provider?**
Yes:
- **Customers and products:** import them from CSV.
- **Saudi Arabia:** each device connects to ZATCA with a new certificate. Your earlier invoices stay in your previous system's archive, which you must keep for the retention period.
- **UAE:** the platform sends through the ASP you appoint. If you change ASP, you do so through EmaraTax, then record the new one on your company page.

**Can we leave later?**
Yes. Export your records before you go. Issued invoices are kept, never deleted, for the legal retention period.

**Is it secure?**
Yes. Features include two-factor sign-in, encrypted signing keys and backups, roles and an audit log. See [Security and trust](SECURITY-AND-TRUST.md).

Still have a question? See [Objections](OBJECTIONS.md), or write to **shamil3ilm@gmail.com**.
