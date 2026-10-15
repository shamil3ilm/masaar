# Saudi Arabia (ZATCA Phase 2) — where the implementation stands

**Written for whoever picks up the ZATCA work next.** It says what has been
established, by what evidence, and what has not — so that nothing here has to
be re-derived and nothing is assumed to work because it looks finished.

Reference material is elsewhere and not repeated: [README.md](README.md) for
what the authority requires, [HASHING-AND-SIGNING.md](HASHING-AND-SIGNING.md)
for every digest and how each was pinned down,
[COMPLIANCE-RULES.md](COMPLIANCE-RULES.md) for the BR-KSA rules, and
[../PRODUCTION-READINESS.md](../PRODUCTION-READINESS.md) for the go-live
checklist.

---

## 1. Established against the authority itself

These are not test doubles. Each was observed against ZATCA's own developer
portal at `gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal`.

| What | Evidence |
|---|---|
| A certificate request is accepted and a **compliance CSID** issued | `fatoora:onboard --step=ccsid`, HTTP 200, disposition `ISSUED` |
| **All six compliance documents** are accepted — standard and simplified invoice, credit note and debit note | `--step=compliance`, six `PASS` |
| A **production CSID** is issued | `--step=pcsid`, HTTP 200, disposition `ISSUED` |

Completed both in CI and locally. The sandbox needs no taxpayer: the developer
portal accepts the fixed OTP `123345`, which is the one value in the flow that
could not be confirmed any other way.

What this settles beyond onboarding: the authority accepts documents this
platform generates and signs, chained by ICV and PIH, over the real protocol.

---

## 2. Established against ZATCA's own validator, offline

The authority publishes a Java SDK carrying its validator and a certificate
issued by its own pre-production CA. `ZatcaConformanceTest` runs it over
documents generated here — 26 tests, covering the UBL 2.1 schema, the CEN
EN 16931 rules, ZATCA's Schematron, the certificate, the QR code, the
signed-properties digest and the PIH chain.

Signing with the authority's certificate rather than a self-signed one is what
makes the certificate and QR checks meaningful; `SigningCredentials::authorityCredentials()`
loads it when `ZATCA_SDK_PATH` is set.

**This runs in CI on every push.** The SDK is a licensed download that cannot
be committed, so it is hosted as a release asset in a separate private
repository, and two secrets on this repository point at it:

| Secret | Value |
|---|---|
| `ZATCA_SDK_URL` | `https://api.github.com/repos/<owner>/<repo>/releases/latest` |
| `ZATCA_SDK_TOKEN` | A fine-grained token with **Contents: Read-only** on that repository, and nothing else |

The URL names the *release*, not the asset: a private repository's asset id
appears nowhere in the GitHub web UI, so the `conformance` job resolves the
release to whichever asset is named for the SDK. Upgrading is therefore
publishing a newer release - not a draft and not a pre-release, because
`/releases/latest` ignores both - with no secret to change.

Two things about the archive the job handles rather than assumes. `install.sh`
writes the absolute paths of the machine it ran on into
`Configuration/config.json`, so it is regenerated from `defaults.json`. And the
SDK's own `defaults.json` names `Data/Rules/schematrons` while the archive
ships `Data/Rules/Schematrons` - invisible on a Windows or macOS checkout,
fatal on the Ubuntu runner, and the SDK reports an unreadable rule set not as a
configuration problem but as every document being invalid. Every path in the
rewritten config is checked for existence and re-matched case-insensitively if
it is missing.

When the token expires the job fails at the fetch step rather than skipping,
which is the right way round.

**One check is excluded, and not because this platform fails it.** The SDK's
`signatureValue` check fails on ZATCA's own shipped samples:
`Data/Samples/Simplified/Invoice/Simplified_Invoice.xml` passes XSD, EN, KSA
and PIH and then reports `signatureValue: wrong signature Value`, because
those files were pretty-printed after they were signed. A check that rejects
the authority's reference documents cannot say anything about ours, so
`SignatureVerifiesTest` establishes the property directly instead: the
signature verifies with OpenSSL over the canonicalised `ds:SignedInfo` using
the certificate the document carries, on the document as finally emitted —
after the QR is injected and the whole thing re-serialised, which is the step
that would silently invalidate a signature that was right when it was made.

