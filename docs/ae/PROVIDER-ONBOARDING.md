<!--
  Provider readiness checklist for connecting a UAE accredited service
  provider. Names no provider and carries no dates: authority timelines are
  on the Ministry of Finance's e-invoicing pages.
-->

# UAE e-invoicing: connecting an accredited service provider

This page is the checklist for connecting a real accredited service provider
(ASP) in place of the [simulator](../integration/SIMULATOR.md). It covers:

- what the platform needs from a provider;
- the questions to ask a provider before choosing one;
- the steps to switch over;
- the gaps between the platform's gateway contract and what the UAE
  documents expect a provider to do.

The sources are the Ministry of Finance documents listed under
[Sources](#sources), cited by page.

## How the platform talks to a provider

The platform is Corner 1. It builds the PINT AE document and hands it to its
provider (Corner 2). The provider validates it, carries it to the buyer's
provider (Corner 3), reports the tax data to the Federal Tax Authority
(Corner 5) in parallel, and forwards the confirmations back (Guidelines p10-11).

Everything provider-specific sits behind one contract, `AspGateway`, in
`app/Domains/Compliance/Networks/Peppol/Contracts/`:

| Method | What the platform expects |
|---|---|
| `submit(document)` | Hand over the XML, with the document id as an idempotency key. Returns the provider's message id. Throws "no verdict" (retried) or "refused" (not retried, with the errors) |
| `status(messageId)` | What became of a message, for when a callback was missed |
| `verifyWebhook(request)` | Whether a callback really comes from the provider, and recently |
| `parseStatusWebhook(request)` | A status callback: `sent`, `delivered`, `rejected` or `reported`, or a message level response code `AB`, `AP` or `RE` |
| `parseInboundWebhook(request)` | A document another supplier sent to one of our organizations: message id, XML, sender and receiver endpoints |

The generic implementation, `HttpAspGateway`, speaks a common REST shape. For
a chosen provider you adapt three classes, `HttpAspGateway`,
`WebhookSignature` and `WebhookPayload` (in `.../Peppol/Gateways/`), and
nothing else. The simulator implements the same contract, so a flow that
works through the simulator only needs those three classes to match the
provider.

## What the platform needs from a provider

### Endpoints

- [ ] **Submit a document.** Takes UBL 2.1 XML in PINT AE form, as the
      platform builds it (billing `urn:peppol:pint:billing-1@ae-1` and
      self-billing `urn:peppol:pint:selfbilling-1@ae-1`, invoice and credit
      note). It returns a message id synchronously.
- [ ] **Look up a status by message id.** The platform polls every ten
      minutes for sent documents that have had no callback for 30 minutes,
      for up to 14 days.
- [ ] **Look up by our own reference** (the document id or idempotency key).
      This is needed when a worker died during a submission and no message
      id came back. See gap 5.
- [ ] **Separate sandbox and production base URLs**, with separate
      credentials.

### Authentication

- [ ] A machine-to-machine credential the platform can hold as a secret. The
      generic gateway expects OAuth2 client credentials (`/oauth/token`), and
      caches the token until a minute before it expires.
- [ ] Credentials scoped per connection. If the platform serves several
      taxpayers through one provider account, find out how the provider
      separates them. See gap 3.

### Idempotency

- [ ] The provider honours an idempotency key. A retry after a lost answer
      must return the first message id, not file the invoice twice. The
      platform sends `Idempotency-Key: <document id>` and treats `409` as
      "already have it", not as a refusal.
- [ ] Otherwise, the provider refuses a second document with the same seller
      and invoice number, and says so in a way the platform can tell apart.

### Webhooks (callbacks)

- [ ] The provider calls back for every status change and for every inbound
      document, without the platform asking.
- [ ] **Signed.** The generic shape is HMAC-SHA256 over
      `"{timestamp}.{raw body}"`, in `X-Signature` (optionally with a
      `sha256=` prefix), and the timestamp in `X-Signature-Timestamp`. A
      callback more than 300 seconds old is refused. Record the provider's
      scheme. If it differs, only `WebhookSignature` changes.
- [ ] The provider retries a callback the platform did not take: `404`
      (message not yet known), `5xx`, or a timeout. Both callback routes are
      idempotent.
- [ ] The provider can register two URLs:
      `POST {APP_URL}/api/peppol/webhooks/status` and
      `POST {APP_URL}/api/peppol/webhooks/inbound`.
- [ ] Ideally, two secrets can be valid at once, so a secret can be rotated
      without losing callbacks.

### Statuses

Map each provider status to the platform's statuses on its two tracks (delivery, and the report to the authority), and record the
mapping in `WebhookPayload::statusFrom`:

| Platform | Meaning | Source |
|---|---|---|
| `sent` | The provider accepted it for transport | Corner 2 |
| `delivered` | The buyer's provider validated it: message level response `AB` or `AP` | Corner 3 confirmation (Guidelines p10, step 5) |
| `rejected` | The provider refused it, or the buyer's provider did (`RE`), with the reasons | Corner 2 or 3 |
| `reported` | The authority confirmed the tax data, with its reference where relayed. A reporting status | Corner 5 confirmation, forwarded (Guidelines p10, step 8; p11, step 10) |
| `report_failed` | The tax data did not reach the authority, with the reasons. A reporting status | The provider (Guidelines p41, item vi) |

- [ ] Errors carry the rule ID (for example `ibr-003`), a severity and a
      location, not only text. The platform reads either form (gap 7).
- [ ] A failed report to the authority is its own status, with its reasons,
      and the provider can be asked to report a document again. The platform
      tracks the report apart from delivery (gap 1) and calls `retryReport`
      hourly on a failure.
- [ ] Find out what the provider sends for the reserved buyer endpoints
      (`0235:9900000097` deemed supply, `0235:9900000098` buyer not yet on
      e-invoicing, `0235:9900000099` export). These documents are reported
      and never exchanged (Guidelines p23, p26, p28). The platform treats
      them as `not_exchanged`, done once reported (gap 2).

### Inbound

- [ ] The provider delivers each document addressed to one of our
      organizations' participant identifiers, with its message id, the XML
      exactly as it travelled, and the sender and receiver endpoints
      (`0235:` plus the 10-digit TIN, with or without the
      `iso6523-actorid-upis::` prefix).
- [ ] The provider forwards Corner 5's confirmation for invoices our
      organizations receive (Guidelines p11, step 11). The platform does not
      record it yet. See gap 6.

### Test environment

- [ ] A sandbox that covers the six tests the Guidelines ask a taxpayer to
      run with its provider (Guidelines p40-41): sending invoice data to the
      provider, delivery to the buyer, the confirmation of exchange,
      receiving an invoice, reporting to the authority, and the confirmation
      of reporting.
- [ ] Test participant identifiers on both sides: one we send from, and one
      that sends to us. The ability to trigger a refusal by the buyer's
      provider and an unknown receiver.
- [ ] Find out whether the sandbox reports to the authority, or simulates
      Corner 5. The programme presentation puts Corner 5 in a pilot phase
      (p16), and its voluntary exchange has no reporting (p15, row 1), so
      `reported` may not arrive for everyone.

## Questions to ask a provider

1. Do you send the buyer's provider's confirmation and the authority's
   confirmation as separate events? Does each have its own time, the
   authority's reference, and a reason when it fails? (Guidelines p10-11,
   p41, p48)
