# 01 — Summary & Ladder Placement

**Repo:** `Masaar` → `github.com/shamil3ilm/masaar`, branch `main`

---

## Ladder placement

# **L2 — fully satisfied and verified against ZATCA's own validator.**

### Why L2

**L2 requires "UBL 2.1 XML generated and validating against the ZATCA XSD."**
Both halves now hold, demonstrated rather than argued.

`ZatcaConformanceTest` runs the ZATCA Java SDK (**238-R3.4.8**) over documents
this platform generates and signs. With `ZATCA_SDK_PATH` set:

```
Tests:  12 passed (77 assertions)   — conformance only
Tests:  3 skipped, 727 passed (1670 assertions)   — full suite
```

Verified across **all six ZATCA document types** — standard and simplified ×
invoice, credit note, debit note:

| Stage | What it checks | Result |
|---|---|---|
| `XSD` | UBL 2.1 schema | ✅ |
| `EN` | CEN EN16931 rules | ✅ |
| `KSA` | ZATCA Schematron (BR-KSA-*) | ✅ zero errors |
| — | advisories/warnings | ✅ **zero** |

Two details that make this credible rather than self-congratulatory:

- **A control test.** `test_the_authority_own_sample_passes` runs ZATCA's own
  sample invoice through the same harness. If the harness were misconfigured,
  that fails too — so a pass means the pipeline is genuinely being exercised.
- **Advisories are asserted, not ignored.** `test_no_advisories` fails on any
  warning. Its docblock explains why: BR-KSA-51 once reported every line's
  amount-with-VAT as zero and the document cleared anyway. *"A rule ZATCA is
  willing to overlook is still a rule about what the invoice says."*

### Why not L3

L3 asks for the cryptography to be right: secp256k1 keys, a CSR with correct
OIDs, an embedded XAdES signature, a TLV Phase-2 QR, and the invoice hash and
PIH chain.

