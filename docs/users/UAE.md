# UAE e-invoicing

Audience: business owners and admins of a company registered in the UAE.

In the UAE, e-invoices travel over the Peppol network through an accredited
service provider that your company appoints. The platform prepares, sends and
receives them through that provider. Read [GETTING-STARTED.md](GETTING-STARTED.md)
first.

UAE e-invoicing is switched off on a platform until an operator turns it on.
<!-- source: config/peppol.php (PEPPOL_AE_ENABLED, default false); resources/views/admin/countries.blade.php -->

## Company details

Under **Company**:

- legal names in English and Arabic;
- **TRN** (15 digits, starting with 1 and ending with 03) or **TIN** (10 digits, starting with 1);
- the address, with its **Emirate**;
- the legal registration: its type, number and issuing authority;
- the **Peppol endpoint** your provider issued (`0235:` followed by 10 digits);
- optionally a payment account (IBAN), printed on invoices paid by bank transfer.

Each branch also needs its emirate. The VAT return puts each sale in the
emirate of the branch it was made from.

<!-- source: resources/views/countries/united-arab-emirates/company.blade.php, tax-identity.blade.php, legal-registration.blade.php, address.blade.php; app/Domains/Compliance/Countries/UnitedArabEmirates/Reports/VatReturn.php -->

## Before e-invoicing starts

Set your **E-invoicing start date** on the company page: the date e-invoicing
becomes mandatory for you, or an earlier date if you start voluntarily. Leave
it empty while you are not yet in scope.

Before that date:

- you issue tax invoices as usual and they are kept in the portal;
- none is sent to a service provider or reported;
- each shows **Not reported: issued before e-invoicing started**.

Invoices issued before the start date are never sent, even after it.

<!-- source: resources/views/countries/united-arab-emirates/company.blade.php; resources/views/countries/united-arab-emirates/invoice/panel.blade.php -->

## Appoint your service provider

1. Appoint one accredited service provider through the authority's tax portal.
2. On **Company**, record it under **E-invoicing service provider**: who, the
   date appointed and, optionally, the appointment reference.

Reminders are emailed before your last date to appoint, and 90, 30 and 7 days
before your start date while no provider is recorded.

From the start date, an invoice **cannot be issued** until a provider is
recorded. The portal says so and points you to the company page.

<!-- source: app/Domains/Compliance/Countries/UnitedArabEmirates/Console/RemindProviderAppointments.php; app/Domains/Compliance/Countries/UnitedArabEmirates/Issuing/IssuingStep.php -->

## After e-invoicing starts

Every invoice you issue is sent through your provider. Two things are tracked
separately on each invoice:

- **Delivery to the buyer** - awaiting delivery, delivered or rejected.
- **Report to the authority** - reported, or the report failed (with the reason).

If the provider is not answering, the e-invoice waits and is sent as soon as it
answers again. You do not need to resend it.

The dashboard shows a tile for each of these states that has any e-invoices,
each with a line saying what it means. Before your first e-invoice is sent it
shows none of them.

**Received invoices** lists e-invoices other companies sent you, with their XML.

<!-- source: resources/views/countries/united-arab-emirates/dashboard.blade.php; resources/views/countries/united-arab-emirates/invoice/panel.blade.php; resources/views/countries/united-arab-emirates/received/ -->

## Test mode

A platform can run with a service-provider simulator. The invoice then says
so: nothing is sent on the Peppol network or reported.

## Books in the UAE

Input VAT follows the pay-or-intend-to-pay rule, and the VAT return is VAT 201.
See [BOOKS.md](BOOKS.md) and [VAT-RETURN.md](VAT-RETURN.md).