2. For the reserved endpoints `9900000097`, `9900000098` and `9900000099`,
   which statuses do we get, and which one is final? (Guidelines p23, p26,
   p28)
3. Do your validation errors carry the PINT AE rule ID, a severity and a
   location? Do you offer a validate-only call, so a draft can be checked
   before it is issued? (Guidelines p10, step 2)
4. Can we look up a buyer's participant identifier before issuing?
   (Guidelines p11, row 6; programme presentation p7)
5. The Guidelines make generating the invoice UUID the provider's job
   (Guidelines p11, row 7). Do you keep ours (`cbc:UUID` is the invoice's
   id), or replace it? If you replace it, or convert the document, do you
   return what travelled?
6. Do you honour an idempotency key? Can we look up a message by our own
   reference? (Guidelines p46, on exchanging pending invoices once service
   resumes)
7. How do you tell us about an outage, and about your notice to the
   authority? Do you send pending invoices automatically when service
   resumes? (Guidelines p45-46)
8. Do you support self-billing (`selfbilling-1@ae-1`) and credit notes for
   both billing and self-billing? (Guidelines p23)
9. For invoices our organizations receive, do you forward the authority's
   confirmation? (Guidelines p11, step 11)
10. Does your sandbox cover all six tests on Guidelines p40-41, including
    reporting during the Corner 5 pilot? (programme presentation p16)
