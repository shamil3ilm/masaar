# Runbook: a pilot's first run in ZATCA's simulation environment

Audience: the operator taking a pilot client through ZATCA's simulation environment.

The platform has no Saudi VAT number of its own, so it cannot onboard a
device in ZATCA's simulation environment by itself. The first real
simulation run is therefore the pilot client's. Everything that can be
proved without them is proved first:

1. **Rehearse** the whole journey on the staging server against ZATCA's
   developer portal (the sandbox), with a dedicated rehearsal organization.
2. **Check** the pilot's organization and the server with a read-only
   preflight.
3. **Switch** only the pilot's organization to simulation. Every other
   organization stays where it is.
4. **Run the session** with the pilot, watching submissions, the offline
   queue and the audit log.

Commands are run on the server in the application's directory, as
`php artisan ...`. If the application runs in containers, run them inside
the application container.

---

## 1. Prerequisites

### The server

| What | Why | How to confirm |
|---|---|---|
| Hosted in Saudi Arabia | Records of a taxpayer resident in the Kingdom must be kept on a server there (ZATCA Tax Invoicing and Records guideline, section 8.3.1). | Hosting region of the server and of its backups. |
| Its own subdomain over HTTPS | The pilot signs in there; links in emails point there. | Open the subdomain; the certificate is valid. |
| Outgoing mail through a real mail service | Sign-up verification, invitations and notices go by mail. | Preflight check "Outgoing mail", with `--send-test-mail`. |
| Queue workers running | Simplified invoices are reported by a worker; webhooks are delivered by one. | Preflight check "Queue workers". |
| Scheduler running every minute | It drains the offline queue, recovers stuck submissions and sends the heartbeat the preflight reads. | Preflight check "Scheduler". |
| Clock synchronised (NTP) | ZATCA refuses a document whose time is off. | Preflight check "Server clock". |
| `APP_ENV=production`, `APP_DEBUG=false` | A debug page can show secrets. | Preflight check "Production settings". |
| A platform admin with two-factor set up | Only a platform admin approves organizations and switches their environment. | Sign in to the console. A first admin gets an enrolment link with `php artisan platform:enrolment-link <email>`. |

See the [deployment guide](../DEPLOYMENT.md) for setting these up.

### From the pilot

Collect these before the session, and enter them on the pilot's company
page (or check that they did) at least a day before:

- **VAT number**: 15 digits, starting and ending with 3.
- **Seller identification** and its type: the 10-digit commercial
  registration number (CRN), or another identification ZATCA allows for
  the seller (BR-KSA-08): MOMRAH, MHRSD or MISA licence, 700 number, or
  another ID. It is chosen on the company page.
- **National address**, complete: street, 4-digit building number,
  district, city and 5-digit postal code.
- **Arabic legal name exactly as registered** on the commercial
  registration, letter for letter. It is printed on every invoice and
  carried in its QR code and XML.
- **Their own login to the Fatoora portal's simulation environment.** The
  pilot generates the onboarding OTP there themselves; nobody else can.
- Who will attend, and an email address for the platform account.

Their company must be **approved** in the console before the session.
Approval assigns a plan; until then nothing can be onboarded or issued.

---

## 2. The rehearsal (sandbox)

### 2.1 Run the rehearsal command

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

### 2.2 Browser checklist

The command proves the engine. The browser proves the screens a person
uses. On the staging server, sign up a test company yourself (any test
details; the sandbox accepts the OTP `123345`) and walk the same path the
pilot will:

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

---

## 3. The switch

### 3.1 Preflight

```bash
php artisan platform:simulation-preflight <pilot organization id or VAT number> --send-test-mail=<your email>
```

It changes nothing. Each check prints PASS or FAIL with the fix:

| Check | Passes when |
|---|---|
| Organization approved | It is active: approved and not suspended. |
| VAT number | 15 digits, starting and ending with 3. |
| Seller identification | A seller identification of an allowed type is recorded, in that type's format. |
| National address | Street, 4-digit building number, district, city and 5-digit postal code. |
| Arabic legal name | Present, in Arabic letters. |
| Nothing waiting to be filed | No document issued in its current environment is waiting for ZATCA. A switch archives the certificate those documents were signed with, so the switch itself refuses while any wait. |
| Outgoing mail | The mailer is a real mail service, not the log or array transport. |
| Test message | Sent (only with `--send-test-mail`); check that it arrived. |
| Queue workers | A worker picked up the heartbeat on each queue within five minutes. |
| Scheduler | It ran within five minutes. |
| Simulation gateway reachable | ZATCA's simulation gateway answers from this server, with any HTTP status, as the platform judges it before filing; only a failed or timed-out connection is unreachable. |
| Server clock | Within five seconds of the gateway's clock. |
| Certificate storage writable | Onboarding can store the certificates it obtains. |
| Production settings | `APP_ENV=production` and debug off. |

**Switch only when every check passes.** Run it again on the day, shortly
before the session.

### 3.2 Switch the pilot's organization only

In the console: **Organizations**, the pilot's organization, **ZATCA
environment**, choose **Simulation**, save.

- This moves **that organization only**. Do not change `ZATCA_ENVIRONMENT`:
  it is the default for every organization that has no environment of its
  own, and changing it moves all of them.
