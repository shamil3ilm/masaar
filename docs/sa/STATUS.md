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

## 3. Built, not yet observed against the authority

`fatoora:onboard --step=submit` clears and reports the six documents with the
**production** certificate, against `/invoices/clearance/single` and
`/invoices/reporting/single`. Standard documents are cleared, because B2B must
be cleared before it is issued; simplified are reported, where B2C goes within
twenty-four hours.

`ProductionSubmissionTest` covers the part that is ours — that three documents
go each way, that `Clearance-Status: 1` rides only on clearance, that a
refusal ends the command non-zero — with the exchange faked.

**Run on 2026-10-10, and it answered more than it was asked.** The portal
serves both endpoints. All three standard documents were **CLEARED and a
stamped copy returned** - the authority accepted documents generated and
signed here, over the real protocol, and sent back its own signed version.

All three simplified documents were **REFUSED**: "Invalid signed properties
hashing, SignedProperties with id='xadesSignedProperties'". The cause was
`xades:SigningTime` carrying a trailing `Z` where the authority writes local
time with no designator, and it is fixed - but **the fix has not yet been put
back to the authority.** Re-run the step to confirm it.

Why that matters beyond the bug: all 26 SDK conformance tests passed while the
live API refused those documents. The SDK recomputes the digest from the bytes
it is given, so it cannot catch a disagreement about what to write - only
submitting can. Standard documents cleared throughout, because ZATCA stamps
those itself and does not check the seller's signature the way it must for a
simplified document, which is reported after the customer already has it.

This was described for some time as needing a real taxpayer. It does not. That
was wrong, and `fatoora:sandbox-test --step=report` — which exists for exactly
this and prints the two URLs before returning success, having submitted
nothing — is probably why nobody checked.

---

## 4. Outstanding, and reachable without a taxpayer

- **`ZATCA_SDK_URL` / `ZATCA_SDK_TOKEN`.** The 26 conformance tests skip in CI
  without them, and the job says so rather than passing quietly. The SDK is a
  licensed download that cannot be committed; host it as a release asset in a
  **private** repository and point the secret at the asset's API URL. See the
  `conformance` job in `.github/workflows/ci.yml`.
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
| Does `xades:SigningTime` keep its `Z`? | Yes, UTC | It is a signing instant with an explicit marker, not a civil statement, and dropping the marker makes it guessable |
| Where does a document discount reduce? | The taxable amount | EN 16931 and BR-CO-14 read a document-level allowance that way; VAT on the undiscounted base overstates the tax |
| Which certificate template? | From `config('fatoora.environment')` | The template tells ZATCA which environment a request is for, and one carrying another's is refused. Both commands read the same value so they cannot disagree |
| Submission rate ceiling | 120/min per organization, under ZATCA's | A limit above the authority's does not buy throughput; it moves the refusal from a cheap local 429 to a failed submission against the 24-hour deadline. **Confirm their published figure and stay under it** |
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