11. Can one platform account serve many taxpayers, each with its own
    credentials and callbacks? A taxpayer appoints one provider for sending
    and receiving (Guidelines p14), and members of a tax group may use
    different ones (Guidelines p38).
12. How is the participant identifier issued? How do we learn about
    reverification or offboarding through EmaraTax? (Guidelines p6, p41, p46)
13. How long do you keep transactional logs? Where is our data hosted? Can
    you keep invoices for us? Retention is the taxpayer's duty, for five
    years or more (Guidelines p12; Considerations p6-7).
14. What uptime and support response times do you commit to, and which
    certifications do you hold? (Considerations p7)
15. Is the product your own or a third party's, and who provides support?
    (Considerations p6)

## Switching from the simulator to a provider

1. **Contract and sandbox.** Sign up with the provider and get sandbox
   credentials, a sandbox base URL, a webhook secret and test participant
   identifiers.
2. **Adapt the gateway.** Check the provider's API reference against
   `HttpAspGateway` (token, submit, status), `WebhookSignature` and
   `WebhookPayload`, and change only those three classes. Add the
   provider's real request and response bodies to
   `tests/Feature/Compliance/Networks/Peppol/AspHttpGatewayTest.php`, so
   the mapping is pinned.
3. **Configure the sandbox deployment.**

   ```dotenv
   PEPPOL_GATEWAY=
   PEPPOL_ASP_BASE_URL=<sandbox base URL>
   PEPPOL_ASP_CLIENT_ID=<sandbox client id>
   PEPPOL_ASP_CLIENT_SECRET=<sandbox client secret>
   PEPPOL_ASP_WEBHOOK_SECRET=<sandbox webhook secret>
   ```

   An empty `PEPPOL_GATEWAY` selects the provider at `PEPPOL_ASP_BASE_URL`.
   Register the two callback URLs with the provider.