It is named individually in `businessRules()` rather than filtered by prefix,
so a second cannot join it unnoticed.

---

## 3. Established against the authority: all six documents accepted

`fatoora:onboard --step=submit` clears and reports the six documents with the
**production** certificate, against `/invoices/clearance/single` and
`/invoices/reporting/single`. Standard documents are cleared, because B2B must
be cleared before it is issued; simplified are reported, where B2C goes within
twenty-four hours.

**All six are accepted** - three CLEARED with a stamped copy
returned, three REPORTED. The authority accepts documents generated and signed
here, over the real protocol, on both routes.

`ProductionSubmissionTest` covers the part that is ours - that three documents
go each way, that `Clearance-Status: 1` rides only on clearance, that a refusal
ends the command non-zero - with the exchange faked.

### Why a signed document must never be re-serialised with formatting

Worth reading before touching the signer.

The QR code is inserted by a DOM round trip that runs **after** the document is
signed. If that round trip pretty-prints (`formatOutput = true`), it re-indents
the signature block, so the digest recorded in `ds:SignedInfo` describes a
block the document no longer carries. The authority then refuses every
simplified document with *"Invalid signed properties hashing, SignedProperties
with id='xadesSignedProperties'"* - while standard documents still clear.

Three things make this fault easy to miss:

- **Only simplified documents are checked.** ZATCA stamps a standard document
  itself and does not verify the seller's signature; it must verify a
  simplified one, which is reported after the customer already holds it. So
  half the suite passes and points away from signing.
- **The SDK cannot see it.** It recomputes the digest from whatever bytes it is
  handed, so a document that contradicts itself still passes all 26
  conformance checks.
- **The invoice hash is taken after the round trip**, so it always matches the
  emitted document; only the signature can be left describing an earlier form.

The error message names the check that failed, not the thing that is wrong:
the digest encoding is correct, and changing it does not help.

### What the authority says when it refuses, and when it does not

Collected from the sandbox by submitting deliberate faults,
because the readiness checklist asked whether the codes the authority returns
are the ones handled, and only the authority can answer that.

**A resubmission is accepted, not refused.** The same simplified document was
reported twice, byte for byte, and both times the answer was HTTP 200
`REPORTED` with validation `PASS`. This is the property every retry in the
platform rests on - a retry after a timeout cannot know whether the first
attempt landed - and it is observed, not assumed. Re-check it against simulation when a taxpayer exists.

**The codes are not `BR-KSA-*`.** The rules are written as BR-KSA-nn; the API
answers with something else entirely, and in three different spellings:

| Fault submitted | HTTP | Code | Category |
|---|---|---|---|
| `invoiceHash` not matching the document | 400 | `invoiceHash_QRCODE_INVALID` | `QRCODE_VALIDATION` |
| A simplified document sent for clearance | 400 | `XML-INVOICE-ERROR` | `INVOICETYPE_ERRORS` |
| Not an invoice at all | 400 | `XSD_ZATCA_INVALID`, `QRCODE_INVALID`, `invalid-certificate` | `XSD validation`, `QRCODE_VALIDATION`, `CERTIFICATE_ERRORS` |

Three things follow. Nothing in `ErrorCode`'s 99 cases corresponds to these,
and that is correct rather than a gap: every one is a document-level fault,
which must not be retried, and they are carried to the operator verbatim. Any
future mapping must not assume a format, since the authority mixes
`snake_Case`, `UPPER-KEBAB` and `lower-kebab` in one response. And **the first
code is not necessarily the cause** - a document that was not an invoice drew
`invalid-certificate` alongside the real reason, with a perfectly good
certificate - which is why the refusal carries the first three messages rather
than one.

---

### Signing facts established against the authority

Established by submitting **the authority's own published sample** simplified
invoice, which it accepts, and varying one thing at a time:

