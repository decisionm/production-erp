# Source priority — what outranks what

When answering a factory question, read sources in this order. A higher rung
outranks every rung below it. **When two rungs disagree, do not silently pick
one:** record the conflict in `PENDING-OWNER-QUESTIONS.md` and raise it.

1. **Latest explicit owner confirmation** — dated, in the owner's own words.
2. **Verified physical factory process** — what the floor demonstrably does.
3. **Exact Tally accounting evidence** — masters, Stock Journals, vouchers.
4. **The approved original factory workbook** — `product-master-rows.json`
   and the source spreadsheets in the manifest.
5. **Verified live ERP state** — what the live database actually holds
   (counted on the live instance, never assumed from dev).
6. **Current code and its automated tests.**
7. **Agent analysis, session memory, or an old transcript.**

## Two rules that are not obvious from the list

**Memory is not evidence.** Rung 7 can *suggest* where to look; it can never
*confirm* anything. A claim that exists only in an agent's session memory,
with no artifact behind it, goes to `PENDING-OWNER-QUESTIONS.md` — it does
not become a decision record.

**An owner statement can be honestly wrong about the books.** Rung 1 outranks
rung 3 only when the owner is *deciding*; when the owner is *recalling* what
the accountant does, check rung 3. Two real cases from this project:

- The owner said scrap is discarded; the Stock Journals book `Pet Scrap`
  inward in 31 of 38 vouchers. Shown the journals, the owner reversed —
  the journals were right (→ DEC-20260805-001).
- The owner twice proposed a 2.25% masterbatch standard; the July journals
  dose amber at 0.32 g on a 12.9 g bottle, which is 2.5%. 2.5% was recorded
  (→ DEC-20260806-003).

The order stands: in both cases the *final owner confirmation* — made while
looking at the evidence — is the recorded decision. The lesson is to bring
the evidence before recording, not to skip the owner.

## Validation honesty

`scripts/factory-knowledge/validate.py` proves the decision set is
*structurally* sound: ids, evidence present, supersession chains intact,
generated view fresh, every prose DEC-/FC- reference resolving, and — since
the 06-Aug migration — every record byte-identical to the tool's own
canonical serialization, which rejects hand-edits as a class
(DEC-20260806-012). It cannot detect two decisions that contradict each
other *in meaning*. That judgement belongs to whoever records the newer
decision — which is why the `record-factory-decision` skill's first step is
to read the current decisions in the same scope before writing one.
