# 08 — The Next Rung Only

**Current: L2** — verified against ZATCA's SDK 238-R3.4.8
**Target: L3** — *ECDSA secp256k1 keys, CSR with correct OIDs, XAdES embedded,
TLV Phase-2 QR, invoice hash + PIH chain*

This document covers that rung and nothing else.

---

## The whole of L3 is one thing: get a CSID

Local conformance proved what the documents **say** — XSD, EN16931 and the ZATCA
Schematron pass for all six document types with zero advisories. It cannot prove
who **signed** them, because the four stages that would (certificate, QR,
signature, PIH) are excluded by design: a self-signed key cannot satisfy them
([`ZatcaConformanceTest.php:155-178`](../tests/Feature/Compliance/ZatcaConformanceTest.php#L155-L178)).

A compliance CSID turns all four green at once. There is no partial credit
available and no cheaper substitute.

---

## Exit criteria for L3

- [ ] A CCSID issued against a CSR this platform generated
- [ ] The four excluded stages assert instead of filtering — certificate, QR,
      signature, PIH — against that certificate
- [ ] `secp256k1` confirmed as the curve actually used (see T2)
- [ ] `CustomizationID` settled against the specification

Not in scope: PCSID, clearance/reporting submission, renewal. Those are L4–L5.

---

## The first three tasks

### T1 — Obtain a compliance CSID · **3–6 h**

**Do:**
1. Register on the Fatoora **simulation** portal and obtain an OTP.
2. Run `fatoora:generate-csr`, then `fatoora:onboard` with the OTP.
   `CsidOnboarding::requestComplianceCsid()`
   ([`:33-71`](../app/Domains/Compliance/Fatoora/Services/CsidOnboarding.php#L33-L71))
   already implements the exchange; `CredentialStore` already encrypts what
   comes back.
3. Submit the six compliance documents —
   `OnboardingController::runComplianceCheck()` builds them already.

**Watch for:** this is the first time the CSR meets a real authority. Expect the
OIDs, the template name and the `organizationIdentifier` to be scrutinised. If
it is rejected, the rejection text names the field.

**Done when:** a CCSID is stored and the six compliance documents are accepted.

---

### T2 — Make the curve fallback fatal · **30 min** · *do before T1*

[`FatooraGenerateCsr.php:307-309`](../app/Console/Commands/FatooraGenerateCsr.php#L307-L309)
and `:490-491` fall back to `prime256v1` behind a `warn()` when `secp256k1` is
unavailable. **`prime256v1` is the wrong curve for ZATCA.** A key generated that
way onboards and then fails cryptographically, far from the cause.

Thirty minutes now saves a confusing failure during T1. Make it throw.

---

### T3 — Settle `CustomizationID` · **1–2 h** *(independent)*

The conformance run is **silent** on this: searching the SDK's Schematron and
XSLT for `CustomizationID` returns no rule, so a green run neither endorses nor
rejects the current value.

[`XmlBuilder.php:125`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L125)
emits `urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod`, a generic
OASIS string. ASSUMPTION: ZATCA expects `urn:sa:zatca:documents:1.0`.

**Do:** read the XML Implementation Standard PDF sitting beside the SDK, or a
ZATCA sample invoice under `Data/`. Correct `XmlBuilder` if it differs, updating
the pinned constant at `XmlProfileTest:33` in the same commit.

---

## Then: T4 — put conformance in CI · **2–4 h**

Right now the run is manual and CI has no Java, so a regression in document
generation would not fail the build — the same exposure that let `ProfileID`
stay wrong. Options: cache the SDK as a CI artifact, use a self-hosted runner,
or make conformance a documented pre-release gate. Any is defensible; leaving it
undecided is not. Tracked as R-19.

---

## Effort summary

| Task | Hours | Blocking? |
|---|---|---|
| T2 Make the curve fallback fatal | 0.5 | Do first — it protects T1 |
| T1 Obtain a compliance CSID | 3–6 | **Yes — this is L3** |
| T3 Settle `CustomizationID` | 1–2 | No — independent |
| T4 Conformance in CI | 2–4 | Not for L3; prevents regression |
| **To reach L3** | **5–13 h** | |

---

## Baseline

`main`, clean tree. With `ZATCA_SDK_PATH` set to SDK 238-R3.4.8:
**727 passed / 3 skipped (1670 assertions)**.

⚠️ The default `php` here is **8.2.28**; `composer.json` requires `^8.4`, so
`php artisan test` aborts in `platform_check.php` **and exits 0** — a failure
that reports success. Use
`C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe`.

⚠️ Use SDK **238-R3.4.8**, not `zatca-envoice-sdk-203` — the latter is release
**3.0.8**, an older build despite the more recent download, and its jar is named
`cli-*.jar`, which `ZatcaSdk::jar()` does not glob.

---

## What NOT to do next

- **Don't extract the standalone package yet.** It is feasible and cheap (46–62 h,
  [07-consolidation.md](07-consolidation.md)) but freezing the DTO boundary before
  Q3 is answered risks baking in a per-taxpayer chain that may need to be
  per-EGS-unit.
- **Don't fix R-4 (blocking B2B clearance) yet.** It is the largest behavioural
  change in the audit and the sandbox run may change what "cleared" means in
  practice.
- **Don't tighten the schema (R-16) yet.** Do it after the sandbox, when the
  shape is settled.
- **Don't start the UAE/Qatar jurisdictions.** Saudi is one XSD away from being
  demonstrable. Finish it.
