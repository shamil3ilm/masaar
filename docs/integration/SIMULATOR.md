# UAE service-provider simulator

Audience: developers and testers running the whole UAE flow without an
accredited service provider, locally or on a staging server.

The simulator takes the provider's place inside the application:

- it **checks every document** against the PINT AE billing and self-billing
  schematrons and the UBL 2.1 schema, and refuses one that breaks a rule,
  naming the rules (`[ibr-003]-An Invoice MUST have an Invoice issue date (ibt-002).`);
- it **accepts each document once**, keyed on the document id as a real
  provider would be, so a retry gets the first message id back;
- it **calls back** over HTTP through the same signed routes a provider uses,
  and answers `peppol:poll-status` lookups;
- it **delivers inbound**: a buyer that is one of the platform's
  organizations receives the invoice as a received document.

It never sends anything off the server, and **production refuses it**: with
`APP_ENV=production` and `PEPPOL_GATEWAY=simulator` the application does not
boot. Every UAE invoice page shows a test-mode notice.

## Switching it on

1. Unzip the PINT AE billing `resources.zip` under `billing/`, the
   self-billing one under `selfbilling/`, and UBL 2.1 under `ubl21/`, in one
   directory. Install Java 11+ and a jar with Saxon-HE 10+.
2. Set:

   ```dotenv
   PEPPOL_AE_ENABLED=true
   PEPPOL_GATEWAY=simulator
   PEPPOL_ARTEFACTS_PATH=/path/to/pint-artefacts
   PEPPOL_XSLT_JAR=/path/to/saxon.jar
   PEPPOL_SIMULATOR_STEP_SECONDS=10     # 0 plays callbacks at once
   PEPPOL_SIMULATOR_CALLBACK_URL=       # empty: APP_URL, reachable from the worker
   QUEUE_CONNECTION=database            # callbacks are queued jobs
   ```

   With `PEPPOL_ASP_WEBHOOK_SECRET` empty, callbacks are signed with a secret
   derived from `APP_KEY`.
3. Run `php artisan queue:work --queue=peppol,default`, and
   `php artisan schedule:work` to recover lost callbacks.

Then register a UAE organization with an endpoint (for example
`0235:1001234567`), issue an invoice, and watch its **UAE e-invoice** panel
move `Sending` → `Sent` → `Delivered` and `Awaiting the authority` →
`Reported`. A second organization holding the buyer's endpoint receives it;
`php artisan peppol:simulate-inbound 0235:1007654321 [--file=invoice.xml]`
delivers a document from outside.

## Outcomes by buyer endpoint

Any endpoint not listed is delivered (`peppol.simulator.outcomes` in
`config/peppol.php`).

| Buyer endpoint | Plays | Ends |
|---|---|---|
| any other | sent, delivered (`AP`), reported | `delivered`, `reported` |
| `0235:1999999901` | Not a network participant: the lookup stops the issue | draft |
| `0235:1999999902` | First attempt unanswered (`503`), then delivered | `delivered`, `reported` |
| `0235:1999999903` | First answer lost; the retry gets the same message id | `delivered`, `reported` |
| `0235:1999999904` | The buyer's access point refuses (`RE`); still reported | `rejected`, `reported` |
| `0235:1999999905` | Report fails until `peppol:retry-reports` | `delivered`, `reported` |
| `0235:1999999906` | Report confirmed before delivery | `delivered`, `reported` |
| `0235:1999999907` | An outage: four unanswered attempts, then all waiting documents sent | `delivered`, `reported` |
| `0235:9900000097`, `…98`, `…99` | Reported, never exchanged | `not_exchanged`, `reported` |

A document that breaks a rule is `rejected` whatever its endpoint. A first
attempt without an answer is retried after 1, 5, 15 and 60 minutes.

## What it is not

No AS4 transport, SMP lookup or access-point certificate; nothing reaches the
Federal Tax Authority; the generic callback shapes stand in for a real
provider's (see the provider onboarding checklist in the UAE compliance
documentation); its records live in the cache.

Tests: `tests/Feature/Compliance/Networks/Peppol/SimulatorTest.php` (every
outcome, no Java needed) and
`tests/Feature/Compliance/UnitedArabEmirates/SimulatorConformanceTest.php`
(end to end with the real rules, when `PEPPOL_ARTEFACTS_PATH` and
`PEPPOL_XSLT_JAR` are set; required in CI).
