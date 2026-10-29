# Runbook: rehearsing the pilot's journey in ZATCA's sandbox

Audience: the operator preparing a staging server for a pilot client's first
run in ZATCA's simulation environment.

Before a pilot meets simulation, the whole journey is rehearsed on the
staging server against ZATCA's developer portal (the sandbox), with a
dedicated rehearsal organization. The session itself is in
[PILOT-SIMULATION.md](PILOT-SIMULATION.md).

Commands are run on the server in the application's directory, as
`php artisan ...`. If the application runs in containers, run them inside
the application container.

## 1. The server

| What | Why | How to confirm |
|---|---|---|
| Hosted in Saudi Arabia | Records of a taxpayer resident in the Kingdom must be kept on a server there (ZATCA Tax Invoicing and Records guideline, section 8.3.1). | Hosting region of the server and of its backups. |
| Its own subdomain over HTTPS | The pilot signs in there; links in emails point there. | Open the subdomain; the certificate is valid. |
| Outgoing mail through a real mail service | Sign-up verification, invitations and notices go by mail. | Preflight check "Outgoing mail", with `--send-test-mail`. |
| Queue workers running | Simplified invoices are reported by a worker; webhooks are delivered by one. | Preflight check "Queue workers". |
| Scheduler running every minute | It drains the offline queue, recovers stuck submissions and sends the heartbeat the preflight reads. | Preflight check "Scheduler". |
| Clock synchronised (NTP) | ZATCA refuses a document whose time is off. | Preflight check "Server clock". |
| `APP_ENV=production`, `APP_DEBUG=false` | A debug page can show secrets. | Preflight check "Production settings". |
| A platform admin with two-factor set up | Only a platform admin approves organizations and switches their environment. | Sign in to the console. The first admin is made with `php artisan platform:create-admin <email>`, which prints a one-time enrolment link; `php artisan platform:enrolment-link <email>` issues a new one. |

See the [deployment guide](../DEPLOYMENT.md) for setting these up.

## 2. Run the rehearsal command

```bash
php artisan platform:rehearse --admin=<your platform admin email>
```

It works only with the **rehearsal organization**, which it creates on the
first run (approved by you, set to the sandbox) and reuses afterwards. It
never reads or writes any other organization, and it refuses to run if the
rehearsal organization has been switched out of the sandbox.

It then does what the pilot will do, through the platform's own code:

| Step | What is checked |
|---|---|
| Company profile | VAT number, CRN, national address, and an Arabic legal name containing ة, so a name altered on the way into the QR or XML shows up. |
| Compliance certificate (OTP) | Onboarding with the sandbox's published OTP. |
| Six compliance documents | All six pass; ZATCA will not issue a production certificate otherwise. |
| Production certificate | Issued, and the organization is ready to file. |
| Simplified invoice, reported | Accepted (reported) by ZATCA. |
| Standard invoice with supply date, cleared | Accepted (cleared), and ZATCA's cleared copy kept. |
| Credit note against the standard invoice | Issued only once the original was accepted, then cleared. |
| Debit note against the simplified invoice | Issued only once the original was accepted, then reported. |
| Zero-rated line with its exemption code | Cleared with a VATEX code on the zero-rated line. |
| Invoice with a discount | Reported. |
| Foreign-currency invoice | Cleared, with an exchange rate to SAR. |
| PDFs, A4 and 80mm | Both render. The sandbox issues every caller one shared certificate, so there the PDF comes unstamped; in simulation and production it must carry the device's stamp. |
| QR tags | Tags 1 to 8 on every document and 9 on simplified ones; tag 1 is the registered Arabic name unchanged, tag 2 the VAT number. |
| Hash chain | Counters consecutive, each document's previous-invoice hash the hash of the one before (`fatoora:verify-hash-chain`). |
| Offline queue | None of the rehearsal's documents went through it. |
| Onboarding steps in the audit log | The compliance certificate, the checks and the production certificate each left an audit entry. |

Each line prints PASS or FAIL as it runs, then a table with the detail and
the fix. The command exits non-zero if anything failed. **Do not book the
pilot session until a run passes every step.**

Options:

- `--keep` leaves the rehearsal organization active so you can look at its
  invoices in the console. Without it, the organization is suspended at
  the end with a reason that says what it is, so nothing is issued under it
  by mistake. The next run reactivates it.
- `--wait=<seconds>` (default 120) is how long to wait for a queue worker
  to report a simplified document.

Nothing is ever deleted: every document the rehearsal issued stays on
record, marked in its notes as a rehearsal in the sandbox and not a tax
record.

## 3. Browser checklist

The command proves the engine. The browser proves the screens a person
uses. On the staging server, sign up a test company yourself and walk the
same path the pilot will. The sandbox accepts the OTP `123345`. A new
company starts in the deployment default (simulation on staging), so after
approving it set its **ZATCA environment** to sandbox in the console, before
step 1 on its ZATCA page.

The sandbox files only for ZATCA's test VAT number `399999999900003`; with
any other, onboarding passes but every invoice is rejected, and the ZATCA
page warns of it. Sign-up refuses a VAT number another organization holds,
and the rehearsal organization holds the test number from the first
`platform:rehearse` run. So the issuing steps below (Issue, Notes, Print and
PDF, Reports) pass in the browser only for a company signed up with the test
number before that first run. Otherwise check them on the rehearsal
organization's documents in the console (`platform:rehearse --keep`).

- [ ] **Home page and sign-up.** The four-step sign-up finishes; the
  verification email arrives in a real inbox; the first screen shows the
  set-up checklist and the review banner.
- [ ] **Approval.** In the console, the company appears under
  Organizations, awaiting review, with its VAT number shown. Approve it
  with a plan.
- [ ] **Company page.** VAT number, CRN, national address and Arabic legal
  name save and show again.
- [ ] **Branding and invoice template.** Logo, template and paper size
  save.
- [ ] **ZATCA page.** The environment shown is the sandbox. Step 1 with
  the OTP gives a compliance certificate; step 2 shows all six documents
  passed; step 3 says connected. The console's audit log shows the three
  steps.
- [ ] **Customers and products.** A business customer with VAT number and
  full address; a product at 15% and a zero-rated one with its exemption
  code; Arabic names on each.
- [ ] **Issue.** A simplified invoice is reported and a standard one (with
  supply date) cleared: check the state on Submissions, not only the
  message after issuing.
- [ ] **Notes.** A credit note against the cleared standard invoice and a
  debit note against the reported simplified one.
- [ ] **Print and PDF.** A4 for the standard invoice and 80mm for the
  simplified one, in English and Arabic; the QR scans, and shows the
  Arabic name exactly as registered.
- [ ] **A refused invoice.** A standard invoice without the buyer's
  address is refused with a message that names the field, and the form
  keeps what was typed.
- [ ] **Reports.** The VAT summary includes the documents just issued.
- [ ] **Team.** Invite an accountant; the invitation names the role; the
  accountant cannot issue.
- [ ] **Console.** Logs show each submission with ZATCA's answer; the
  offline queue is empty; the audit log has the approval, onboarding and
  issuing.
- [ ] **Phone width and Arabic.** The invoice screens work at phone width
  and in Arabic.

Record anything that fails and fix it before the session.
