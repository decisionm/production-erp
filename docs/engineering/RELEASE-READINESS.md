# Release readiness — Phase 8 verdict (17-Aug-2026)

## VERDICT: **NOT READY**

Not because something is broken. Every one of the five acceptance chains passes, the full
backend suite is green at **1,904 tests / 1,903 passed / 1 skipped by design / 17,806
assertions**, Pint is clean, the frontend typechecks, tests and builds, and
`inventory:check-ledger` reports clean. The verdict is NOT READY because **one link of one
chain is NOT TESTED**, and the rule this programme set for itself says that is enough,
full stop:

> Any link FAIL or NOT TESTED → `NOT READY`, full stop. A link BLOCKED by a named owner
> gate is recorded BLOCKED and the result is `PASS WITH OWNER-GATED ITEMS` — the product
> is **not called complete** while a BLOCKED link remains.

## The link that is NOT TESTED

**D-WIRING — the configuration lifecycle contract applied to the in-scope masters.**

Phase 7.6 shipped the *mechanism* and deliberately wired no entity to a route. The
independent QA confirmed it by inspection: no module declares
`ManagesConfigurationLifecycle`, and the only lifecycle-shaped route in the application
(production configuration deactivate) bypasses the shared mechanism entirely. Chain D
therefore walks the contract against `warehouses` through routes that predate Phase 7.6 —
which proves the mechanism works, and proves nothing about the 35 in-scope masters.

This independently confirms both constraints the lead set: Phase 7.6 wired no entity, and
a material configuration gap remains. Neither is overridden by every individual test file
being green.

## What the audit still shows open

From `AUDIT-CONFIGURATION-LIFECYCLE-2026-08-17.md`, unchanged by Tier 0 because Tier 0 was
the mechanism: **dependency guard 1 PASS / 22 GAP · audit trail 1 PASS / 30 GAP ·
delete-unused 4 PASS / 20 GAP.** Tier 0 closed the live-facing flag half and built the
guard; it did not close these rows.

## The two owner gates, correctly recorded BLOCKED

| Link | Gate |
|---|---|
| A6b · the CEC layout/format | No source document exists. The composition is proved equal to the Shift Summary; the FORMAT stays BLOCKED until the owner supplies a sample. Never invented. |
| B8 · the PO → Tally live write | Q35(d). The flag is off, the staging is proved, and no egress path is reachable. The first live post is attended, never unattended. |

**Even with chain D walked and green, the best achievable state is `PASS WITH OWNER-GATED
ITEMS`, not unconditional PRODUCT READY**, while those two remain.

## What would change the verdict

1. **Wire the Tier-1 masters through the contract** with real routes (Create · View · Edit ·
   Activate/Deactivate · Safe Delete · Audit) and re-walk chain D **against those
   entities** — not against `warehouses` again, which only re-proves the mechanism.
2. **Close the audit's GAP rows** for the masters the factory touches daily. This is the
   material configuration gap the lead named as a Phase 8 blocker.
3. **Run the acceptance chains on the MySQL leg.** Every chain result in this phase is
   SQLite-only; no MySQL is installed on the build machine. The CI `app-mysql` job exists
   (Phase 7) and must execute these files before "green on both drivers" is claimed.
4. **Take the browser proof.** No UI walk has been performed for any chain at any point in
   this phase's history — the Chrome extension disconnected early and never returned.
   Every PASS above is at the transaction-model / API layer. If the operator-facing screens
   are part of the acceptance bar, that work has not started.
5. Then the two owner gates above, which only the owner can lift.

## What IS proven

- **Chain A — the operator workflow**, end to end: configuration answers every Shift Floor
  question; the standard is frozen at Start under its own `calculation_version` and that
  stamp is load-bearing; Completed Today equals the completed batches and excludes
  running, cancelled and yesterday's; Shift Summary equals Σ completed production with the
  QC reduction flowing through; the CEC composition is byte-identical to the Shift Summary;
  the Tally shift voucher contains exactly the approved entries — proved by inclusion AND
  exclusion; release gate → agent ack → snapshot.
- **Chain B — accounting traceability**: PO → GRN → lot → movement → balance →
  consumption, traced backward to every GRN line, with FC-06 holding on every link in both
  halves (rates AND supplier identity, absence rather than null).
- **Chain B2 — the material flow**: the three states never collapse, proved by movement
  census rather than by a total — the store's outflows are exactly three
  `issue_to_production` rows and **zero** consumption rows, so it cannot be charged twice
  for the same material. Mutation-checked: forcing the consumption onto the store kills the
  chain twice over. An open issue reads as **no drift** in the reconciliation.
- **Chain C — sales visibility and downloads**: documents traced to their Tally rows, the
  Layer-B honesty statement stated plainly, one export per kind, FC-06 on every file, and
  the CEC slot catalogued BLOCKED with its reason rather than producing a file.
- **Chain D — the mechanism**: a referenced master's delete refused with integer counts and
  its cascade-side children surviving; a genuinely unused record really deleted and its code
  re-released; the fail-closed verdicts; the schema backstop refusing an incomplete
  declaration.

## Release-readiness checklist

| Item | State |
|---|---|
| No P0 | ✅ none open |
| No unresolved P1 data-integrity issue | ✅ every P1 raised by a gate was fixed and re-gated |
| Migrations accounted for and reversible | ✅ each phase's listed in DEPLOYMENT-RUNBOOK |
| All suites green | ✅ 1,903 passed / 1 skipped by design |
| Production build passes | ✅ |
| Browser smoke | ❌ **not performed** — extension unavailable |
| Sync dry-run | ✅ reconciliation dry run exercised in chain B2 |
| Rollback documented | ✅ DEPLOYMENT-RUNBOOK |
| MySQL leg on the acceptance chains | ❌ **not run** |
| `DEVELOPMENT-PLAN.md` current or superseded | ⚠️ superseded in practice by MASTER-PLAN rev 3; say so explicitly before release |

Nothing in this programme has been merged. Live remains at `9a9cbe3`.
