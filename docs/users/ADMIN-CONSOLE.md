# Admin console

Audience: platform operators who run the platform for every company on it.

The console is at `/admin`. It is in English. Every page is open to any admin
with console access; changing things needs the permission named below. Every
admin action is recorded in the audit log.

<!-- source: routes/web.php (admin group: platform.admin, admin.can:console.view, admin.audit); app/Domains/Auth/Enums/AdminPermission.php -->

## First admin and two-factor

1. On the server: `php artisan platform:create-admin <email>`. It creates the
   platform's one owner and prints a one-time link. It refuses once an owner exists.
2. Open the link, sign in and set up two-factor. Admins cannot use the console without it.
3. Invite more admins from **Admins > Invite an admin**. They accept, create
   their account if needed, and set up two-factor.

Lost two-factor? Another admin uses **Reset two-factor** or **Issue enrolment
link** on the user's page, or on the server:
`php artisan platform:enrolment-link <email> --reset`.

A sole owner locked out by approvals can recover with `php artisan platform:owner-recover`.

<!-- source: app/Domains/Auth/Console/CreatePlatformAdmin.php; app/Domains/Auth/Console/IssueEnrolmentLink.php; app/Domains/Platform/Console/RecoverOwner.php -->

## Admins and roles

| Role | Can |
|---|---|
| Owner | Everything. There is one owner; **Offer ownership** hands it on. |
| Operations | Everything except platform configuration and managing admins. |
| Support | The console, the support desk and resetting users' two-factor. |
| Auditor | The console and the audit log. |

**Admin roles** adds custom roles from the same permissions.

**Export CSV** on Organizations, Licences and the offline queue needs the
permission that manages that list (*organizations.review*,
*licences.manage*, *operations.run*); the audit log's needs *audit.view*.
The button is shown only to an admin who holds it.

<!-- source: app/Domains/Auth/Enums/PlatformRole.php; resources/views/admin/admins.blade.php; resources/views/admin/roles.blade.php -->

## Approving companies

New companies wait under **Organizations** as *Awaiting review*.

- **Approve and assign plan** - choose the plan and, optionally, when it ends.
  Left empty, the plan runs for the free period set in Finance settings and
  nothing is invoiced for it.
- **Turn down** - with a reason sent to the company.

<!-- source: resources/views/admin/organization-detail.blade.php -->

## An organization's page

- Totals, details, certificate status, members and recent submissions.
- **Setup** - the company's checklist (company details, ZATCA connection, each
  till, a UAE company's service provider).
- **Features** - switch a feature off for this company, or on beyond its plan,
  with a reason. Issuing can also be switched off for one role or one person.
- **Arabic text** - invoices missing Arabic names, and a grace period
  (*Warn instead of refuse until*). An approval policy can make granting it
  wait for a second admin (off as shipped).
- **Suspend organization** / **Reactivate organization** - while suspended it
  issues nothing new; its people still read and export, and issued invoices
  keep being filed.
- **Plan and billing** - see Finance below.

### ZATCA environment

**ZATCA environment** sets which environment a Saudi company works in:
sandbox, simulation or production (the deployment default otherwise).

- Switching archives every certificate the company and its tills hold.
  Nothing is deleted. The company connects again with an OTP from the new environment.
- Each till's first document in the new environment starts a new chain (no
  previous invoice hash). Invoice counters and numbers carry on; they are
  never reset.
- **The switch is refused while any document signed in the current environment
  is not yet filed.** The page lists them (queued offline, report overdue, or
  waiting for ZATCA). File them first.
- Needs the *platform.configure* permission. Both the switch and a refusal are audited.
- **A switch to or from production waits for a second admin's approval**
  (held as shipped). Say why, then **Save environment**; it runs once
  approved. A switch between sandbox and simulation is not held. With no
  other admin who could approve, it goes through and is recorded as bypassed.

A company with its own VAT number cannot file in the sandbox. Move it to
simulation before it connects.

The same page downloads the company's **E-invoice archive** for up to a year of
issue dates (the *operations.run* permission); on a server,
`php artisan fatoora:export-archive {organization} --from= --to=` writes any
period to a ZIP. Both are recorded on the company's activity.

<!-- source: resources/views/countries/saudi-arabia/admin/environment.blade.php; app/Domains/Compliance/Countries/SaudiArabia/Onboarding/EnvironmentSwitch.php; app/Domains/Compliance/Countries/SaudiArabia/Onboarding/EnvironmentSwitchActions.php; app/Domains/Compliance/Countries/SaudiArabia/Issuing/ChainStart.php; app/Domains/Compliance/Countries/SaudiArabia/ModuleServiceProvider.php -->

### Paused devices

When ZATCA refuses a device's certificate, its filing pauses: the company is
emailed, and what it issues waits until it renews the certificate.

<!-- source: app/Domains/Compliance/Countries/SaudiArabia/Presentation/SetupSteps.php; resources/views/countries/saudi-arabia/emails/filing-paused.blade.php -->

## Filing operations

- **Logs** - every submission, by state. **Send again** on a failed one runs
  every check first.
- **Offline queue** - invoices waiting to be sent while the authority was
  unreachable. **Process queue** sends them now; **Retry** sends one.