4. **Onboard one organization.** Enter the participant identifier the
   provider issued as the organization's endpoint
   (`PUT /api/compliance/ae/profile`, or the portal's company page).
5. **Run the simulator's scenarios against the sandbox,** by hand, from the
   portal or the API:
   - a tax invoice, a credit note, a self-billed invoice, a commercial
     invoice, an export, and discounts and charges, each ending
     `delivered`, then `reported`;
   - a document the provider refuses, with readable errors;
   - an unknown receiver, and a refusal by the buyer's provider;
   - a send with no answer, followed by its retry: one message at the
     provider;
   - an invoice received from the provider's test sender.

   Check the admin console's **Peppol** page after each one, and let
   `peppol:poll-status` run once with callbacks blocked.
6. **Production.** Set the production base URL and credentials. Production
   refuses to boot while any of the credentials or the webhook secret is
   missing, and while `PEPPOL_GATEWAY=simulator`.
7. **Leave the simulator for local and staging.** It stays useful for demos
   and for CI's PINT AE job.

## One provider or one per taxpayer

A person within the scope of e-invoicing must appoint one accredited
service provider, for both sending and receiving (Guidelines p14). It
contracts with that provider and onboards through EmaraTax, which redirects
it to the provider's portal (Guidelines p38-39). Members of a tax group may
each appoint a different provider (Guidelines p38). So the provider is the
taxpayer's choice, not the platform's. There are two ways to fit that.

| | One platform provider | One provider per taxpayer |
|---|---|---|
| What it means | The platform contracts with one provider, and every customer appoints that provider (or the platform as its partner or reseller) | Each organization connects the provider it appointed: its own credentials, callback secret and sandbox |
| Fits the Guidelines when | every customer is willing to appoint the platform's provider | always |
| Build | done: one set of `PEPPOL_ASP_*` settings | a gateway per organization, credentials stored per organization (encrypted), callback routes that say which connection a callback is for, and one adapter per provider API |
| Run | one contract, one sandbox, one support line, one set of status mappings to keep right | as many as customers' providers: each API, outage and change is ours to follow |
| Onboarding a customer | the partner provider's onboarding, which the platform can guide or do as a reseller | the customer's own provider, whose onboarding the platform cannot see |
| Customer who already has a provider | cannot use the platform without switching provider, or the platform must support its provider | can |
| Cost and pricing | the platform negotiates one price and can bundle it | the customer pays its provider. Pricing differs per customer |
| Moving a taxpayer between providers | a re-appointment through EmaraTax, then a new endpoint registration; the platform's records stay | the same, plus reconnecting credentials on the platform |

**Trade-off.** One platform provider is simpler and cheaper to run, and
predictable to support. It costs customers the freedom to keep a provider
they already appointed, and it rests the platform on one partner. One
provider per taxpayer fits every customer, at the price of an integration
and a support line per provider, and of storing each customer's
credentials.

**A middle way.** Start with one partner provider, and build the seams so
that an organization can later name another configured provider: resolve
the gateway per organization, and keep each organization's appointment as
a record. Then adding a second provider is configuration and one adapter,
not a redesign.

### The decision

The owner chose the middle way: **one platform partner provider now, built
so that each organization can have its own later.** What that means in the
platform:

- **Resolved per organization.** Every organization's documents go through
  the provider `AspGatewayResolver` resolves for it. That is the platform's
  partner (`peppol.providers.platform`: the provider at `PEPPOL_ASP_*`, or
  the simulator) unless the organization's customization
  `einvoicing.service_provider` names another configured provider by its
  purpose key. Only the partner is configured. A second provider needs:
  - its entry under `peppol.providers`, with its base URL, credentials and
    webhook secret;
  - an adapter, if its API differs from the generic shape;
  - callback routes that say which provider a callback comes from.
- **An appointment record per UAE organization** (`provider_appointments`,
  a compliance record that is never deleted). It holds:
  - the provider, by purpose key;
  - the status (`pending`, `appointed`, `ended`);
  - when it was appointed;
  - the EmaraTax or provider reference;
  - the organization's own last date, if its revenue band sets one;
  - who recorded and changed it.

  Recording an appointment also points the organization's documents at that
  provider. A change of provider ends the old record and starts a new one.
- **Readiness.** "Appoint your e-invoicing service provider" is a setup
  step on the organization's dashboard (done once it is recorded as
  appointed) and on the admin console's organization page.
- **Reminders.** Organizations with no provider appointed are emailed 60,
  30, 14, 7 and 1 days before their last date. The date comes from
  `PEPPOL_AE_APPOINTMENT_DEADLINE`, or from the organization's own date. It
  is never hard-coded, because the date depends on the revenue band
  (Guidelines p19). With no date set, nothing is sent. The obligation is to
  appoint one provider for sending and receiving (Guidelines p14), through
  EmaraTax (Guidelines p38-39).

### Questions for a partner provider

1. **Reseller model.** Can the platform resell your service, so that its
   customers contract with the platform rather than with you? Or must each
   taxpayer contract with you directly? Who holds the taxpayer relationship
   for support and for the authority?
