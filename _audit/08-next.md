# 08 — The Next Rung Only

**Current: L1** (fully satisfied)
**Target: L2** — *UBL 2.1 XML generated and validating against the ZATCA XSD*

This document covers **that rung and nothing else.** No roadmap to L3–L5. Those
rungs are already largely built ([01-summary.md](01-summary.md)) — they are
blocked on verification, not construction, and verification starts here.

---

## Why L2 and not "go straight to the sandbox"

Tempting, because the sandbox would verify a dozen things at once. But a sandbox
run without local schema validation gives you a rejection with a ZATCA error
code and no local way to iterate. You would be debugging through a remote API
with a slow feedback loop.

**Get the XSD first.** Then every fix is a local test run, and the sandbox
becomes a confirmation rather than a debugger.

---

## Exit criteria for L2

- [ ] ZATCA UBL 2.1 XSDs vendored in the repo
- [ ] `InvoiceValidator::validateAgainstSchema()` actually calls `schemaValidate()`
- [ ] A test that fails the build when generated XML violates the schema
- [ ] All six document types validate — standard/simplified × invoice/credit/debit
- [ ] Q1 and Q2 from [06-risks.md](06-risks.md) resolved and **asserted by test**

Deliberately **not** in scope: signature verification against ZATCA's profile,
schematron/BR-KSA rules, any live call. Those are L3/L4.

---

## Dependency order

```
T1  Obtain + vendor the ZATCA XSDs
     │
     ├──────────────┐
     ▼              ▼
T2  Wire            T3  Resolve Q1/Q2
    schemaValidate      (CustomizationID,
     │                   ProfileID)
     │                   ⟵ can start now, independent
     └──────┬───────────┘
            ▼
      T4  Six-type validation test  → L2 reached
```

T3 has no dependency on T1 — it is documentation research. **Start it in
parallel** if you have a spare evening; it is the only task here that is not
keyboard work.

---

## The first three tasks

### T1 — Obtain and vendor the ZATCA XSDs · **2–4 h**

The single highest-leverage action available. Everything else waits on it.

**Do:**
1. Download the ZATCA E-Invoicing SDK from the Fatoora portal (developer
   resources / technical guidelines package).
