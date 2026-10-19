# UAE e-invoicing: the service-provider simulator

The simulator lets you run the whole UAE flow with no accredited service
provider. A provider normally gives sandbox credentials only after you sign up
with it. The simulator takes its place inside the application and does what
the platform relies on a provider for:

- it **checks every document** against the published PINT AE rules (the
  billing and self-billing schematrons) and the UBL 2.1 schema, with the same
  run as `PintConformanceTest`. A document that breaks a rule is refused, and
  the refusal lists the rule IDs, for example
  `[ibr-003]-An Invoice MUST have an Invoice issue date (ibt-002).`;
- it **accepts each document once**. It uses the document id as the
  idempotency key, the same key `HttpAspGateway` sends, so a retry gets the
  first message id back and nothing is filed twice;
- it **calls back** through the same signed routes a provider would use
  (`POST /api/peppol/webhooks/status` and `/inbound`). The calls go over HTTP,
  so the signature check, the body validation and the controllers are the
  production ones. It also answers `peppol:poll-status` lookups from its own
  record;
- it **delivers inbound documents**. When the buyer is one of this
  platform's organizations, the buyer receives the invoice as a received
  document, as the buyer's provider would deliver it.

The simulator never sends anything outside the server, and **production
refuses it**. With `APP_ENV=production` and `PEPPOL_GATEWAY=simulator` the
application refuses to boot, so the instance never serves and its readiness
check never passes. The gateway also refuses to build itself there. The admin
console's **Countries** page shows the service provider as
"Simulator, not for production". Every UAE invoice page has a test-mode
notice.

## Switching on demo mode (local or staging)

