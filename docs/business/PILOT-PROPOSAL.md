# Pilot proposal (template)

Audience: sales staff and partners proposing a pilot to a business; the business's owner and finance manager who will read it.

Copy this page, fill in the parts in [brackets], and send it with a short covering note. Keep it to what you have agreed on the call. No prices go in a pilot proposal; pricing is agreed separately.

---

## Pilot proposal for [Company name]

**What we propose.** A pilot of the platform for [Company name]'s e-invoicing in [Saudi Arabia / the UAE], first in the tax authority's test environment and then, if you are satisfied, live. We set it up with you.

**Why a pilot.** You see your own invoices, with your own VAT number, printers and people, before anything is filed for real. Nothing in the pilot is a legal tax record until you decide to go live.

## Scope

- **Company and branches:** [legal entity], [number] branch(es), [number] till(s) or device(s).
- **Documents:** [standard (B2B) / simplified (B2C) invoices], credit and debit notes.
- **Printing:** [A4 / 80 mm / 58 mm], in Arabic and English.
- **People:** [number] users, with roles (Owner, Admin, Member, Accountant, Viewer).
- **Data loaded:** [customers / products] from CSV files you provide.
- **Integration (optional):** [ERP or till system], sending invoices through the API, with webhooks back.
- **Books (optional):** purchases, receivables and the VAT return draft for your accountant.
- **Out of scope for the pilot:** [anything agreed as later, for example a second country or offline counters, which are planned].

## What we need from you

**Saudi Arabia:**
- VAT registration number (15 digits) and commercial registration (CR) number.
- National address: building number, street, district, city, postal code.
- The company's **Arabic legal name exactly as registered**, and the English name.
- A login to ZATCA's **Fatoora portal** with the right to generate one-time codes (OTP) in the **simulation** environment, and later in production. An OTP is valid for about an hour, so the person with this login joins the setup session.
- Arabic names for your products, or permission for us to prepare them for your review.

**UAE:**
- TRN, legal registration details and emirate.
- Your Peppol participant identifier and the accredited service provider you appointed through EmaraTax, or your plan to appoint one.
- Your e-invoicing start date for your revenue band.

**Both:** a named contact on your side, sample invoices you issue today, and the people who will use the portal.

## How it runs, step by step

1. **Kick-off call.** Agree scope, contacts and success criteria (below).
2. **Account and company profile.** You sign up; we check your company details with you, and the platform approves the account.
3. **Rehearsal on our side.** We run the full journey in ZATCA's developer sandbox before touching your details.
4. **Connect to the test environment.** Saudi Arabia: together, you generate an OTP in Fatoora's simulation portal and the platform obtains your certificate and passes ZATCA's compliance checks. UAE: we record your provider and start date.
5. **Load data and print.** Customers, products, invoice template and logo; test prints on your paper sizes.
6. **Your team issues test invoices** for [agreed number of days of normal trading], including a credit note and a refused invoice, so everyone sees what happens.
7. **Integration test (if in scope).** Your system sends invoices through the API in the test environment.
8. **Review.** We go through the success criteria with you.
9. **Go live, when you decide.** Saudi Arabia: a production OTP and certificate. UAE: from your start date, through your provider.

## Success criteria

Agree these at kick-off; tick them at the review.

- [ ] Saudi Arabia: every test invoice was accepted (cleared or reported) by ZATCA's simulation environment, or refused with a clear reason before numbering. UAE: every invoice passed the official PINT AE rules before it was stored.
- [ ] Saudi Arabia: printed and PDF invoices show the Arabic legal name exactly as registered, and the QR code scans.
- [ ] Your team can issue, correct with a note and find an invoice without help.
- [ ] Your accountant can produce the VAT return draft for the test period.
- [ ] [If integrated] Your system's invoices arrive once each, and webhooks reach it.
- [ ] [Your own criterion]

## After the pilot

- **If you go ahead:** we move you to production on a date you choose, and the support desk in the portal handles questions and requests. New customers' first month is free.
- **If you do not:** you can export everything you entered as CSV and PDF. Test-environment documents were never legal tax records.
- Either way, we send you a short written summary of the pilot.

Contact: **shamil3ilm@gmail.com**

---

Related: [Demo script](DEMO-SCRIPT.md) · [Objections](OBJECTIONS.md) · [Security and trust](SECURITY-AND-TRUST.md)
