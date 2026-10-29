# Runbook: a pilot's first run in ZATCA's simulation environment

Audience: the operator taking a pilot client through ZATCA's simulation environment.

The platform has no Saudi VAT number of its own, so it cannot onboard a
device in ZATCA's simulation environment by itself. The first real
simulation run is therefore the pilot client's. Everything that can be
proved without them is proved first:

1. **Rehearse** the whole journey on the staging server against ZATCA's
   developer portal (the sandbox), with a dedicated rehearsal organization:
   [PILOT-REHEARSAL.md](PILOT-REHEARSAL.md), which also lists what the
   server needs.
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

## 1. From the pilot

The server's own prerequisites are in the
[rehearsal runbook](PILOT-REHEARSAL.md#1-the-server). Collect these before
the session, and enter them on the pilot's company page (or check that they
did) at least a day before:

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

## 2. The switch

### 2.1 Preflight

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

### 2.2 Switch the pilot's organization only

In the console: **Organizations**, the pilot's organization, **ZATCA
environment**, choose **Simulation**, save.

- This moves **that organization only**. Do not change `ZATCA_ENVIRONMENT`:
  it is the default for every organization that has no environment of its
  own, and changing it moves all of them.
- Whatever the organization held in its previous environment (its
  certificates and its tills' certificates) is archived, never deleted.
  Its tills go back to pending. It has to onboard again, with an OTP from
  the simulation portal.
- The switch is refused while any document from the current environment is
  still waiting to be filed (the preflight's "Nothing waiting to be filed"
  check). The page lists what waits.
- A switch between the sandbox and simulation runs at once. A switch to or
  from production waits for a second platform admin's approval (as
  shipped; see the approval policies in the console).
- Each till's first document in simulation starts a new chain (no previous
  invoice hash). Invoice counters and numbers carry on.
- The change is in the audit log as `zatca.environment.changed`, with your
  account; a refused switch as `zatca.environment.refused`.

Open the pilot's ZATCA page as a platform admin and confirm it now shows
the simulation environment. `/api/health` now lists `simulation` under
`zatca_environments`.

### 2.3 The OTP

The pilot generates the OTP on the **Fatoora portal's simulation
environment** (onboard a new solution unit or device, then generate an
OTP), **just before step 2 of the session**. It is valid for about an hour
and works once. Do not let them generate it in advance.

---

## 3. The pilot session

Plan about an hour. One operator drives the console and the server; the
pilot uses their own account.

| # | The pilot does | The operator watches |
|---|---|---|
| 1 | Signs in. Opens the company page and confirms the VAT number, CRN, national address and the Arabic legal name, letter for letter. | That nothing on the company page changed since the preflight. |
| 2 | Opens the ZATCA page, checks it says Simulation, generates the OTP on the Fatoora simulation portal and enters it in step 1. | Audit log: the compliance certificate step. Application log: no errors. |
| 3 | Runs the compliance checks (step 2). | All six pass. **If any fails, stop here** and go to section 4. |
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

## 4. When something fails

| Symptom | Likely cause | What to do |
|---|---|---|
| Step 1 refused: OTP invalid or expired | The OTP is older than about an hour, was already used, or came from another environment's portal. | The pilot generates a new one on the **simulation** portal and enters it at once. |
| Step 1 refused: profile incomplete | VAT number, seller identification or national address missing or malformed. | Fix the company page; generate a new OTP. |
| Step 2: some compliance documents refused | The documents break a ZATCA rule; the refusal names it. | **Stop.** Do not ask for the production certificate. Copy the refusal from the ZATCA page and the application log, end the session politely and fix the cause. Running the checks again does not need a new OTP. |
| Step 3 refused | ZATCA did not record six passing documents for this compliance certificate. | Run step 2 again until all six pass, then step 3. If it still fails, start again from step 1 with a new OTP. |
| "Issued and filed", but Submissions shows Queued | The platform judged the gateway unreachable and queued the document offline. | Console, Offline queue, Process queue. Then run the preflight's gateway check. The scheduler drains the queue every five minutes. |
| A simplified invoice stays Issued | No worker picked up its submission. | Check the workers (preflight "Queue workers"). It must be reported within 24 hours of issue. |
| An invoice is Rejected | It broke a ZATCA rule; the invoice page lists the rule. | A rejected invoice is not corrected by a note: fix the cause and issue a corrected invoice. Common ones: BR-KSA-08 (seller identification), BR-KSA-63 (buyer's national address on a standard invoice). |
| A note is refused and stays a draft | ZATCA has not accepted its original yet. A note can be made only once the original is cleared or reported. | Wait until Submissions shows the original accepted, then issue the note. A rejected original takes no note: issue a corrected invoice. |
| Accepted with warnings | ZATCA accepted it and advised a correction, for example BR-KSA-15 when a standard invoice has no supply date. | Note it; correct the habit on the next invoice. |
| The PDF is refused with a message about stamping | The PDF is stamped with the onboarded certificate, and the stamp could not be made; the message gives the reason. | Note the invoice; the printed copy still works. Renew the certificate if the message says so; fix before production. |
| The QR or the invoice shows a different Arabic name | The registered name was altered on the way into the document. | Stop issuing; this must be fixed before production. |
| A document refused for its time | The server clock has drifted. | Turn on time synchronisation; run the preflight again. |
| No email arrives | Mail settings. | Preflight with `--send-test-mail`. |

---

## 5. Backing out

Nothing filed in the simulation environment is a tax record, so nothing
has to be reversed with ZATCA. Backing out is about the platform's own
state:

1. Ask the pilot to stop issuing.
2. Let anything already issued be filed: Submissions should show nothing
   pending, and the offline queue should be empty. Process the queue from
   the console if needed. The preflight's "Nothing waiting to be filed"
   check confirms it.
3. In the console, set the pilot's **ZATCA environment** back to what it
   was (it refuses while anything is still waiting to be filed; back to
   production waits for a second admin's approval). Its simulation
   certificates are archived, never deleted, its tills go back to pending,
   and the change is audited.
4. Tell the pilot what happens next. To return to simulation later they
   onboard again with a new OTP.

Never delete an organization, an invoice, a certificate or an audit
entry: records are kept, whatever the environment (ZATCA E-Invoicing
Detailed Guidelines, record keeping).

The rehearsal organization stays suspended between rehearsals; leave it
that way.