- **Alerts** - invoices past their filing deadline.
- **Certificates** - device certificates. Marking one revoked waits for a
  second admin as shipped, and needs *platform.configure*.
- **Peppol** (UAE) - resend a document, or retry a failed report.
- **Kill switches** - stop issuance, submission, clearance, reporting or
  signing, or force offline mode, for a time and with a reason.
  **Stop everything** and **Resume everything** act on all of them.
- **Webhooks** - every company's endpoints and deliveries; replay a delivery.

<!-- source: resources/views/admin/logs.blade.php; resources/views/admin/queue.blade.php; app/Domains/Platform/Services/KillSwitch.php; routes/web.php -->

## Audit log and approvals

- **Audit log** - who did what, from where, with filters and a CSV export.
- **Approvals** - requests waiting for a second admin, with **Approve**,
  **Reject** and **Withdraw**. Nobody approves their own.
- **Settings > Approval policies** - per action: on or off, approvers needed,
  who may approve, and how long a request stays open. **Allow bypass when no
  other approver exists** lets a new platform with one owner work; each bypass
  is recorded and emailed to the owner. Details: [APPROVALS.md](../architecture/APPROVALS.md).

## Settings and customization

- **Settings** - defaults for everyone. An organization can set its own, and a
  person their own, unless the platform locks it. Also **Business types** and
  **Request categories**.
- **Countries** - switch e-invoicing in a country on or off. Documents already
  issued are still filed.
- **Branding** - the platform's name, colours, logo and invoice template.

How the levels work: [CUSTOMIZATION.md](../architecture/CUSTOMIZATION.md).

## Users and licences

- **Users** - search, suspend, reactivate, send a password reset link, reset
  two-factor, and see each account's history.
- **Licences** - API licences: create, suspend, reactivate, revoke, rotate and set allowances.
- **Renewals** - plans that end soon or ended recently.

## Finance

Reading is open to every admin. Changing anything needs the *billing.manage* permission.

- **Plan and billing** (on each organization) - account standing, **Issue
  invoice**, **Record a bank transfer**, **Record a plan payment**, credit and
  pay-back, refunds (a credit note), write-offs, the statement and the
  customer's payment terms and invoice currency.
- **Receivables** - owed by age; statuses Open, Partly paid, Paid, Overdue, Written off.
- **Reconciliation** - import bank statements (CSV, camt.053, MT940) or card
  settlement reports, then **Confirm**, **Reject** or **Match** each line.
  Only a high-confidence match confirms itself.
- **Expenses**, **Recurring costs**, **Suppliers** and **Payables**.
- **Finance reports** (receivables, payments, VAT, ageing, collections) and
  **Expense reports** (including the VAT return draft and profit and loss by
  month). A reversed payment stays in its month and shows as a negative
  **Reversed** row in the month it was reversed. The VAT report lists the
  billing company's own invoices and notes, each in the period of its tax
  point (the earlier of supply and issue). An invoice credited in full does
  not count in days to pay.
  The profit and loss shows a write-off before VAT (**Bad debts written off**)
  and its VAT apart (**VAT on bad debts written off**), and VAT taken back as
  bad-debt relief on the billing company's own invoices as a gain; the VAT
  view has a line for each. **Revenue deferral CSV** spreads each
  subscription over the months it covers: what belongs to each month and what
  is still deferred. It is a report beside the P&L, which stays on the date
  invoiced until the accounting policy is decided.
- **Journal CSV**, **Trial balance CSV** and **Cash by bank account CSV** on
  **Expense reports** - the platform's books for its accountant, in the tax
  currency, every entry balanced. They need *billing.manage*. Payables by age
  as at a past day include a bill voided since.
- **Finance settings** - currencies (SAR on, AED off by default), bank
  accounts, VAT on purchases, lists, statement profiles, rates and the freeze.

### Payment freeze (off by default)

When turned on in Finance settings, an overdue company moves through
reminders, grace, read-only and frozen. Each company can have its own days or
be exempt. An admin can also **Freeze by hand** or **Unfreeze by hand** with a
reason. A frozen company can still read and export its records, file what it
issued and correct an invoice with a credit note.

<!-- source: app/Domains/Billing/Settings/FinanceCatalogue.php (finance.freeze_enabled default false); app/Domains/Billing/Services/Freeze.php; resources/views/admin/billing.blade.php -->

### Referrals (off by default)

**Referrals** sets the programme's terms: rewards for both sides (percentage
off, fixed credit or free months), when a referral qualifies, and caps. A
company can have its own terms. Self-referrals (same company, VAT number,
people or email domain) are refused. **Approve**, **Reverse** or **Adjust** a reward.

<!-- source: app/Domains/Referral/DTOs/ReferralTerms.php; app/Domains/Referral/Services/SelfReferral.php -->

## Support, board and website

- **Support** - customer requests. Assign, reply, set status, turn a request
  into a priced customization, or link it to the feature board.
- **Feature board** - items worded with no company named. Stages: submitted,
  under review, planned, in progress, released, declined. **Publish** to show
  it to customers; **Merge** duplicates.
- **Website** - **Home page** sections (edit, order, hide, reset),
  **Testimonials** (off by default), **Search and sharing** text in both
  languages, and **Messages** from the contact form.

<!-- source: app/Domains/Support/Enums/; app/Domains/Platform/Website/SectionCatalogue.php -->
