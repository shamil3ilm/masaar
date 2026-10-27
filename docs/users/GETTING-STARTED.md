# Getting started

Audience: business owners and the person who sets up the company in the portal.

From sign-up to your first invoice. For the UAE, read this page and then
[UAE.md](UAE.md).

## 1. Sign up

Open **Create an account**. It takes four short steps:

1. **Your company** - the country, and the company whose invoices you will issue.
2. **What the authority knows** - the tax details its rules require.
   - Saudi Arabia: VAT number (15 digits, starting and ending with 3),
     commercial registration (CR) number (10 digits) and the national address.
   - UAE: TRN or TIN, the address with its emirate, and the legal registration.
3. **Your account** - your name, work email and password.
4. **Review** - check everything, then **Check and create**.

You also give the legal name in English and in Arabic. The Arabic name is
printed on every invoice and in its QR code.

If another company recommended the platform, enter its **Referral code**.

Then confirm your email address from the link we send. Your first month is free.

<!-- source: resources/views/auth/register.blade.php; resources/views/countries/saudi-arabia/signup/; resources/views/countries/united-arab-emirates/signup.blade.php -->

## 2. Wait for approval

A platform administrator reviews every company. While it is under review:

- you can sign in, complete your details and prepare draft invoices;
- connecting to the tax authority and issuing stay closed.

The portal shows **Your company is being reviewed.** until it is approved. If
it is turned down, you get the reason by email.

<!-- source: resources/views/layouts/portal.blade.php (isPendingReview); app/Domains/Compliance/Countries/SaudiArabia/Rules/SellerRules.php signupNext() -->

## 3. Complete the company

Owners and admins see **Organization settings** in the menu.

- **Company** - legal names (English and Arabic), VAT number, seller
  identification, national address, business type and whether your prices
  include VAT. The VAT number is fixed once you connect to ZATCA.
- **Seller identification type** (Saudi Arabia) - commercial registration,
  700 number, or a licence (MOMRAH, MHRSD, MISA) or other ID. ZATCA needs it
  to connect and on every standard invoice.
- **Branches** - each branch has its own address on the invoice, its own
  numbering and printer, and the people who work there. A UAE branch needs its
  emirate. Until you add a branch, every invoice is issued from head office.
- **Tills** (Saudi Arabia) - each till is its own ZATCA device, with its own
  certificate, counter and numbering. Add one under a branch, then connect it.
- **Numbering**, **Printing** and **Branding** - see
  [TEAM-AND-SETTINGS.md](TEAM-AND-SETTINGS.md).

<!-- source: resources/views/countries/saudi-arabia/company.blade.php; app/Domains/Compliance/Countries/SaudiArabia/Rules/FatooraConfig.php (ID schemes); resources/views/portal/branches/; resources/views/countries/saudi-arabia/branch-device.blade.php -->

## 4. Connect to ZATCA (Saudi Arabia)

Open **ZATCA** in the menu (owners and admins). It has three steps, done once:

1. **Compliance certificate** - enter a one-time code (OTP) and request the certificate.
2. **Compliance checks** - six sample documents are sent: standard and
   simplified invoice, credit note and debit note.
3. **Production certificate** - once the checks pass. This completes the connection.

The OTP is valid for one hour. Enter it straight away.

### Which environment, and where the OTP comes from

Each company works in one ZATCA environment. A platform administrator sets it.
The page tells you which one you are in.

| Environment | What it is for | Where the OTP comes from |
|---|---|---|
| Sandbox | Developer testing | The fixed test code ZATCA documents for its developer portal |
| Simulation | A real rehearsal with your own VAT number | The Fatoora portal's Simulation environment |
| Production | Live filing | The Fatoora portal |

In the Fatoora portal, choose to onboard a new solution unit and generate one OTP.

**The sandbox files only for ZATCA's own test VAT number.** With your real VAT
number every check passes there, but every invoice is then rejected, and the
VAT number cannot be changed after you connect. To test with your own VAT
number, ask for your company to be moved to Simulation **before** you connect.

Moving a company to another environment archives its certificates. You then
connect again with an OTP from the new environment.

<!-- source: resources/views/countries/saudi-arabia/connection.blade.php; app/Domains/Compliance/Countries/SaudiArabia/Rules/FatooraEnvironment.php; app/Domains/Compliance/Countries/SaudiArabia/Onboarding/EnvironmentSwitch.php -->

### Each till

A till connects with its own OTP, from **Device certificates**. Until a till is
connected, head office issues for that branch.

## 5. The setup checklist

The dashboard lists what is left: company details, the ZATCA connection, each
till, and anything that needs attention. A paused device always comes first.

<!-- source: app/Domains/Compliance/Countries/SaudiArabia/Presentation/SetupSteps.php -->

## Next

- Issue your first invoice: [INVOICING.md](INVOICING.md)
- Invite your team: [TEAM-AND-SETTINGS.md](TEAM-AND-SETTINGS.md)
- The whole flow as a checklist: [WORKFLOWS.md](WORKFLOWS.md)