2. **Appointment through a partner.** When a taxpayer appoints you through
   EmaraTax, can the platform start or complete the onboarding on its
   behalf? What does the taxpayer do in EmaraTax and in your portal, and
   what reference confirms the appointment (Guidelines p38-39)?
3. **Pricing.** Per document, per organization, or a platform volume tier?
   Are inbound documents and reports to the authority priced apart? Is any
   free allowance written into the contract? What does the sandbox cost?
   (Considerations p7 asks about the pricing model.)
4. **Sandbox.** One sandbox for the platform, with test participant
   identifiers on both sides, covering the six tests on Guidelines p40-41,
   including reporting during the Corner 5 pilot?
5. **Moving a taxpayer.** When a taxpayer moves to or from you, how is the
   re-appointment done in EmaraTax, what happens to its participant
   identifier and to documents in flight, and what records and logs do you
   hand over (Guidelines p41: reverification and offboarding)?
6. **Many taxpayers, one connection.** Do you take documents for many
   taxpayers over one platform connection, identified by participant
   identifier? Or does each taxpayer need its own credentials and callback
   secret?

## Gaps in the contract

These are the gaps between `AspGateway` as it stands and what the UAE
documents expect. Most can only be closed once a provider's API is known.
The order is by how much each matters at go-live.

**Blockers.**

1. **Exchange and reporting are tracked apart.** *Closed.* The provider
   reports to the authority in parallel with delivery (Guidelines p10,
   step 4). The taxpayer must receive a confirmation of success or failure
   for each (Guidelines p41; p45; p48). A document now has a delivery
   status and a reporting status (`pending`, `reported`, `report_failed`),
   and `AspStatus` names which one a callback is about. An early report
   confirmation no longer hides a later delivery. A failed report has its
   own status, an `e_invoice.report_failed` webhook and an error log, and is
   asked again every hour (`peppol:retry-reports`, contract method
   `retryReport`). The authority's reference is kept. **Ask the provider**
   (question 1) how it names these and whether it offers a re-report call.
2. **Report-only documents.** *Closed.* Deemed supplies, buyers not yet on
   e-invoicing, and exports with no participant identifier are reported and
   never exchanged (Guidelines p23, p26, p28). A document to one of the
   reserved endpoints (`peppol.countries.AE.report_only_endpoints`, kept
   equal to the ones the PINT AE rules name) is now `not_exchanged` once the
   provider takes it. It waits only for its report, and the status poll
   stops asking once it is `reported`. **Ask the provider** (question 2)
   which statuses it sends for them.