2. Extract the UBL 2.1 schema set. The path already referenced in the codebase
   tells you what the tree looks like:
   [`FatooraGenerateCsr.php:263`](../app/Console/Commands/FatooraGenerateCsr.php#L263)
   → `.../Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd`
3. Vendor to `resources/zatca/xsd/` — the path `InvoiceValidator` already
   anticipates ([`:525`](../app/Domains/Compliance/Fatoora/Services/InvoiceValidator.php#L525)
   references `resources/zatca/Invoice.xsd`).
4. Keep the **whole** schema tree, not just `maindoc` — UBL imports
   `common/UBL-CommonAggregateComponents-2.1.xsd` and friends; a lone maindoc
   file will not resolve.
5. Commit them. They are spec artefacts, not dependencies — vendoring is correct.

**Watch for:** `libxml` resolving imports relative to the XSD's own path. Load by
file path, not by string, or the imports break. Note `App\Support\Xml` sets
`LIBXML_NONET` — good for security, and it means every import must be local.

**Done when:** `resources/zatca/xsd/` exists and a throwaway script can
`schemaValidate()` any well-formed UBL document without an import error.

---

### T2 — Wire schema validation into the pipeline · **4–6 h**

**Do:**
1. Replace the commented-out block at
   [`InvoiceValidator.php:519-526`](../app/Domains/Compliance/Fatoora/Services/InvoiceValidator.php#L519-L526)
   with a real implementation:
   - `libxml_use_internal_errors(true)`, then collect `libxml_get_errors()` into
     the existing `ValidationResult` shape rather than throwing raw.
   - Return line/column and the failing element — a bare "invalid" is useless.
2. Add `fatoora.validation.schema_path` to `config/fatoora.php`.
   ⚠️ `ConfigKeyTest` and `EnvExampleTest` will fail the build if you add a
   config key without registering it — that is the architecture suite working.
3. Decide where it runs. **Recommendation: validate at generation, not at
   submission** — inside `DocumentBuilder::generateComplianceData()`, *before*
   signing. A document that fails the schema should never be signed, because
   signing is what makes it an issued invoice
   ([`Submitter.php:80-86`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L80-L86)).
4. Put it behind `config('fatoora.validation.enforce_schema', true)` so it can be
   disabled if a schema mismatch ever blocks issuance in production.

**Watch for:** schema validation is not free (~5–20 ms per document). Fine at
current scale; note it before it is a batch of 10,000.

**Done when:** a deliberately malformed `InvoiceXmlData` produces a validation
failure naming the offending element, and a valid one passes.

---

### T3 — Resolve Q1 and Q2 · **2–3 h** *(parallelisable)* · **half already done**

> **Update:** the *pinning* half of this task landed during the audit.
> `tests/Feature/Compliance/XmlProfileTest.php` now asserts both values and
> `fatoora:validate` reads the real document rather than printing hardcoded
> ticks. **The values themselves are unchanged, so the question is still open** —
> what remains is step 1 and 2 below, not step 3.

Two constants are probably wrong ([06-risks.md](06-risks.md) R-3):

| | Current | Suspected correct |
|---|---|---|
| `CustomizationID` [`XmlBuilder.php:125`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L125) | `urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod` | `urn:sa:zatca:documents:1.0` |
| `ProfileID` [`XmlBuilder.php:130`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L130) | `clearance:1.0` / `reporting:1.0` | possibly `reporting:1.0` for **both** |

**Do:**
1. Open a ZATCA-published sample invoice — they ship with the SDK from T1, so
   this lands naturally alongside it. Read the literal values.
2. Correct `XmlBuilder.php:125,130` if they differ — and update the pinned
   constant in `XmlProfileTest.php:28` in the same commit, which is exactly the
   "deliberate act" that test exists to force.
3. ~~Write the test~~ — done. `XmlProfileTest` already guards both.

**Watch for:** if `ProfileID` turns out to be `reporting:1.0` for both types,
check whether anything *else* branches on `isSimplified()` for the same reason —
the clearance/reporting **endpoint** split at
[`Submitter.php:171-186`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L171)
is correct regardless and must not be "fixed" along with it.

**Done when:** two tests assert the literal strings, and both fail if the values
are changed.

---

## Then: T4 — the six-type validation test · **4–6 h**

Not one of the first three, but it is what closes the rung.

`OnboardingController::generateTestInvoices()`
([`:226-250`](../app/Domains/Compliance/Fatoora/Http/Controllers/OnboardingController.php#L226-L250))
already builds exactly the six documents ZATCA's compliance suite requires, with
chained ICVs, a genesis PIH, and billing references on the notes. **Point that
generator at the XSD instead of at ZATCA.** You get the entire compliance matrix
validated locally, at build time, for a fraction of the effort of writing
fixtures by hand.

That is the last exit criterion. When it passes, you are at **L2** and the
sandbox run becomes worth doing.

---

## Effort summary

| Task | Hours | Blocking? |
|---|---|---|
| T1 Vendor the XSDs | 2–4 | **Yes — blocks everything** |
| T2 Wire `schemaValidate()` | 4–6 | Yes |
| T3 Resolve Q1/Q2 (pinning already done) | 2–3 | No — start anytime |
| **First three** | **8–13 h** | |
| T4 Six-type validation test | 4–6 | Closes L2 |
| **To reach L2** | **12–19 h** | |

Two to three focused days. T1 answers Q1 and Q2 as a side effect — the SDK
download that carries the XSDs also carries ZATCA's sample invoices.

---

## Two things to do before T1, because they take minutes

Neither is on the ladder; both protect the work that is.

1. **Push.** 135 commits — effectively the entire ZATCA implementation — exist
   only on this machine, and `origin/main` is from 2026-02-03.
   `git push -u origin chore/security-remediation-and-cleanup`. *(R-11, 5 min)*
2. **Commit erp-backend's working tree.** 149 uncommitted changes including 41
   staged deletions, dormant three months. One `git checkout` from gone.
   *(R-12, ~1 h)*

## The current uncommitted tree is ready to commit

I flagged the `cleared_xml` work as untested; `ClearedDocumentTest.php` then
landed and covers it well, along with `XmlProfileTest.php` and `SecretFileTest.php`.
Suite re-run: **715 passed, 3 skipped (1589 assertions)**. R-1 is closed.

Nothing blocks committing this tree. Do it before starting T1, so the audit's
baseline and yours agree.

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