| Fact | How |
|---|---|
| The digest rule is the element as the document carries it, with the prefixes it uses declared on it, hashed, written as hex, then base64 | It reproduces the digest recorded in three of ZATCA's own samples |
| Indentation is irrelevant | Compacting the block in an accepted sample and recomputing the digest - still accepted |
| The live API does not verify `ds:SignatureValue` | Those experiments invalidated it and were accepted anyway |
| The block's own content is right | The authority's sample carrying **our** block, digest recomputed - accepted |
| The sandbox issues everyone the same certificate | Our PCSID's CertDigest, issuer and serial are identical to the sample's |

`EmittedSignatureTest` asserts the property none of the above covers: that
the digest recorded in `SignedInfo` still describes the `SignedProperties` block
in the document **as finally emitted**, after the QR is inserted. It fails if
`formatOutput` is introduced, and it names the three files that handle a
signed document and must never pretty-print one.

`ds:Signature` carries `Id="signature"` and `QualifyingProperties` a
`Target` of `signature` with no `#`, matching the authority's own accepted
documents. XML-DSig reads `Target` as a URI reference, so `#signature` is the
conformant form; ZATCA compares it to the Id literally.

---

## 4. Outstanding, and reachable without a taxpayer

- **Scan a generated QR with ZATCA's mobile app.** Needs a phone, not a
  taxpayer, and it is the one thing neither the SDK nor the API can tell you:
  whether a consumer's scanner reads the TLV. Open on the readiness checklist.
- **Volume.** The sandbox round trip is six documents. Section 1.1 of the
  readiness guide targets a thousand invoices a minute; `tests/Load` holds k6
  scripts that have not been run against a deployment.

---

## 5. Blocked on a registered taxpayer

The Fatoora portal issues an OTP only against TIN credentials, so these cannot
be reached by a developer without a VAT registration:

- Simulation onboarding — ZATCA's intended rehearsal, with real certificates.
- Production onboarding, and the first cleared live invoice.
- Any confirmation that a real VAT registration is accepted.

### What simulation will take, when there is a taxpayer

The sequence, so it is not researched again on the day. Everything here is
already built; what is missing is the OTP, and the OTP needs the registration.

1. Sign in to the Fatoora portal as the taxpayer, switch to the **Simulation**
   environment, and generate an OTP. This is the step a developer cannot do:
   the portal authenticates a VAT-registered entity, not a person.
2. Onboard the taxpayer's organization through the platform, as described
   under "The rehearsal that counts" below. Not `fatoora:onboard`: it files as
   ZATCA's test taxpayer, which a real OTP can never be for, so it refuses any
   target but the sandbox. The CSR template switches itself to
   `PREZATCA-Code-Signing` from `ZATCA_ENVIRONMENT`.
3. The six compliance documents go up as they do against the sandbox, and a
   **production CSID is issued on passing**. Note the name: in simulation that
   certificate is still scoped to simulation. It is not the production
   credential and does not shorten production onboarding.

Two things to expect that differ from the sandbox, and one to re-check:

- The certificate will be **issued against the CSR actually sent**, so the
  private key and the certificate will finally be a matching pair. In the
  sandbox they are not, and cannot be - see section 3. `XadesSigner` refuses
  to sign a mismatched pair outside the sandbox, so a mismatch here will stop
  the run rather than produce unverifiable invoices.
- `ds:SignatureValue` becomes verifiable for the first time. Verify one with
  OpenSSL over the canonicalised `ds:SignedInfo` before reporting anything in
  volume; `SignatureVerifiesTest` does exactly that and can be pointed at a
  real document.
- Re-check that a resubmission is still idempotent. It is in the sandbox
  (section 3), and every retry in the platform depends on it.

### The rehearsal that counts: the platform's own path

Real filing reads each organization's credentials from `CredentialStore`,
written by the onboarding API, and signs with the organization's own VAT
number and address. So the rehearsal that says the platform works is this
one, on a separate deployment (the environment is per deployment, not per
organization):

1. A deployment with `ZATCA_ENVIRONMENT=simulation`, migrated, with `APP_URL`
   set. Not the production deployment: credentials are environment-specific.