3. **One provider for the platform, or one per taxpayer.** *An owner
   decision; see [One provider or one per taxpayer](#one-provider-or-one-per-taxpayer).*
   Each taxpayer appoints its own provider (Guidelines p14, p38).
   Configuration holds one provider for the whole platform. That is enough
   only while every customer appoints the provider the platform works with.

4. **The record of transmission is kept.** *Closed.* Data on the issuance,
   transmission and receipt of each invoice must be kept for five years or
   more (Guidelines p12), and the taxpayer stays responsible for it while
   the provider keeps only its own logs (Guidelines p47). Every move,
   callback and status lookup now goes on an append-only record
   (`peppol_document_events`). Each entry has its source, its status on
   either track, the response code, the provider's message id, the errors,
   the SHA-256 of the body as it arrived, and the time. Entries are never
   changed or deleted. The document's status is derived from them, and
   documents from before the record have their state as one entry.

**Important.**

5. **A document stuck in `sending` is recovered.** *Closed.* A worker that
   dies mid-call used to leave no message id, and so nothing to ask about.
   `AspGateway::findByReference` now asks the provider what it holds under
   the document's own id, which is sent as the idempotency key.
   `peppol:recover-sending` runs every ten minutes. It records a document
   the provider took as sent, and sends again a document the provider never
   received. Pending invoices go out once service resumes (Guidelines p46).
   **Ask the provider** (question 6) whether it can look up by our
   reference.
6. **Confirmations for received invoices.** *Closed.* The buyer's provider
   reports a received invoice to the authority and forwards the
   confirmation to the buyer (Guidelines p10 step 7; p11 steps 9 and 11). A
   reporting callback for a received document's message id now records its
   reporting status and the authority's reference, announces
   `e_invoice.reported`, and shows on the portal's received invoice page.
   **Ask the provider** (question 9) whether it forwards these.
7. **Structured validation errors.** *Closed.* Every error is now
   `{code, severity, field, message}` (`AspError`): the rule ID, `fatal`,
   `error` or `warning`, where in the document, and the words. This applies
   to refusals, callbacks, lookups, the API and the portal. A provider's
   errors are read whether it sends objects (`code`/`rule_id`, `severity`/
   `flag`, `field`/`location`/`path`, `message`) or strings that lead with
   their code (`[ibr-003]-...`, `BV: ...`). This supports the governance
   model for resolving errors that the Guidelines ask for (p41). **Ask the
   provider** (question 3) which names it uses.
8. **Outages.** *Closed for what the platform can see.* The provider must
   notify the taxpayer and the authority of a disruption, and exchange and
   report pending invoices once service resumes (Guidelines p45-46).
   - Retries are now measured in time: `PEPPOL_RETRY_WINDOW_HOURS`, 72 by
     default, from when a document was first queued. Before, five quick
     refusals failed a document.
   - Three documents in a row with no verdict are an outage. No document is
     failed while it lasts. It is shown on the admin console and on the
     invoice page, and logged as critical.
   - When the provider answers again, every waiting document is sent at
     once.

   **Ask the provider** (question 7) whether it also sends a service
   notice. If it does, it can declare and end an outage directly.
9. **Participant lookup before issuing.** *Closed.* The supplier gathers
   the buyer's identifier, and the provider looks it up before
   transmitting (Guidelines p11 rows 5-6; programme presentation p7).
   `AspGateway::participantExists` now asks the provider, and the UAE
   issuing step refuses an invoice whose recipient is not registered, with
   the reason and the reserved endpoint for a buyer not yet on e-invoicing
   (p23). The answer is cached for a day when registered and ten minutes
   when not. An unknown answer (no provider, or no reply) stops nothing.
   **Ask the provider** (question 4) for its lookup call.
10. **The UUID.** *Closed.* The Guidelines say the UUID that makes each
    e-invoice unique is generated for it (p11, row 7). The platform used the
    invoice's database id. It now generates a UUID for each document
    (`document_uuid`, written as `cbc:UUID`) and sends it with the document.
    `submit` returns a `SubmitReceipt`: the message id, plus the provider's
    own document id where it keeps one, which is stored as
    `provider_reference`. A received document's UUID is read from its XML.
    **Ask the provider** (question 5) whether it keeps our UUID or
    substitutes its own. If it substitutes, it should return the final
    document.

**Nice to have.**

- Business-level responses to a received invoice (accept, query, reject,
  paid). The UAE documents do not mention them.
- Secret rotation with two valid secrets, or mutual TLS or an IP allowlist
  on the callback routes.
- Keeping the authority's tax data document reference, if the provider
  returns one (programme presentation p15; Guidelines p48).

## Sources

- **Guidelines**: UAE Electronic Invoicing Guidelines, version 1.1
  (Ministry of Finance).
- **Considerations**: Considerations for selecting an Accredited Service
  Provider, version 1.0 (Ministry of Finance).
- **Programme presentation**: UAE eInvoicing Programme (Ministry of Finance
  and Federal Tax Authority).
- Peppol PINT AE specification: https://docs.peppol.eu/poac/ae/pint-ae/
- Ministry of Finance e-invoicing pages: https://mof.gov.ae/einvoicing/
