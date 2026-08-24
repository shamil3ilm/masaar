# 01 — Summary & Ladder Placement

**Audit date:** 2026-08-23/24 · **Repo:** `Masaar` @ `e83d8fe` (`chore/security-remediation-and-cleanup`)

---

> ## ⚡ Addendum — the central recommendation was acted on during the audit
>
> This document argues that the project's one structural weakness is that
> **verification is self-referential**: 715 tests assert the code does what the
> code intends, and none assert that it matches ZATCA.
>
> That gap is now closed in principle. `tests/Feature/Compliance/ZatcaConformanceTest.php`
> and `tests/Fixtures/ZatcaSdk.php` run **ZATCA's own SDK** over generated
> documents — four validators: UBL 2.1 schema, EN16931 rules, ZATCA Schematron,
> and the PIH chain check. Its docblock states the case better than this audit did:
>
> > *"Every other test in this suite asserts that the code does what the code
> > intends. That is worth having and it is not conformance: it cannot tell you
> > that BT-23 must read `reporting:1.0` on a standard invoice, because nothing
> > in this repository knew. ZATCA's validator knows — it rejected the document
> > as BR-KSA-EN16931-01 — and this runs it."*
>
> **It already caught a real defect** (Q2 / `ProfileID`, blocker #3 below).
>
> **The ladder is still L1, for one reason:** the harness **skips** unless
> `ZATCA_SDK_PATH` is set, and the SDK is a licensed download that cannot be
> committed. The suite reports **15 skipped** where it reported 3, and twelve of
> those are conformance. So item 2 (XSD validation) is **PRESENT-UNVERIFIED, not
> VERIFIED** — the mechanism exists and is correct, but the run that would prove
> it has not been recorded here.
>
> **To reach L2:** set `ZATCA_SDK_PATH`, run the suite, and confirm the twelve
> conformance tests pass rather than skip. That is now the whole of
> [08-next.md](08-next.md) T1–T4 — hours, not days, and the harness is already
> built. Skipping is honest; it is not yet proof.

---

## Ladder placement

# **L1 — fully satisfied. L2 is blocked by one missing artifact.**

That number will look harsh next to the code, so read the next paragraph before
reacting to it.

### Why L1 and not higher

The ladder is cumulative and I was asked to err downward. **L2 requires "UBL 2.1
XML generated **and** validating against the ZATCA XSD."** The first half is
comfortably true. The second half is not true at all:

- **There is no XSD anywhere in the repository.**
  `find . -name "*.xsd"` (excluding `vendor/`) returns nothing.
- The one place that would do it is commented out:
  ```php
  // For full validation, would load ZATCA XSD schema
  // $schemaPath = base_path('resources/zatca/Invoice.xsd');
  // $dom->schemaValidate($schemaPath);
  ```
  — [`InvoiceValidator.php:519-526`](../app/Domains/Compliance/Fatoora/Services/InvoiceValidator.php#L519-L526)
- The only other reference is a path into a ZATCA Java SDK that is not vendored:
  [`FatooraGenerateCsr.php:263`](../app/Console/Commands/FatooraGenerateCsr.php#L263).

No invoice this system has ever produced has been checked against the schema it
must conform to. Everything downstream — signature, QR, submission — is built on
a document whose structural correctness is assumed, not demonstrated.

### Why that number is misleading on its own

**The implementation is not linear.** Most of L3, and a real share of L4 and L5,
is already written and covered by passing tests. This is not a project that
stopped at L1; it is a project that built L3–L5 and skipped the L2 gate.

| Rung | Code exists? | Validated? |
|---|---|---|
| L1 Phase-1 invoice + QR | ✅ | ✅ VERIFIED — `QrCodeGenerator::generatePhase1`, 5 tags |
| L2 UBL 2.1 + **XSD validation** | ✅ / ❌ | **❌ XSD absent — the blocker** |
| L3 ECDSA · CSR OIDs · XAdES · TLV QR · hash+PIH | ✅ | 🟡 VERIFIED against *its own* tests, never against ZATCA |
| L4 CCSID · 6-doc compliance suite | ✅ | ❌ never executed against ZATCA |
| L5 PCSID · clearance/reporting split · retry · EGS units · renewal · archive | ✅ mostly | ❌ |

So: **L1 by the rung test; L3-shaped by volume; unproven above L1.**

### The single fact that changes the whole picture

Everything above L1 is unproven for the same reason: **no invoice from this
system has ever been sent to ZATCA, not even the sandbox.** There is no stored
ZATCA response, no CCSID, no PCSID, no conformance run. `runComplianceChecks()`
([`CsidOnboarding.php:82`](../app/Domains/Compliance/Fatoora/Services/CsidOnboarding.php#L82))
is fully written and generates all six document types
([`OnboardingController.php:243-250`](../app/Domains/Compliance/Fatoora/Http/Controllers/OnboardingController.php#L243-L250))
— it has simply never been run against a live endpoint.

Per your own status vocabulary, that makes essentially the entire ZATCA surface
**PRESENT-UNVERIFIED**, however complete it looks. And it looks very complete.

---

## What is genuinely VERIFIED

The suite is real and it is green — this is not a repo of aspirational code.

```
Tests:    3 skipped, 715 passed (1589 assertions)
Duration: 28.00s
```

The 3 skips are all `SecretFileTest` — POSIX file modes, which Windows does not
enforce. They run in CI (`ubuntu-latest`). Worth knowing that this class of
check is **unverifiable on your dev machine** and only ever proven by CI.

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
  columns you named. The mapping you asked me to verify is correct and tested:
  `is_third_party`→bit3, `is_nominal`→bit4, `is_export`→bit5, `is_summary`→bit6,
  `is_self_billed`→bit7
  ([`InvoiceXmlData.php:166-179`](../app/Domains/Compliance/Fatoora/DTOs/InvoiceXmlData.php#L166-L179)).
- **XAdES signature verifies** — `XadesPropertiesTest`, `Phase2SigningTest`.
- **PIH chaining** — `PreviousHashTest`.
- **Credential encryption at rest** — `CredentialStoreTest`, `CredentialKeyTest`.
- **Offline queue, circuit breaker, kill switch, duplicate detection, VAT
  period, timestamp drift, foreign-currency VAT in SAR** — all have tests.

Code quality is high: **zero** `TODO`/`FIXME`/`HACK` markers across 35,104 lines
of `app/`, two files over 1000 lines, dense and unusually candid docblocks that
name past bugs rather than hide them.

---

## Top 5 blockers

### 1. No XSD, no conformance fixtures — the L2 gate
**Severity: CRITICAL.** Blocks L2, and by dependency L3/L4 verification.
Evidence: [`InvoiceValidator.php:519-526`](../app/Domains/Compliance/Fatoora/Services/InvoiceValidator.php#L519-L526);
no `*.xsd` in repo.
Until an invoice is checked against the schema, every rung above L1 is a guess.

### 2. ⚡ The ZATCA-cleared XML was thrown away — **fixed and tested mid-audit**
**Severity: was CRITICAL; now CLOSED.**

When I began, `FatooraResponse::$clearedInvoice` was populated from the ZATCA
response and **read by nothing outside a DTO unit test**. For a B2B cleared
invoice, the document ZATCA returns — bearing its own stamp — is the legal
invoice; the system archived and would have delivered the pre-clearance version.

**This was implemented in the working tree while this audit was being written.**
HEAD is still `e83d8fe`; the change is uncommitted. It is complete and well
shaped: `invoices.cleared_xml`
([`0080_invoices.php:46`](../database/migrations/0080_invoices.php#L46)),
assignment at [`Submitter.php:400-402`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L400-L402),
a `clearedXml()` decoder that keeps the raw value verbatim rather than discard a
malformed one ([`:420-434`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L420)),
and `Invoice::getLegalXmlAttribute()` returning `cleared_xml ?? signed_xml`
([`Invoice.php:302-305`](../app/Domains/Invoice/Models/Invoice.php#L302-L305)).

I flagged the fix as untested. **Then the test landed too**, before I finished
writing: `tests/Feature/Compliance/ClearedDocumentTest.php` covers population
from base64, divergence from `signed_xml`, `legal_xml` precedence, null on a
reporting response, verbatim retention of a non-base64 value, and presence in
the pipeline payload. More thorough than what I was going to ask for.

**Item 34 went ABSENT → PRESENT-UNVERIFIED → VERIFIED inside a single audit
run.** Keep this blocker in the list only as the clearest example of the pattern
in the verdict below: a field modelled correctly, left unwired, and caught by
someone reading the code rather than by anything measuring it.

### 3. Two ZATCA spec constants look wrong — now pinned, still unanswered
**Severity: HIGH.**
[`XmlBuilder.php:125`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L125):
```php
$this->addElement('cbc:CustomizationID', 'urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod');
```
That is a generic OASIS string, not a ZATCA one.
[`XmlBuilder.php:130`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L130)
emits `ProfileID` of `clearance:1.0` for standard and `reporting:1.0` for
simplified.

ASSUMPTION: both are incorrect — ZATCA's published samples use
`urn:sa:zatca:documents:1.0` and `reporting:1.0` for *all* document types. **I
have not confirmed this against the current spec and you should not act on it
until you have.** Recorded as a question, not a fact, in [06-risks.md](06-risks.md).

**Update: one of the two was a real defect, and it is now fixed.**

`ProfileID` emitted `clearance:1.0` for standard invoices. ZATCA's validator
rejects that as **`BR-KSA-EN16931-01` — "Business process (BT-23) must be
reporting:1.0"** — and all nineteen SDK sample invoices use `reporting:1.0`
regardless of type. **Every standard B2B invoice this system produced would have
been rejected.** Now corrected to a single value for all types
([`XmlBuilder.php:127-137`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L127-L137)),
with the reasoning recorded: *"Clearance is chosen by the endpoint the document
is sent to, not by a field inside it."* Pinned by a positive and a negative
assertion in `XmlProfileTest`.

**`CustomizationID` (Q1) is still unpinned-as-correct** — unchanged at
`XmlBuilder.php:125`, held only as a tripwire. Q2 turning out wrong is the
argument for checking Q1 against the same samples; it is the same file.

This is the clearest vindication of the audit's central point: **the defect was
invisible to 715 passing tests and took ten minutes to find once an external
reference was consulted.**

### 4. B2B clearance is treated as non-blocking
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

### 5. One encryption key covers every tenant; no KMS
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

What worries me: **the verification is entirely self-referential.** 715 tests
assert that the code does what the code intends. Not one asserts that what the
code intends matches ZATCA. The XSD gap and the two suspect spec constants are
the same failure in two places — there is no external oracle anywhere in this
project. The `clearedInvoice` gap is what that costs: a field was modelled
correctly, tested at the DTO level, and then never wired up. Nothing noticed for
however long it sat there, because nothing was ever measured against reality.
That it got found and fixed *and tested* during this audit is genuinely
encouraging — it is evidence the habit that produces these defects is also the
habit that catches them. What it does not change is that the catching depends on
someone re-reading the code. An external oracle would have found it in minutes.

The gap between here and a demonstrably compliant product is **not large in
code**. It is one XSD, one sandbox credential, one conformance run, and the
handful of defects that run will expose. That is days-to-weeks of work, not
months. But it cannot be skipped, and no amount of additional internal testing
substitutes for it.

**Also true, and worth saying:** the ERP repos are dormant. `erp-backend` and
`erp-frontend` both last committed **2026-05-24**, three months ago, zero
commits in 90 days, and erp-backend carries **149 uncommitted changes including
41 staged deletions**. Masaar has 107 commits in 90 days. You have one live
project and two parked ones. See [07-consolidation.md](07-consolidation.md).

---

## Where to go next

One rung only: **L1 → L2.** See [08-next.md](08-next.md). The first three tasks
total roughly 11–17 hours and all three are prerequisites for trusting anything
else in this audit.