The code for all of it exists and the documents are genuinely signed before
validation — `Submitter::generate()` runs, and the suite asserts the XML came
back signed. But the conformance run **explicitly excludes** the four checks
that would prove the crypto, because they cannot pass with a self-signed key
([`ZatcaConformanceTest.php:155-178`](../tests/Feature/Compliance/ZatcaConformanceTest.php#L155-L178)):

> *"Four of the SDK's checks — the certificate, the QR that embeds it, the
> signature over both, and the PIH chain it compares against its own configured
> file — cannot pass with the self-signed key these tests generate."*

That is the right call for a test suite that must run without a production
certificate. It also means **what the conformance run proves is the document's
content, not its cryptographic stamp.**

So L3 stays PRESENT-UNVERIFIED, and the thing blocking it is now identical to
the thing blocking L4: **a real CSID.** One item, not two.

### Where the rungs stand

**The implementation is not linear.** Most of L3, and a real share of L4 and L5,
is written and covered by passing tests. This is not a project that stopped at
L2; it is a project that built L3–L5 and is now proving them from the bottom up.

| Rung | Code exists? | Validated? |
|---|---|---|
| L1 Phase-1 invoice + QR | ✅ | ✅ VERIFIED |
| L2 UBL 2.1 + XSD/EN/KSA validation | ✅ | ✅ **VERIFIED against ZATCA's SDK, six document types, zero advisories** |
| L3 ECDSA · CSR OIDs · XAdES · TLV QR · hash+PIH | ✅ | 🟡 documents are signed and structurally sound; the four crypto stages are excluded from the run pending a real CSID |
| L4 CCSID · 6-doc compliance submission | ✅ | ❌ never submitted |
| L5 PCSID · clearance/reporting · retry · EGS units · renewal · archive | ✅ mostly | ❌ |

### The single fact that bounds everything above L2

**Nothing has ever been sent to ZATCA — not even the sandbox.** No CCSID, no
PCSID, no stored authority response. `CsidOnboarding::onboard()`
([`:165-198`](../app/Domains/Compliance/Fatoora/Services/CsidOnboarding.php#L165-L198))
runs all four onboarding steps and `OnboardingController` generates the six
required documents ([`:243-250`](../app/Domains/Compliance/Fatoora/Http/Controllers/OnboardingController.php#L243-L250));
neither has met a live endpoint.

Local conformance closed the question of **what the documents say**. It cannot
close the question of **who signed them**.

---

## What is genuinely VERIFIED

The suite is real and it is green — this is not a repo of aspirational code.

```
Tests:    3 skipped, 727 passed (1670 assertions)
Duration: 51.63s        (with ZATCA_SDK_PATH set)
```

The 3 skips are all `SecretFileTest` — POSIX file modes, which Windows does not
enforce. They run in CI (`ubuntu-latest`). Worth knowing that this class of
check is **unverifiable on this machine** and only ever proven by CI.

⚠️ **You cannot run it with the default `php` on this machine.** `php -v` is
**8.2.28**; `composer.json` requires `^8.4`. `php artisan test` dies in
`vendor/composer/platform_check.php` — **and exits 0**, so it looks like a pass
in CI-shaped tooling. Use:

```
C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe artisan test
```

Genuinely verified by that run, among others:

- **Tenant isolation** — `TenantIsolationTest` (7 assertions), including
  "missing tenant context yields no rows". Backed by an architecture test
  (`RawTenantQueryTest`) that fails the build on unscoped raw SQL.
- **ICV allocation** — `IcvAllocationTest`: starts at 1, increments, is per-org,
  and a duplicate is rejected by `invoices_org_icv_unique`.
- **BT-3 invoice-type flags travel end to end** — `InvoiceTypeCodeTest` asserts
  the 7-character `cbc:InvoiceTypeCode/@name` built from the five boolean
  columns: `is_third_party`→bit3, `is_nominal`→bit4, `is_export`→bit5,
  `is_summary`→bit6, `is_self_billed`→bit7
  ([`InvoiceXmlData.php:166-179`](../app/Domains/Compliance/Fatoora/DTOs/InvoiceXmlData.php#L166-L179)).
- **XAdES signature verifies** — `XadesPropertiesTest`, `Phase2SigningTest`.
- **All six document types satisfy ZATCA's own rules** — `ZatcaConformanceTest`, 12 cases, zero errors and zero advisories, with the authority's own sample as a control.
- **PIH chaining** — `PreviousHashTest`.
- **The ZATCA-cleared document is kept and preferred** — `ClearedDocumentTest`:
  `cleared_xml` populated from the response, distinct from `signed_xml`,
  `legal_xml` prefers it, null on a reporting response, a non-base64 value
  retained verbatim rather than discarded.
- **Credential encryption at rest** — `CredentialStoreTest`, `CredentialKeyTest`.
- **Offline queue, circuit breaker, kill switch, duplicate detection, VAT
  period, timestamp drift, foreign-currency VAT in SAR** — all have tests.

Code quality is high: **zero** `TODO`/`FIXME`/`HACK` markers across 35,104 lines
of `app/`, two files over 1000 lines, dense and unusually candid docblocks that
name past bugs rather than hide them.

---

## Top blockers

### 1. No CSID — the L3/L4 gate
**Severity: CRITICAL.** Now the single blocker for everything above L2.

No CCSID, no PCSID, no stored authority response. The onboarding code is
complete — `CsidOnboarding::onboard()` runs all four steps and
`OnboardingController` generates the six required documents — and has never been
executed against a live endpoint.

Until a certificate is issued, four things stay unproven: the CSR's OIDs and
template name, the cryptographic stamp, the Phase-2 QR that embeds the
certificate, and the PIH chain as ZATCA computes it. The conformance harness
excludes exactly these, by design, because a self-signed key cannot satisfy them.

**One action resolves all four.** See [08-next.md](08-next.md).

### 2. `CustomizationID` is unverified against the specification
**Severity: HIGH.**
[`XmlBuilder.php:125`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L125):
```php
$this->addElement('cbc:CustomizationID', 'urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod');
```
That is a generic OASIS string, not a ZATCA one.

ASSUMPTION: ZATCA expects `urn:sa:zatca:documents:1.0`. **Not confirmed against
the specification — do not act on it until you have.** Recorded as an open
question (Q1) in [06-risks.md](06-risks.md).

`XmlProfileTest:33` pins the current value, which stops it drifting silently but
does not make it right — the test is explicit that it is *"a tripwire, not a
certificate."*

**The conformance run does not settle this.** Searching the SDK's Schematron and
XSLT for `CustomizationID` returns **no rule**, so the green run is silent on
this element rather than endorsing it. An unenforced field can still be wrong,
and a validator that does not check it will never say so.

**The reason this ranks HIGH:** its sibling constant *was* wrong. `ProfileID`
emitted `clearance:1.0` on standard invoices, which ZATCA rejects as
`BR-KSA-EN16931-01` — every standard B2B invoice would have been refused. That
one the SDK does enforce, which is how it surfaced. `CustomizationID` has no
such safety net, so it needs reading against the specification directly. The
[XML Implementation Standard PDF](file:///C:/Users/Shamil/Personal/Zatca/) sits
beside the SDK.

### 3. B2B clearance is treated as non-blocking
**Severity: HIGH.**
[`PipelineService.php:30-32`](../app/Domains/Pipeline/Services/PipelineService.php#L30-L32)
states the rule plainly: *"once an invoice is issued it stays issued."*
`submit()` catches every failure and returns an issued invoice with errors
attached ([`PipelineService.php:143-189`](../app/Domains/Pipeline/Services/PipelineService.php#L143-L189)).

The system **does** correctly route to different endpoints — `clearInvoice` vs
`reportInvoice` ([`Submitter.php:170-186`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L170-L186))
— and **does** enforce the B2C 24-hour deadline
([`Submitter.php:254-298`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L254-L298)).
What it does not do is treat a *failed clearance* differently from a *failed
report*. For B2B, clearance is pre-issuance and blocking: an uncleared standard
invoice is not legally issuable. Nothing is silently swallowed — it is logged
and returned in `errors` — but the caller gets an invoice object either way.

### 4. One encryption key covers every tenant; no KMS
**Severity: HIGH.**
[`CredentialStore.php:60-76`](../app/Domains/Compliance/Fatoora/Services/CredentialStore.php#L60-L76).
Keys *are* encrypted at rest, the disk *is* configurable, previous-key rotation
*is* implemented, and `masaar:rotate-credential-key` exists. The remaining gap —
per-tenant data keys wrapped by a KMS — is named in the code's own docblock
(lines 29-31) as the unclosed half of a prior finding. One compromised secret
exposes every taxpayer's signing key on the platform.

---

## The honest verdict, in plain words

**This is not a half-built spike and it is not nearly-L3. It is a carefully
built L3/L4-shaped implementation that has never met the authority it was built
for.**

What impressed me: the tenant isolation is structural rather than by
convention; the ICV concurrency problem is not only handled but the *wrong*
solution is explained in a docblock and rejected for the right reason
([`Invoice.php:198-209`](../app/Domains/Invoice/Models/Invoice.php#L198-L209));
the submission state model has ten states, distinct warning/error arrays and a
full actor-attributed state log; there are zero TODO markers; and the code
repeatedly documents bugs it used to have — a signature over the empty string, a
PIH that always claimed genesis, a CSR that never carried the VAT number. Those
are the notes of someone who found real defects by testing, not someone
generating plausible code.

What worries me: **the verification is almost entirely self-referential.** 715
tests assert that the code does what the code intends. Until the conformance
harness actually runs, not one asserts that what the code intends matches ZATCA.

That is not a hypothetical cost. `ProfileID` carried a value ZATCA rejects
outright, and no amount of internal testing could have found it — the repository
did not contain the knowledge. `CustomizationID` sits in the same file, in the
same condition, today.

The harness that closes this is already written and it is good: four validators,
an honest skip when the SDK is absent, and a docblock that states the problem
precisely — *"it cannot tell you that BT-23 must read `reporting:1.0` on a
standard invoice, because nothing in this repository knew."* **What is missing
is one environment variable and one run.**

The gap between here and a demonstrably compliant product is **not large in
code**. It is an SDK path, a sandbox credential, one conformance run, and the
handful of defects that run will expose. Days, not months. But it cannot be
skipped, and no further internal testing substitutes for it.

**Also true:** the ERP repos are dormant. `masaar-erp-backend` and
`masaar-erp-frontend` last committed substantive feature work in **May 2026**,
with zero commits in the 90 days before this audit. Masaar had 107. You have one
live project and two parked ones. See [07-consolidation.md](07-consolidation.md).

---

## Where to go next

One rung only: **L1 → L2.** See [08-next.md](08-next.md).
