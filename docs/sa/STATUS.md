# Saudi Arabia (ZATCA Phase 2) — where the implementation stands

**Written for whoever picks up the ZATCA work next, including the person who
left it.** It says what has been established, by what evidence, and what has
not — so that nothing here has to be re-derived and nothing is assumed to work
because it looks finished.

Reference material is elsewhere and not repeated: [README.md](README.md) for
what the authority requires, [HASHING-AND-SIGNING.md](HASHING-AND-SIGNING.md)
for every digest and how each was pinned down,
[COMPLIANCE-RULES.md](COMPLIANCE-RULES.md) for the BR-KSA rules, and
[../PRODUCTION-READINESS.md](../PRODUCTION-READINESS.md) for the go-live
checklist.

Last updated 2026-10-10.

---

## 1. Established against the authority itself

These are not test doubles. Each was observed against ZATCA's own developer
portal at `gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal`.

| What | Evidence |
|---|---|
| A certificate request is accepted and a **compliance CSID** issued | `fatoora:onboard --step=ccsid`, HTTP 200, disposition `ISSUED` |
| **All six compliance documents** are accepted — standard and simplified invoice, credit note and debit note | `--step=compliance`, six `PASS` |
| A **production CSID** is issued | `--step=pcsid`, HTTP 200, disposition `ISSUED` |

First completed in CI on 2026-10-09 and again locally on 2026-10-10. The
sandbox needs no taxpayer: the developer portal accepts the fixed OTP
`123345`, which is the one value in the flow that could not be confirmed any
other way.

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

**On 2026-10-10 all six were accepted** - three CLEARED with a stamped copy
returned, three REPORTED. The authority accepts documents generated and signed
here, over the real protocol, on both routes.

`ProductionSubmissionTest` covers the part that is ours - that three documents
go each way, that `Clearance-Status: 1` rides only on clearance, that a refusal
ends the command non-zero - with the exchange faked.

### How the simplified documents were failing, and why it took so long

Worth reading before touching the signer, because every plausible theory here
was wrong and the evidence said so each time.

Every simplified document was refused with *"Invalid signed properties hashing,
SignedProperties with id='xadesSignedProperties'"* while every standard one
cleared. The cause was `formatOutput = true` on the DOM round trip that inserts
the QR code - which runs **after** the document is signed. Pretty-printing
re-indented the signature block, from 726 bytes to 1130, so the digest recorded
in `ds:SignedInfo` described a block the document no longer carried.

Three things conspired to hide it:

- **Only simplified documents are checked.** ZATCA stamps a standard document
  itself and does not verify the seller's signature; it must verify a
  simplified one, which is reported after the customer already holds it. So
  half the suite passed and pointed away from signing.
- **The SDK cannot see it.** It recomputes the digest from whatever bytes it is
  handed, so a document that contradicts itself still passes. All 26
  conformance checks did.
- **An earlier fix hid it.** The invoice hash had the same problem and was
  fixed by taking it *after* the round trip rather than before. That left the
  signature as the only thing still describing the unformatted document.

What the error message says is not what it means: it was the one check that
failed, not the thing that was wrong. Four digest encodings were tried against
the authority - the SDK's form and the canonical form, each as hex and as bytes
- and all four were refused, which is what finally ruled out the hashing.

### What settled it, and the facts worth keeping

Submitting **the authority's own published sample** simplified invoice, which
it accepts. That turned guesswork into bisection, and established:

| Fact | How |
|---|---|
| The digest rule is the element as the document carries it, with the prefixes it uses declared on it, hashed, written as hex, then base64 | It reproduces the digest recorded in three of ZATCA's own samples |
| Indentation is irrelevant | Compacting the block in an accepted sample and recomputing the digest - still accepted |
| The live API does not verify `ds:SignatureValue` | Those experiments invalidated it and were accepted anyway |
| The block's own content is right | The authority's sample carrying **our** block, digest recomputed - accepted |
| The sandbox issues everyone the same certificate | Our PCSID's CertDigest, issuer and serial are identical to the sample's |

`EmittedSignatureTest` now asserts the property none of the above covered: that
the digest recorded in `SignedInfo` still describes the `SignedProperties` block
in the document **as finally emitted**, after the QR is inserted. It fails if
`formatOutput` is reintroduced, and it names the three files that handle a
signed document and must never pretty-print one.

`ds:Signature` now carries `Id="signature"` and `QualifyingProperties` a
`Target` of `signature` with no `#`, matching the authority's own accepted
documents. XML-DSig reads `Target` as a URI reference, so `#signature` is the
conformant form; ZATCA compares it to the Id literally. That was not the cause
of the refusals, and is right regardless.

---

## 4. Outstanding, and reachable without a taxpayer

- **`ZATCA_SDK_URL` / `ZATCA_SDK_TOKEN`.** The 26 conformance tests skip in CI
  without them, and the job says so with a warning rather than passing
  quietly. The SDK is a licensed download that cannot be committed, so it is
  hosted as a release asset in the **private** repository
  `shamil3ilm/zatca-sdk`, and the two secrets are set on this repository:

  | Secret | Value |
  |---|---|
  | `ZATCA_SDK_URL` | `https://api.github.com/repos/shamil3ilm/zatca-sdk/releases/latest` |
  | `ZATCA_SDK_TOKEN` | A fine-grained token with **Contents: Read-only** on that repository, and nothing else |

  The URL is the *release*, not the asset. The `conformance` job resolves the
  release to whichever asset is named for the SDK, which matters because a
  private repository's asset id appears nowhere in the GitHub web UI - so
  pointing at the asset means using the API with a token merely to configure
  the thing. Naming the release instead also survives the SDK being replaced:
  publish a newer release and CI follows it.

  Not a `releases/download/...` link. Those need a browser session and answer
  a token with HTML, which arrives as "SDK archive unreadable".

  The archive may be pristine or installed; the job regenerates
  `Configuration/config.json` from `defaults.json` either way, because
  `install.sh` writes the absolute paths of the machine it ran on and the SDK
  reports an unreadable schema as *every document being invalid*.

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

Everything else above is reachable today. Run the sandbox round trip, then
simulation when a taxpayer exists, then production — in that order, skipping
none.

---

## 6. Decisions taken, so they are not re-litigated

| Question | Answer | Why |
|---|---|---|
| Which clock do `IssueDate`, `IssueTime` and QR tag 3 use? | The Kingdom's | They are civil statements about when a document was issued. Read off UTC, an invoice issued 01:30 in Riyadh declared 22:30 — twenty-one hours out, for every invoice before 03:00 |
| Does `xades:SigningTime` keep its `Z`? | No, Saudi local, no designator | Reasoned the other way first - a signing instant with an explicit marker - and the authority's own samples and SDK write it without one. Changing it did not make the live API accept the digest either, so it was not the cause of that; it is simply what the authority writes |
| Where does a document discount reduce? | The taxable amount | EN 16931 and BR-CO-14 read a document-level allowance that way; VAT on the undiscounted base overstates the tax |
| Which certificate template? | From `config('fatoora.environment')` | The template tells ZATCA which environment a request is for, and one carrying another's is refused. Both commands read the same value so they cannot disagree |
| Submission rate ceiling | 120/min per organization, under ZATCA's | A limit above the authority's does not buy throughput; it moves the refusal from a cheap local 429 to a failed submission against the 24-hour deadline. **Confirm their published figure and stay under it** |
| May a signed document be pretty-printed? | Never | Indenting it moves the bytes every recorded digest describes. This is what refused every simplified document for weeks, and `EmittedSignatureTest` now fails if it returns. Build the document readable *before* signing if it has to be |
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