2. An organization whose VAT number is the taxpayer's, with a complete
   address (building number of four digits, postal code of five, district,
   city) and an org admin with an API token.
3. The OTP, generated in the Fatoora portal's simulation environment. It is
   valid for an hour; the next three calls fit in minutes.
4. As the org admin, in order:
   - `POST /api/compliance/onboarding/ccsid` with `otp`, `common_name` (the
     device name, e.g. `EGS1-Masaar-001`), `serial_number` in ZATCA's
     `1-<solution>|2-<model or version>|3-<device uuid>` form,
     `organization_unit`, `location` and `industry`.
   - `POST /api/compliance/onboarding/compliance-check`. All six documents
     must pass; `results` says which did not and why.
   - `POST /api/compliance/onboarding/pcsid`. The organization is then
     onboarded.
5. File through the platform, not a command: a simplified invoice, a standard
   one, a credit note against each, and one with `prices_include_vat`. Use
   `POST /api/v1/pipeline/submit` as an ERP would, and the portal's Issue
   button once. Each should end reported or cleared in
   `GET /api/v1/pipeline/status/{invoiceId}`, and appear in the simulation
   portal's invoice list.
6. Then the checks no unit test can make: `fatoora:verify-hash-chain` passes;
   a printed simplified invoice's QR, scanned with a phone, decodes to its
   tags; a webhook endpoint receives `invoice.cleared`.

Everything else above is reachable today. Run the sandbox round trip, then
simulation when a taxpayer exists, then production — in that order, skipping
none.

---

## 6. Decisions taken, so they are not re-litigated

| Question | Answer | Why |
|---|---|---|
| Which clock do `IssueDate`, `IssueTime` and QR tag 3 use? | The Kingdom's | They are civil statements about when a document was issued. Read off UTC, an invoice issued 01:30 in Riyadh declared 22:30 — twenty-one hours out, for every invoice before 03:00 |
| Does `xades:SigningTime` keep its `Z`? | No, Saudi local, no designator | The authority's own samples and SDK write it without one |
| Where does a document discount reduce? | The taxable amount | EN 16931 and BR-CO-14 read a document-level allowance that way; VAT on the undiscounted base overstates the tax |
| Which certificate template? | From `config('fatoora.environment')` | The template tells ZATCA which environment a request is for, and one carrying another's is refused. Both commands read the same value so they cannot disagree |
| Submission rate ceiling | 120/min per organization, under ZATCA's | A limit above the authority's does not buy throughput; it moves the refusal from a cheap local 429 to a failed submission against the 24-hour deadline. **Confirm their published figure and stay under it** |
| May a signed document be pretty-printed? | Never | Indenting it moves the bytes every recorded digest describes, and the authority refuses every simplified document whose signed-properties digest no longer matches. `EmittedSignatureTest` fails if it is introduced. Build the document readable *before* signing if it has to be |
| Is `QualifyingProperties/@Target` a URI reference? | Not here | XML-DSig says it is, so `#signature` is conformant. The authority compares it to the signature's Id literally and its own accepted documents carry the bare `signature` |
| One encryption key for every tenant's credentials? | Adequate for one taxpayer | A per-tenant key wrapped by a managed key service is wanted before a second is onboarded — see `CredentialStore`'s own docblock |

---

## 7. How to run it

```bash
# Onboarding, end to end, against the sandbox. No credentials needed.
php artisan fatoora:onboard --step=full --otp=123345 --target=sandbox

# Then clear and report the six documents with the production certificate.
php artisan fatoora:onboard --step=submit --target=sandbox

# The authority's own validator over generated documents, if the SDK is here.
ZATCA_SDK_PATH=/path/to/zatca-einvoicing-sdk-Java-238-R3.4.8 php artisan test --filter ZatcaConformance
```

On Windows with Laragon, the shell's `php` may be 8.3 while this project
requires 8.4.1 or later, in which case Composer refuses to boot and says so.
Call the 8.4 binary directly rather than switching Laragon's global version,
which changes PHP for every site it serves.