1. **The rules.** Unzip the PINT AE billing `resources.zip` under
   `billing/`, the self-billing one under `selfbilling/`, and UBL 2.1 under
   `ubl21/`, all in one directory. These are the same artefacts the
   conformance test uses (see [README.md](README.md#testing-without-a-provider)).
   Install Java 11 or later, and get a jar with Saxon in it (Saxon-HE 10 or
   later).
2. **The environment.**

   ```dotenv
   PEPPOL_AE_ENABLED=true
   PEPPOL_GATEWAY=simulator
   PEPPOL_ARTEFACTS_PATH=/path/to/pint-artefacts
   PEPPOL_XSLT_JAR=/path/to/saxon.jar
   # Seconds between one callback and the next. 0 plays them at once;
   # about 10 lets you watch the status change in the portal.
   PEPPOL_SIMULATOR_STEP_SECONDS=10
   # Where the simulator calls back. Empty means APP_URL, which must be
   # reachable from the queue worker.
   PEPPOL_SIMULATOR_CALLBACK_URL=
   # A real queue: callbacks are queued jobs, and with the sync queue a
   # callback would arrive before the platform has recorded the message.
   QUEUE_CONNECTION=database
   ```

   `PEPPOL_ASP_WEBHOOK_SECRET` is optional here. When it is empty, the
   simulator signs with a secret derived from `APP_KEY`, so a staging server
   reachable from outside still refuses callbacks that nobody here signed.
3. **A worker.** Run `php artisan queue:work --queue=peppol,default`. It sends
   the documents and plays the callbacks. To have lost callbacks recovered as
   well, run the scheduler (`php artisan schedule:work`), which runs
   `peppol:poll-status` every ten minutes.

## What to show

**Portal, sending.**

1. Register an organization with country United Arab Emirates. Under
   **Company**, give it its UAE details, with its endpoint, for example
   `0235:1001234567`.
2. Go to **New invoice**, fill in the UAE form, save the draft, and click
   **Issue**. The invoice is issued and its e-invoice is queued.
3. Open the invoice. The **UAE e-invoice** panel shows the status, and it
   has two lines, **Delivery to the buyer** and **Report to the authority**.
   They move one step every `PEPPOL_SIMULATOR_STEP_SECONDS`: delivery goes
   `Sending` -> `Sent` -> `Delivered`, and the report goes
   `Awaiting the authority` -> `Reported to the authority`. The message ID,
   the buyer's provider response (`AP`) and the authority reference appear
   when they arrive. Reload the page to see each step.

To see a failure, use one of the buyer endpoints in the table below.

**Portal, receiving.**

- Register a second organization and give it the endpoint you send to, for
  example `0235:1007654321`. Invoices the first organization sends to that
  endpoint arrive under **Received** in the second organization's portal.
- Or deliver a document from a supplier outside the platform:

  ```bash
  php artisan peppol:simulate-inbound 0235:1007654321
  # or a file of your own:
  php artisan peppol:simulate-inbound 0235:1007654321 --file=invoice.xml
  ```

  With no file, the command uses the specification's "Standard tax invoice"
  example. It readdresses the buyer endpoint and changes nothing else.

**Admin console.**

- **Peppol** (`/admin/peppol`) shows the gateway, the number of documents in
  each state across every organization, the documents that are waiting or
  stopped, and **Resend** for a failed document.
- **Countries** (`/admin/countries`) shows the UAE's readiness, which names
  the simulator.

## Outcomes

The buyer endpoint decides what the simulator plays. Any endpoint not listed
is delivered. The list is `peppol.simulator.outcomes` in `config/peppol.php`.
The final status has two parts, because delivery and the report to the
authority are confirmed separately: delivery (`status`), then the report
(`reporting_status`).

| Buyer endpoint | Outcome | Callbacks, in order | Final status |
|---|---|---|---|
| any other | Delivered | sent, delivered (`AP`), reported, and inbound if the buyer is on this platform | `delivered`, `reported` |
| `0235:1999999901` | The buyer is not a participant on the network | rejected, with the reason | `rejected`, none |
| `0235:1999999902` | No answer to the first attempt (a simulated `503`) | none for the first attempt; after the retry, as delivered | `delivered`, `reported` |
| `0235:1999999903` | The first attempt is accepted but its answer is lost | the retry gets the same message id; one set of callbacks, as delivered | `delivered`, `reported` |
| `0235:1999999904` | The buyer's access point refuses it. The supplier's provider still reports it (Guidelines p10, steps 4 and 7) | sent, rejected (`RE`, reason `BV`), reported | `rejected`, `reported` |
| `0235:1999999905` | The report to the authority fails until it is asked again | sent, delivered, report_failed; after `peppol:retry-reports`, reported | `delivered`, `reported` |
| `0235:1999999906` | The authority's confirmation arrives before the delivery | sent, reported, delivered | `delivered`, `reported` |
| `0235:9900000097`, `0235:9900000098`, `0235:9900000099` | Reported to the authority, never exchanged: a deemed supply, a buyer not yet on e-invoicing, an export (UAE Electronic Invoicing Guidelines v1.1, p26, p23, p28) | sent, reported | `sent`, `reported` |

A document that breaks a rule is refused at submission, whatever its
endpoint. It becomes `rejected`, and its errors are the rule texts.

**Retries.** A first attempt that gets no answer leaves the document `ready`.
The send job tries again after 1, 5, 15 and 60 minutes. In a demo, run the
worker and wait a minute.

**Callbacks the platform does not take.** A status callback answered `404`
(the platform has not recorded the message yet), `429`, a `5xx`, or not
answered at all is retried after 5, 15, 60, 300 and 900 seconds. An inbound
callback answered `404` means no organization here holds that endpoint, and
nothing more happens. A callback answered `401` or `422` is a configuration
fault: the callback URL points at another application, or the secret differs.
It is logged and not retried.

## What it does not do

The simulator stands in for the provider's API, not for the Peppol network:

- No AS4 transport, no SMP or directory lookup, and no access-point
  certificate. "Unknown receiver" is decided by the endpoint table above.
- Nothing is reported to the Federal Tax Authority. A `reported` status only
  means the simulator played that step.
- It uses the platform's generic API shapes: `WebhookPayload` and
  `WebhookSignature`, and one status field. A real provider's shapes and
  statuses will differ. [PROVIDER-ONBOARDING.md](PROVIDER-ONBOARDING.md)
  lists what to check.
- It keeps its records in the cache. Clearing the cache makes it forget its
  messages, and later lookups then have no answer.

## Tests

- `tests/Feature/Compliance/Networks/Peppol/SimulatorTest.php` plays every
  outcome, idempotency, early callbacks, the status poll, the production
  refusal and the inbound command. It runs everywhere: the rules' verdict is
  scripted, so it needs no Java.
- `tests/Feature/Compliance/UnitedArabEmirates/SimulatorConformanceTest.php`
  runs end to end through the simulator with the real rules: a tax invoice,
  a credit note, self-billing, an export, discounts and charges, failure and
  retry, a duplicate send, an inbound document, and the portal. Every
  outbound document must pass PINT AE inside the simulator. It runs locally
  when `PEPPOL_ARTEFACTS_PATH` and `PEPPOL_XSLT_JAR` are set, and in CI's
  `PINT AE conformance` job, which fails if it is skipped.
