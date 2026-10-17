# Masaar standalone release plan

## Product decision

Release Masaar as a focused invoicing and compliance product for a business that
issues invoices in its own portal. Keep the Laravel application, its existing
server-rendered portal, partner API, database, queue workers, and jurisdiction
modules. Do not import the ERP applications or their migrations.

This plan assumes Saudi Arabia is the first customer journey to refine. UAE
issuing stays disabled until a real accredited service provider is integrated
and tested. A Saudi developer-sandbox demonstration is useful, but a paid live
filing claim needs a registered taxpayer's simulation and production pilot.

## What already exists

- Registration, email verification, organization review, team access, and a
  first-invoice setup checklist.
- Company details, customers, products, numbering settings, invoice drafts,
  issue actions, credit/debit notes, a searchable invoice list, PDF download
  and email for shareable Saudi documents, and VAT/CSV reports.
- ZATCA and PINT AE modules, country availability controls, the partner API,
  queues, webhooks, and operator screens.

Do not rebuild these features from the ERP. Verify them as one customer journey
and fix the places where that journey stops or asks for unnecessary expertise.

## Release milestones

### 0. Stabilize the current code

Finish and commit the in-progress Saudi module relocation. Run the full test
suite, migration checks on a fresh database, and the configured conformance
jobs. Keep the country switch and existing issuing behavior covered. This is
the prerequisite for product changes; the present working tree is not a stable
release baseline.

**Done when:** a clean checkout builds and deploys, all required checks pass,
and an invoice can be drafted and issued in each enabled test jurisdiction.

### 1. Make the first session useful and honest

Walk the actual path from landing page to a saved draft. State which countries
can issue in the deployment and what authority/provider setup is still needed.
For a new company, show the next required action, who must perform it, and
whether drafting is available while approval or onboarding is pending. Offer a
controlled sandbox demonstration separately from live taxpayer filing.

**Done when:** a new user can register, understand the wait and next step, and
save a draft without operator guidance. The UI never presents sandbox or
unconnected-provider output as live accepted filing.

### 2. Complete the draft-to-delivery journey

Add a portal edit action for a saved draft; the portal currently has draft
creation and issue routes but no draft edit route. Make the common first-invoice
form shorter: start with buyer, items, date, and invoice type; reveal fields
needed for a selected tax or document scenario. Preserve the detailed form for
advanced cases. Before issue, show a review of the frozen amounts and explain
that issued documents cannot be edited.

On the invoice page, present a plain-language timeline: draft, issued, filing
attempt, accepted/rejected, and delivered. Show the next action on failures.
Keep compliance state separate from delivery state. For Saudi standard
invoices, permit customer delivery only from the cleared document. For UAE,
show provider delivery and acknowledgement state when a provider exists.

**Done when:** a first-time user can create, correct, issue, and deliver a valid
test invoice without learning internal status names; a blocked or rejected
invoice says what happened and what can be done next.

### 3. Add customer payments and outstanding balances

Create a separate customer-payment record under the Invoice domain. Do not
reuse `payments`: that table records Masaar subscription payments. Start with
manual recording of one payment against one issued invoice, a reference/date/
method, partial payments, and an auditable reversal or correction. Derive the
paid and outstanding amounts from payment records; do not change the signed
invoice total, XML, hash, or compliance status when money arrives. Refuse
allocations above the amount due and cross-organization invoice IDs. Use
decimal arithmetic and lock the invoice while recording a payment.

Show amount due on invoice detail and list, and add paid, partial, unpaid, and
overdue filters. Define a due date for countries and document types where the
product offers overdue tracking; do not infer one silently from issue date.
Multi-invoice allocations, overpayments, refunds, bank feeds, and accounting
entries can follow after a demonstrated need.

**Done when:** a business can answer who owes it money, record a partial or
full payment, and correct a mistaken record without altering the legal invoice.

### 4. Validate a customer release

Test the complete browser journey with a user unfamiliar with ZATCA: register,
complete setup, add a buyer, save and edit a draft, issue, inspect the outcome,
send/download the accepted document, record payment, and find the invoice
later. Cover English and Arabic, mobile width, and a viewer role. Exercise
queue failure/recovery and the difference between issued, accepted, and
delivered. Run a fresh-install deployment rehearsal with scheduler, workers,
mail, backups, and monitoring.

**Done when:** the journey works on a fresh deployment and the operator can
see and recover failed filing or delivery. Live Saudi readiness remains a
separate gate requiring a registered taxpayer pilot; live UAE readiness
requires a provider pilot.

## What to use from the ERP

| ERP capability | Decision for Masaar |
|---|---|
| Payment allocation and overpayment guards | Adapt the business rules as a reference for milestone 3. Implement a smaller Masaar-native model with UUID tenant IDs and no ledger dependency. |
| Invoice summary (paid, outstanding, overdue) and payment list patterns | Adapt the customer-facing questions and filters, using Masaar's portal and data model. |
| Invoice form and status presentation ideas | Compare during usability review; reuse only individual interaction ideas that improve Masaar's existing pages. |
| Quotations and sales orders | Defer until customers ask for a pre-invoice sales workflow. |
| General ledger, inventory, procurement, HR, manufacturing, CRM, ERP admin, and React apps | Exclude from the standalone release. They bring separate tenant/auth/invoice models and deployment needs. |
| ERP's Masaar HTTP client | Exclude. It currently targets the partner API with mismatched request and response shapes; Masaar's own portal does not need it. |

The ERP payment service uses invoices, customers, accounting journals, stock,
fraud jobs, and integer-style foreign IDs from that application. Copying its
service or migrations would couple Masaar to those models. The transferable
part is the behavior: bounded allocations, locking, correction, and balance
calculation.

## Scope guard

Masaar remains the source of truth for the invoice and its legal document.
Payment records describe settlement only. Country modules retain their own
filing and document rules. A new customer-facing feature should fit this
boundary before it is added to the release.
