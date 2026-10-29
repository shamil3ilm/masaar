# Workflows

Audience: business owners, accountants and platform operators who want the whole flow as steps.

Each flow is a numbered list with the states involved. Details are in the
linked guides.

## Onboarding a Saudi organization

1. The owner signs up and confirms the email. The organization is *pending review*.
2. A platform admin opens **Organizations**, then **Approve and assign plan**
   (or **Turn down** with a reason). The organization is *active*.
3. If the company will test with its own VAT number, the admin sets its
   **ZATCA environment** to simulation now, before it connects.
4. An owner or admin completes **Company**: legal names in both languages,
   VAT number, seller identification and national address.
5. **ZATCA**: enter an OTP from that environment, then *Compliance certificate*
   → *Compliance checks* (six documents) → *Production certificate*. The
   organization is *onboarded*.
6. Optional: add branches and tills, and connect each till with its own OTP.
7. Invite the team.

Guides: [GETTING-STARTED.md](GETTING-STARTED.md), [ADMIN-CONSOLE.md](ADMIN-CONSOLE.md).

## Issuing and filing

Invoice: *draft* → *issued* → *accepted* or *rejected*.
Filing: *waiting to be sent* (queued) → *sent* → *cleared*, *reported*,
*accepted with warnings*, *rejected* or *failed*.

1. Save a draft. It can be edited or deleted.
2. **Issue invoice**. Every rule is checked first. If one fails, nothing is
   numbered and the error says why.
3. The invoice is numbered, signed, given its QR code and handed for filing.
   - **Standard**: sent for clearance. It may be given to the buyer only once cleared.
   - **Simplified**: reported by a background worker. It must be reported within 24 hours.
4. The answer arrives:
   - **Cleared / Reported** - the invoice is *accepted*.
   - **Accepted with warnings** - accepted; the advice is listed on the invoice.
   - **Rejected** - a verdict. It is not sent again. Issue a corrected invoice.
   - **Failed** - it did not get through. The invoice keeps its number.
5. **Offline**: after 5 failures in a row the connection is treated as down
   and new filings wait in the offline queue. The queue is sent every 5 minutes
   once the authority answers again.
6. **Retry**: submissions left in flight are recovered every 10 minutes. A
   failed one can be sent again by an owner or admin (**Send again**) or an
   operator (**Logs**). Every check runs again first.
7. A simplified invoice not reported within 24 hours appears under **Late
   reporting**. Notify ZATCA and record it there.

<!-- source: app/Domains/Invoice/Http/Controllers/PortalInvoiceFilingController.php; app/Domains/Compliance/Countries/SaudiArabia/Filing/SubmissionState.php; config/fatoora.php (circuit_breaker.failure_threshold); routes/console.php (fatoora:process-offline, fatoora:recover-submissions) -->

## Credit note lifecycle

1. Open the issued invoice. Wait until it is *accepted*.
2. Press **Credit note**. The form is filled in from the invoice. Choose the reason.
3. If your company requires approval for notes, it waits in **Approvals**
   until another owner or admin approves it. Rejected, it stays a draft.
4. Issued, it is filed like any invoice and reduces what the customer owes in Books.

A note against an invoice that is not yet accepted stays a draft and says
which original it waits for. A rejected invoice is not corrected with a note.

## A ZATCA error at each stage

| Stage | What happens | What you do |
|---|---|---|
| Company details incomplete | **ZATCA** page will not start onboarding. | Complete **Company**. |
| Wrong or expired OTP | The certificate request fails. | Generate a new OTP (valid for one hour). |
| Compliance checks fail | No production certificate. | Read *ZATCA said*, fix the details, run the checks again. |
| A rule fails at issue | Nothing is numbered. | Fix the draft and issue again. |
| Authority unreachable | Filing *failed* or queued offline. | Nothing; it is retried. Or **Send again**. |
| Rejected | The invoice is *rejected*. | Issue a corrected invoice. |
| Certificate refused | The device's filing *pauses*; its invoices wait. | Renew the certificate under **Device certificates**. |
| Certificate expiring | Warnings before it expires. | Renew it. |

## UAE before and after the start date

1. Before the start date: invoices are issued and kept, shown as *issued, not
   transmitted*. Nothing is sent.
2. Appoint a service provider through the authority's tax portal and record it
   on **Company**. Reminders are sent 90, 30 and 7 days before the start date.
3. From the start date: each invoice is sent through the provider. Delivery
   (*awaiting delivery* → *delivered* or *rejected*) and the report to the
   authority (*reported* or *failed*) are tracked separately.
4. With no provider recorded after the start date, issuing is refused.

Guide: [UAE.md](UAE.md).

## Month-end close in Books

1. Record every purchase of the month, with the supplier's document. Use
   **Received on**: it sets the VAT period.
2. Record customer payments against sales, and payments to suppliers.
3. UAE: mark purchases you will not pay within six months; record any supplier write-offs.
4. Check **Receivables** as at the month end and send statements.
5. Open **VAT return** for the period, type in corrections and carried-forward
   figures, and **Draft the return**. Open any box to check its documents.
6. **Download PDF** or **CSV**, and file the return yourself with the authority.

Saudi organizations can also check each day's **Daily close by device** under **Reports**.

Guides: [BOOKS.md](BOOKS.md), [VAT-RETURN.md](VAT-RETURN.md).

## Platform billing to cash

For operators. Finance changes need the *billing.manage* permission.

1. A company is approved with a plan. Its first month is free by default.
2. **Issue invoice** on the company's **Plan and billing** page. The invoice
   is a receivable: *open* → *partly paid* → *paid*, or *overdue* / *written off*.
3. The company pays by bank transfer quoting the invoice number, or by card where offered.
4. Record the payment: **Record a bank transfer** or **Record a plan payment**
   (which also renews the licence). An overpayment becomes credit.
5. **Reconciliation**: import the bank statement or card settlement report.
   Lines are matched by reference, then by amount and date. Confirm, reject or
   match the rest by hand. Confirming a match records the payment.
6. **Finance reports** and **Expense reports**: receivables, payments,
   collections, ageing, the VAT return draft and profit and loss.

Guide: [ADMIN-CONSOLE.md](ADMIN-CONSOLE.md#finance).

<!-- source: app/Domains/Billing/Services/Payments.php; app/Domains/Billing/Reconciliation/Services/Matcher.php; app/Domains/Billing/Enums/ReceivableStatus.php -->

## The pilot simulation

The first real run in ZATCA's simulation environment is done with a pilot
company, step by step, in the runbook:
[PILOT-SIMULATION.md](../operations/runbooks/PILOT-SIMULATION.md).
