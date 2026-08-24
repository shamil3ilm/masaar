# 08 — The Next Rung Only

**Current: L1** (fully satisfied)
**Target: L2** — *UBL 2.1 XML generated and validating against the ZATCA XSD*

This document covers **that rung and nothing else.** No roadmap to L3–L5. Those
rungs are already largely built ([01-summary.md](01-summary.md)) — they are
blocked on verification, not construction, and verification starts here.

---

## Exit criteria for L2

- [ ] A ZATCA SDK available locally, with a Java runtime
- [ ] `ZATCA_SDK_PATH` set so `ZatcaConformanceTest` runs instead of skipping
- [ ] All twelve conformance cases **pass** — UBL 2.1 schema, EN16931, ZATCA
      Schematron, PIH chain — across standard/simplified × invoice/credit/debit
- [ ] `CustomizationID` confirmed against the SDK's sample invoices
- [ ] The run reproducible: the SDK path documented in `.env.example` and CI

Deliberately **not** in scope: a live sandbox call, CCSID/PCSID onboarding, the
six-document compliance submission. Those are L4 and they come after this.

---

## The shape of the work has changed

An earlier plan here was to vendor the XSDs and wire `schemaValidate()` by hand.
**That work is largely unnecessary now.** `ZatcaConformanceTest` and
`ZatcaSdk.php` already drive ZATCA's own SDK, which carries the schema, the
EN16931 rules and the Schematron together — a stricter oracle than the XSD
alone, and the one the authority actually uses.

What remains is supplying the SDK and reading what it says.

---

## Dependency order

```
T1  Obtain the SDK, set ZATCA_SDK_PATH
     │
     ▼
T2  Run the suite; fix what it reports      T3  Settle CustomizationID
     │                                       (independent — start anytime,
     ▼                                        though T1 answers it for free)
T4  Make the run reproducible  →  L2
```

---

## The first three tasks

### T1 — Obtain the SDK and point the harness at it · **1–3 h**

The single highest-leverage action available. Everything else waits on it.

**Do:**
1. Download the ZATCA E-Invoicing SDK from the Fatoora portal.
2. Confirm a Java runtime is on `PATH` — `ZatcaSdk.php:47` skips without one.
3. Set `ZATCA_SDK_PATH` to the directory holding `Apps/` and `Data/`; the
   fixture looks for a jar beneath it (`:43`).
4. Run the suite and confirm the twelve cases execute rather than skip.

**Watch for:** the SDK is licensed and must not be committed — that is exactly
why the harness takes a path. Keep it outside the repository.

**Done when:** `artisan test` reports fewer than 15 skips, and the conformance
cases show a result either way.

---

### T2 — Fix what the validator reports · **4–12 h** *(genuinely unknown)*

This is the only estimate here that could be badly wrong, and it should be read
as a range rather than a number. The first real conformance run on a system that
has never had one typically surfaces several findings at once.

`ProfileID` is the precedent: a single wrong constant that no internal test
could have caught, because the repository did not contain the knowledge. Expect
more of that shape — namespace declarations, element ordering, the transform set
used for the invoice digest, `SigningCertificate` digest form.

**Do:** run, read the validator output, fix, re-run. Each fix should land with a
test that pins the corrected value, in the manner of `XmlProfileTest`.

---

### T3 — Settle `CustomizationID` · **2–3 h** *(parallelisable)*

`XmlProfileTest` already pins both spec constants, so neither drifts unnoticed.
Pinning is not verification: `CustomizationID` remains unchecked against the
specification ([06-risks.md](06-risks.md) R-3 Q1).

[`XmlBuilder.php:125`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L125)
emits `urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod`, a generic
OASIS string. ASSUMPTION: ZATCA expects `urn:sa:zatca:documents:1.0`.

**Do:** read the literal value in the SDK's sample invoices, correct
`XmlBuilder` if it differs, and update the pinned constant at
`XmlProfileTest:33` in the same commit — which is the deliberate act that test
exists to force.

**Note:** T1 answers this for free. If you are doing T1 anyway, fold this in.

---

## Then: T4 — make the run reproducible · **2–4 h**

A conformance run that only works on one machine is not a gate. Document
`ZATCA_SDK_PATH` in `.env.example`, and decide how CI gets the SDK — a cached
artifact, a self-hosted runner, or an accepted "conformance runs locally before
release" policy. Any of the three is defensible; leaving it undecided is not.

That is the last exit criterion. When the twelve cases pass and the run is
repeatable, you are at **L2**.

---

## Effort summary

| Task | Hours | Blocking? |
|---|---|---|
| T1 Obtain SDK, set `ZATCA_SDK_PATH` | 1–3 | **Yes — blocks everything** |
| T2 Fix what the validator reports | 4–12 | Yes |
| T3 Settle `CustomizationID` | 2–3 | No — T1 answers it for free |
| T4 Make the run reproducible | 2–4 | Closes L2 |
| **To reach L2** | **9–22 h** | |

Call it two to four days. The spread is real and it lives almost entirely in T2:
nobody knows what the first conformance run reports until it runs. T1 alone is
an afternoon, and it converts the largest unknown in this audit into a list.

---

## Baseline

`main`, clean tree, **715 passed / 15 skipped (1640 assertions)** on PHP 8.4.12.
Twelve of those skips are the conformance suite this document exists to switch
on.

⚠️ The default `php` on this machine is **8.2.28**; `composer.json` requires
`^8.4`, so `php artisan test` aborts in `platform_check.php` **and exits 0** — a
failure that reports success. Use
`C:\laragonin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe`.

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