- Whatever the organization held in its previous environment (its
  certificates and its tills' certificates) is archived, never deleted.
  Its tills go back to pending. It has to onboard again, with an OTP from
  the simulation portal.
- The change is in the audit log as `zatca.environment.changed`, with your
  account.

Open the pilot's ZATCA page as a platform admin and confirm it now shows
the simulation environment.

### 3.3 The OTP

The pilot generates the OTP on the **Fatoora portal's simulation
environment** (onboard a new solution unit or device, then generate an
OTP), **just before step 2 of the session**. It is valid for about an hour
and works once. Do not let them generate it in advance.

---

## 4. The pilot session

Plan about an hour. One operator drives the console and the server; the
pilot uses their own account.

| # | The pilot does | The operator watches |
|---|---|---|
| 1 | Signs in. Opens the company page and confirms the VAT number, CRN, national address and the Arabic legal name, letter for letter. | That nothing on the company page changed since the preflight. |
| 2 | Opens the ZATCA page, checks it says Simulation, generates the OTP on the Fatoora simulation portal and enters it in step 1. | Audit log: the compliance certificate step. Application log: no errors. |
| 3 | Runs the compliance checks (step 2). | All six pass. **If any fails, stop here** and go to section 5. |
| 4 | Gets the production certificate (step 3). | The page says connected, in the simulation environment. Audit log entry. |
| 5 | Adds a customer (business, with VAT number and full address) and a product, with Arabic names. | - |
| 6 | Issues a simplified invoice. | Submissions: it becomes **Reported** within a minute. Offline queue: empty. |
| 7 | Issues a standard invoice with a supply date. | Submissions: **Cleared**. The printed copy and the PDF open. |
| 8 | Prints both (A4 and 80mm) and scans each QR with a phone. | The QR shows the registered Arabic name and VAT number unchanged. |
| 9 | Issues a credit note against the cleared standard invoice. | Submissions: **Cleared**. |
| 10 | Optionally: a zero-rated line with its exemption code, a discount, a foreign-currency invoice. | Each accepted; any warnings read out and noted. |
| 11 | Opens Reports and checks the VAT summary. | The figures match what was issued. |

Throughout, keep three console pages open:

- **Logs** (submissions): every document with ZATCA's answer. An accepted
  document with warnings is legally accepted; note each warning.
- **Offline queue**: should stay empty. Anything here means the platform
  judged the gateway unreachable.
- **Audit log**, filtered to the pilot's organization: the environment
  switch, each onboarding step, each invoice issued.

---

## 5. When something fails

| Symptom | Likely cause | What to do |
|---|---|---|
| Step 1 refused: OTP invalid or expired | The OTP is older than about an hour, was already used, or came from another environment's portal. | The pilot generates a new one on the **simulation** portal and enters it at once. |
| Step 1 refused: profile incomplete | VAT number, seller identification or national address missing or malformed. | Fix the company page; generate a new OTP. |
| Step 2: some compliance documents refused | The documents break a ZATCA rule; the refusal names it. | **Stop.** Do not ask for the production certificate. Copy the refusal from the ZATCA page and the application log, end the session politely and fix the cause. Running the checks again does not need a new OTP. |
| Step 3 refused | ZATCA did not record six passing documents for this compliance certificate. | Run step 2 again until all six pass, then step 3. If it still fails, start again from step 1 with a new OTP. |
| "Issued and filed", but Submissions shows Queued | The platform judged the gateway unreachable and queued the document offline. | Console, Offline queue, Process queue. Then run the preflight's gateway check. The scheduler drains the queue every five minutes. |
| A simplified invoice stays Issued | No worker picked up its submission. | Check the workers (preflight "Queue workers"). It must be reported within 24 hours of issue. |
| An invoice is Rejected | It broke a ZATCA rule; the invoice page lists the rule. | A rejected invoice is not corrected by a note: fix the cause and issue a corrected invoice. Common ones: BR-KSA-08 (seller identification), BR-KSA-63 (buyer's national address on a standard invoice). |
| Accepted with warnings | ZATCA accepted it and advised a correction, for example BR-KSA-15 when a standard invoice has no supply date. | Note it; correct the habit on the next invoice. |
| The PDF is refused with a message about stamping | The PDF is stamped with the onboarded certificate, and the stamp could not be made; the message gives the reason. | Note the invoice; the printed copy still works. Renew the certificate if the message says so; fix before production. |
| The QR or the invoice shows a different Arabic name | The registered name was altered on the way into the document. | Stop issuing; this must be fixed before production. |
| A document refused for its time | The server clock has drifted. | Turn on time synchronisation; run the preflight again. |
| No email arrives | Mail settings. | Preflight with `--send-test-mail`. |

---

## 6. Backing out

Nothing filed in the simulation environment is a tax record, so nothing
has to be reversed with ZATCA. Backing out is about the platform's own
state:

1. Ask the pilot to stop issuing.
2. Let anything already issued be filed: Submissions should show nothing
   pending, and the offline queue should be empty. Process the queue from
   the console if needed. The preflight's "Nothing waiting to be filed"
   check confirms it.
3. In the console, set the pilot's **ZATCA environment** back to what it
   was (it refuses while anything is still waiting to be filed). Its simulation certificates are archived, never deleted, its tills
   go back to pending, and the change is audited.
4. Tell the pilot what happens next. To return to simulation later they
   onboard again with a new OTP.

Never delete an organization, an invoice, a certificate or an audit
entry: records are kept, whatever the environment (ZATCA E-Invoicing
Detailed Guidelines, record keeping).

The rehearsal organization stays suspended between rehearsals; leave it
that way.
