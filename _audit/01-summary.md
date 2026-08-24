# 01 — Summary & Ladder Placement

**Repo:** `Masaar` → `github.com/shamil3ilm/masaar`, branch `main`

---

## Ladder placement

# **L1 — fully satisfied. L2 is one command away.**

That number will look harsh next to the code, so read the next two sections
before reacting to it.

### Why L1 and not higher

The ladder is cumulative and the brief asked me to err downward. **L2 requires
"UBL 2.1 XML generated **and** validating against the ZATCA XSD."** The first
half is comfortably true. The second half is not yet demonstrated.

The conformance harness exists and is correct.
[`tests/Feature/Compliance/ZatcaConformanceTest.php`](../tests/Feature/Compliance/ZatcaConformanceTest.php)
with [`tests/Fixtures/ZatcaSdk.php`](../tests/Fixtures/ZatcaSdk.php) runs
**ZATCA's own SDK** over generated documents — four validators: the UBL 2.1
schema, EN16931 rules, ZATCA's Schematron, and the PIH chain check.

**But it skips.** The SDK is a licensed download that cannot be committed and
needs a Java runtime, so the tests stand down unless `ZATCA_SDK_PATH` is set.
The suite reports **15 skipped**, twelve of them conformance:

```
Tests:  15 skipped, 715 passed (1640 assertions)
```

There is also no vendored XSD and no schema validation on the request path —
`schemaValidate()` remains commented out at
[`InvoiceValidator.php:519-526`](../app/Domains/Compliance/Fatoora/Services/InvoiceValidator.php#L519-L526).

So **no invoice this system produces has been checked against the schema it must
conform to.** Everything downstream — signature, QR, submission — rests on a
document whose structural correctness is assumed. A skip is an honest report of
that; it is not proof. Gap item 2 is therefore PRESENT-UNVERIFIED, not VERIFIED.

**To reach L2:** set `ZATCA_SDK_PATH`, run the suite, confirm the twelve
conformance tests pass rather than skip. That is the whole of
[08-next.md](08-next.md) — the hard part is already built.

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
  columns: `is_third_party`→bit3, `is_nominal`→bit4, `is_export`→bit5,
  `is_summary`→bit6, `is_self_billed`→bit7
  ([`InvoiceXmlData.php:166-179`](../app/Domains/Compliance/Fatoora/DTOs/InvoiceXmlData.php#L166-L179)).
- **XAdES signature verifies** — `XadesPropertiesTest`, `Phase2SigningTest`.
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

## Top 5 blockers

### 1. The conformance suite skips — the L2 gate
**Severity: CRITICAL.** Blocks L2, and by dependency the verification of L3–L5.

The harness is built and correct; it does not run.
`ZatcaConformanceTest` stands down unless `ZATCA_SDK_PATH` names a ZATCA SDK
with a Java runtime available, so **twelve conformance tests skip** and no
generated document has been put in front of ZATCA's schema, EN16931 rules or
Schematron. Separately, schema validation on the request path is still commented
out at [`InvoiceValidator.php:519-526`](../app/Domains/Compliance/Fatoora/Services/InvoiceValidator.php#L519-L526)
and no `*.xsd` is vendored.

Until a document is checked against the schema, every rung above L1 is inference.

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

**The reason this ranks HIGH:** its sibling constant was wrong. `ProfileID`
emitted `clearance:1.0` on standard invoices, which ZATCA rejects as
`BR-KSA-EN16931-01` — every standard B2B invoice would have been refused. That
was invisible to 715 passing tests and took minutes to settle once an external
reference was consulted. The same check settles `CustomizationID`, in the same
file.

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
