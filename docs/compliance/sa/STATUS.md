# Saudi Arabia — what has been verified, and what is open

Audience: engineers and operators picking up the ZATCA work.

What has been established, by what evidence, and what has not. The rules are
in [RULES.md](RULES.md), the cryptography in
[HASHING-AND-SIGNING.md](HASHING-AND-SIGNING.md), the go-live checklist in
[PRODUCTION-READINESS.md](../../operations/PRODUCTION-READINESS.md).

## Verified against the authority (developer-portal sandbox)

| What | Evidence |
|---|---|
| A CSR is accepted and a compliance CSID issued | `fatoora:onboard --step=ccsid`: HTTP 200, `ISSUED` |
| All six compliance documents pass | `--step=compliance`: six `PASS` |
| A production CSID is issued | `--step=pcsid`: HTTP 200, `ISSUED` |
| Documents signed with the production CSID are accepted on both routes | `--step=submit`: three `CLEARED` with a stamped copy, three `REPORTED` |
| A resubmission is accepted, not refused | The same simplified document reported twice, byte for byte: both HTTP 200 `REPORTED`. Every retry in the platform rests on this |
| The live API does not verify `ds:SignatureValue`; block indentation is irrelevant when the digest describes it | ZATCA's own accepted sample, varied one thing at a time |
| The sandbox issues everyone the same certificate | Our PCSID's digest, issuer and serial equal the sample's |

`ProductionSubmissionTest` covers the platform's side of `--step=submit` with
the exchange faked: three documents each way, `Clearance-Status: 1` only on
clearance, a refusal ends the command non-zero.

### What a refusal looks like

The API does not answer with `BR-KSA-*` ids, and mixes spellings:

| Fault submitted | HTTP | Code | Category |
|---|---|---|---|
| `invoiceHash` not matching the document | 400 | `invoiceHash_QRCODE_INVALID` | `QRCODE_VALIDATION` |
| Simplified document sent for clearance | 400 | `XML-INVOICE-ERROR` | `INVOICETYPE_ERRORS` |
| Not an invoice | 400 | `XSD_ZATCA_INVALID`, `QRCODE_INVALID`, `invalid-certificate` | mixed |

These are document faults, never retried, and carried to the operator
verbatim with the first three messages: the first code is not always the
cause. Only a verdict like these changes an invoice; what a 401, 5xx or
timeout does is in [FILING-STATES.md](../../architecture/FILING-STATES.md).

## Verified against ZATCA's validator, on every push

`tests/Feature/Compliance/SaudiArabia/ZatcaConformanceTest.php` runs ZATCA's
SDK over generated documents: UBL 2.1 schema, EN 16931, ZATCA's schematron,
the certificate, the QR, the signed-properties digest and the PIH chain.
Documents are signed with the SDK's own pre-production certificate
([HASHING-AND-SIGNING.md](HASHING-AND-SIGNING.md#testing-without-a-taxpayer)).

- **One check is excluded**, by name in `businessRules()`: `signatureValue`,
  which fails on ZATCA's own shipped samples. `SignatureVerifiesTest`
  establishes the property instead, with OpenSSL, on the emitted document.
- `EmittedSignatureTest` and `EmittedDigestsTest` fail if a signed document is
  ever re-serialised with formatting.
- **CI:** the SDK is a licensed download, fetched in the `conformance` job of
  `.github/workflows/ci.yml` from a private release named by two repository
  secrets: the release's URL (a `/releases/latest` URL) and a read-only
  token. The job names them. The job regenerates `Configuration/config.json` from
  `defaults.json` and re-matches paths case-insensitively, because the archive
  ships `Schematrons` where its config says `schematrons`. An expired token
  fails the fetch rather than skipping.

## Verified in the platform (tests)

| Behaviour | Where |
|---|---|
| Compliance samples signed with the CCSID, simplified ones with their QR | `Onboarding/SampleStamp`, used by portal, API and `fatoora:onboard` |
| Per-organization environment; credentials tagged; switch refused with unfiled documents | `Rules/FatooraEnvironment`, `Onboarding/EnvironmentSwitch`, `Signing/CredentialStore` |
| Only a verdict accepts or rejects; acceptance is final | `Filing/InvoiceVerdict` |
| A 401 pauses the device; renewal resumes and files what waited | `Filing/FilingPause`, `Filing/FilingResume` |
| Notes only against an accepted original | `Rules/VatPeriodTracker` via `Issuing/IssuingStep` |
| An expired certificate cannot stamp | `IssuingStep::assertNotExpired()` |
| Legal names written as registered | `Support/TextNormalizer::legalName()` |
| Two tills of one branch keep a chain each | `TillChainTest`, `TillConformanceTest` |

## Open, reachable without a taxpayer

- **Scan a generated QR with ZATCA's mobile app** — neither the SDK nor the
  API says whether a consumer's scanner reads the TLV.
- **Volume** — the sandbox round trip is six documents; the k6 scripts in
  `tests/Load` have not been run against a deployment.
- **Branch commercial registrations** — see [ICV-PIH.md](ICV-PIH.md#branches-with-their-own-commercial-registration).

## Open, blocked on a registered taxpayer

The Fatoora portal issues an OTP only against TIN credentials, so these wait
for a VAT-registered pilot:

- simulation onboarding with real certificates, then production and the first
  cleared live invoice;
- a key and certificate that finally match (in the sandbox they cannot;
  `XadesSigner` refuses a mismatch outside it), making `ds:SignatureValue`
  verifiable — verify one with `SignatureVerifiesTest` before volume;
- re-checking that a resubmission is still idempotent;
- the CA chain (`ZATCA_CA_CHAIN_PATH`) and revocation list source
  (`ZATCA_CRL_URLS`, host allowed in `REVOCATION_ALLOWED_HOSTS`) for that
  environment.

The pilot moves to simulation by switching that organization's environment
(see [README.md](README.md#environments)); no separate deployment is needed.
Run `php artisan platform:simulation-preflight {organization}`, onboard through
`/api/compliance/onboarding/{ccsid,compliance-check,pcsid}` with the portal's
OTP, then file through the platform (pipeline and portal), and check
`fatoora:verify-hash-chain`, a scanned QR and an `invoice.cleared` webhook.
The step-by-step runbook is `docs/operations/runbooks/PILOT-SIMULATION.md`.

## Decisions, so they are not re-litigated

| Question | Answer |
|---|---|
| Clock for `IssueDate`, `IssueTime`, QR tag 3, `SigningTime` | The Kingdom's; `SigningTime` without `Z`, as ZATCA's signer writes it |
| Where a document discount reduces | The taxable amount (EN 16931, BR-CO-14) |
| Submission rate | 120/min per device, 300/min per organization by default (`fatoora.rate_limits.*`, `Filing/SubmissionRate`) — confirm ZATCA's published figure and stay under it |
| `QualifyingProperties/@Target` | `signature`, no `#`: ZATCA compares it literally |
| One encryption key for all tenants' credentials | Adequate for one taxpayer; a per-tenant key under a managed key service before a second |
