# Release readiness — Phase 8 verdict (rev 2, 18-Aug-2026)

> **Rev 2 supersedes the 17-Aug verdict below in three places.** D-WIRING has landed, the
> suite has been run on MySQL 8.0, and a browser walk has been taken. The three items that
> were open for engineering reasons are closed; what remains open is owner-gated or
> explicitly recorded as a gap. **The ceiling remains `PASS WITH OWNER-GATED ITEMS` — never
> unconditional PRODUCT READY — while the CEC format and the first PO→Tally live write
> stand.** See "Rev 2 — what changed" at the end.

## VERDICT (17-Aug, superseded in part): **NOT READY**

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

1. ~~**Wire the Tier-1 masters through the contract**~~ — **DONE** (`55ab682`). Eleven
   masters wired: Warehouse, Item, WorkCenter, Mold, Shift, ScrapReason, DowntimeReason,
   ProductionStandard, ProductionStandardPackaging, ProductionConfiguration, Employee, plus
   the `configuration-delete` permission tier. Chain D re-walked through those entities'
   OWN routes.
2. **Close the audit's GAP rows** for the masters the factory touches daily. This is the
   material configuration gap the lead named as a Phase 8 blocker.
3. ~~**Run the acceptance chains on the MySQL leg.**~~ — **DONE.** A MySQL 8.0 container was
   stood up locally (matching CI's service image) and the WHOLE backend suite run against
   it: **one error**, and that is the part worth keeping. The count from that run is deliberately
   NOT quoted: it was measured while a reviewer's 78-test scratch file was in the tree
   mid-edit, so it is not reproducible by anyone. The authoritative MySQL evidence is CI's
   own **`Backend tests on MySQL 8` leg, which passed on `95faab3` in 7m29s**. The single error was
   in the new D-WIRING work and was a real driver divergence — `MoldLifecycleTest` seeded a
   bare `'08:00'` into a `dateTime` column, which sqlite stores and MySQL rejects. Fixed in
   `fa35b83`; 13/13 on both drivers afterwards. This is the third time the MySQL leg has
   caught something sqlite hid, and the first time it caught it *before* CI did.
4. ~~**Take the browser proof.**~~ — **DONE, with a stated method limit.** Twenty screens
   walked against the phase-8 build served by Laravel on its own port (the production
   topology). Full record: `E2E-BROWSER-WALK-2026-08-18.md`. It is genuine browser evidence
   — real React handlers, real requests, real server responses — but it is **not
   mouse-level** evidence: the driven tab is a background tab, so Ant Design's modal
   animations are throttled and interactions had to be dispatched in page context. Overlay
   and z-index defects therefore remain UNTESTED.
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
| All suites green | ✅ at the branch tip `95faab3`: **2,051 tests / 2,050 passed / 1 skipped by design / 18,668 assertions** on sqlite, and **CI's `Backend tests on MySQL 8` leg PASSED on that same commit** (7m29s). Configuration + Acceptance also run locally on MySQL 8.0 after the fixes: 268/268 |
| Production build passes | ✅ |
| Browser smoke | ⚠️ **performed** (20 screens, `E2E-BROWSER-WALK-2026-08-18.md`) — genuine but **not mouse-level**; overlay/z-index class untested |
| Sync dry-run | ✅ reconciliation dry run exercised in chain B2 |
| Rollback documented | ✅ DEPLOYMENT-RUNBOOK |
| MySQL leg on the acceptance chains | ✅ whole suite on MySQL 8.0 — 2,111 passed / 1 skipped; the 1 error it found is fixed (`fa35b83`) |
| `DEVELOPMENT-PLAN.md` current or superseded | ⚠️ superseded in practice by MASTER-PLAN rev 3; say so explicitly before release |

**DEPLOYED 18-Aug-2026 07:44 IST.** All fifteen PRs (#179–#193) merged in dependency order;
live is now **`b92f04d`**, whose tree is byte-identical to the commit CI validated. One
deploy window of **15 seconds** (the `deploy-production` concurrency group cancelled seven
superseded runs). 16 migrations DONE after an automatic database backup. Verified from
evidence: the migrate step's own output · the site up (200/200/401, new routes 401 not 404)
· the Tally queue empty (0 pending, 0 failed — so the issue-#168 signature cannot exist) ·
no server-log error newer than the deploy. Full record in `PHASE-LOG.md`.

The two owner gates are UNCHANGED by the deploy: the CEC format is still BLOCKED, and the
first PO → Tally live write still has not happened (flag off, Q35(d)). The verdict therefore
remains **PASS WITH OWNER-GATED ITEMS** — deployed is not the same as unconditionally ready.


---

## Rev 2 — what changed on the night of 17→18 Aug

### Closed
- **D-WIRING** (`55ab682`) — the NOT TESTED link. Eleven masters, chain D re-walked through
  their own routes, and **proved in a browser**: deleting `RM Store` is refused with
  `Cannot delete warehouse "RM Store" — used by 3 stock balances, 3 stock movements, 2
  material bags and 1 Tally godown identity. Deactivate instead.`, itemised with counts;
  while a genuinely unused warehouse created through the UI was really hard-deleted (row
  gone from the table, `deleted_at` not set).
- **The MySQL leg** — see item 3 above.
- **The browser walk** — see item 4 above.

### Found and fixed
- **The local fixture granted the floor three tiers live withholds** (`7e37261`). The
  acceptance fixture gave its supervisor desk every permission except `carton-trace.*`,
  which meant it held `finance.view` — and `AgentIdentity::mayReadPurchaseDetails()` opens
  **FC-06** to exactly that. A supervisor login read a Procurement Receipt Note's supplier
  in the clear. The product gate was never broken; the fixture made every manual FC-06
  walkthrough pass for the wrong reason. Now withheld and pinned by a test that asserts
  through the product's own predicates rather than permission names.

### Recorded as GAPS — deliberately not fixed unattended
- **Duplicate business codes are not normalized in code.** All 17+ masters use a bare
  `unique:<table>,<column>`; there is no shared normalized-unique rule. Measured on both
  drivers: MySQL (live) **catches** a case-variant duplicate, sqlite does **not**; neither
  catches leading/trailing whitespace. So the contract's "normalized comparison" holds on
  live only by accident of collation. **Not fixed tonight on purpose:** a case-insensitive
  unique rule would begin refusing EDITS to any live master pair that already differs only
  by case, making those records uneditable, and whether live holds such pairs is a
  read-only query nobody has run. The separate "warn on likely duplicate NAMES" requirement
  is **UNTESTED**, not passing.
- **The Day Bin surface outlives the decision.** `/production/day-bin` and the dashboard's
  `DAY BIN` tile still exist, and `UpdateDayBinWarehouseRequest` plus the day-bin setting
  are still wired — so per the standing instruction, it IS still relied upon and was not
  blindly deleted. But the copy contradicts DEC-20260817-001 ("There is no Day Bin") and
  should not reach the floor in that state.
- **The mould master was not exercised in the browser** — the fixture seeds no moulds. Its
  backend lifecycle is covered by `MoldLifecycleTest` on both drivers.
- **Duplicate-posting / retry was not exercised in the browser** — the fixture contains no
  failed or retryable Tally entry to retry. Covered by the backend contract suite only.

### The gate on the D-WIRING delta

Two independent reviewers, both on the delta rather than on the whole branch.

**Sonnet independent QA — PASS WITH DEFERRED.** Wrote its own route-level tests for all
eleven masters, deliberately sharing no harness with the builder's, and reproduced every
claim: referenced-delete refused with an integer `blocking[].count` and
`alternative: 'archive'`; unused-delete succeeding and freeing the business code;
archive→activate round-tripping (including the two BackedEnum-status masters and the two
with no active flag at all, whose archive is a real soft delete); the audit row actually
written to `activity_log` with the right subject and causer; and the hard-delete tier
refusing a module manager who lacks it while still allowing archive — checked across BOTH
permission-check implementations and across WorkCenter's split permission groups.

**Opus adversarial safety review — FAIL, now fixed.** Migrated all 175 migrations into a
scratch database and diffed all 277 foreign keys against what the eleven services declare.
Its headline negative is worth recording: **zero uncovered foreign-key columns** across all
91 inbound FK columns, for every delete rule, not just CASCADE. The hole it found was one
level down — whether the covering check can SEE a soft-deleted child — and it is fixed in
`3df98bb`, together with the structural gap that let it through.

Its remaining P2s are recorded, not fixed: two authority classes name the same permission
string (`HardDeleteAuthority` and `ConfigurationDeleteTier`) and should be collapsed now
that the catalogue entry has landed; `activity_log` rows are orphaned by a hard delete
(nothing declares them, and id reuse is theoretically possible); the TOCTOU lock closes the
race for every FK-constrained child but not for the settings keys, the colour map, the
config snapshot or the Tally link; and six masters bind implicitly, so a trashed row 404s
where the mechanism documents that it should be found.

### P1 — Archive does not yet stop NEW work for Item and WorkCenter

`StartBatchRequest` scopes `shift_id` and `warehouse_id` with
`Rule::exists(...)->where('is_active', true)` but leaves `work_center_id` and `item_id` as
bare `exists:`. The readiness gate that would otherwise catch it ships in watch-only mode —
`PROD_READINESS_ENFORCED` defaults to false — so `item_active` and `machine_active` are
downgraded from blocking findings to warnings. A batch naming an archived item or a retired
machine is therefore ACCEPTED today.

**Severity is bounded by the picker, and this is the sentence that matters:** the Shift
Floor calls `listWorkCenters(true)`, so a supervisor cannot select a retired machine from
the dropdown. The gap is reachable by direct API call, or by a client holding a stale list —
not from the screen.

**Deliberately not fixed unattended.** Adding `is_active` scoping to those two rules would
begin REFUSING batch starts, and refusing a batch start stops the floor; there is a night
shift on it as this is written, and whether live holds an inactive machine or item that is
still in use is a read-only query nobody has run. It is also not really a new defect: making
archived-exclusion bite is the **readiness-gate rollout decision from 29 July**, which is the
owner's call and is already recorded as open. The two-line fix is named above for whenever
that call is made.

Until then, the Archive confirmation's wording — "stops being offered for new work" — is
accurate for the other nine masters and **aspirational for Item and WorkCenter**.

---

# Re-gate of PR #196 — what two independent reviewers broke, 18 Aug 2026

The branch was green: 2,092 backend tests, 530 frontend, all four CI legs including
MySQL. Two reviewers were then asked to **reproduce the earlier exploits rather than read
the new code**. They found five things the green suite did not, and the two worst were in
the surfaces this PR was written to close.

## P0 — the unit guards were unreachable on the one path the store actually uses

`StoreStoreIssueRequest::withValidator()` checked `material_request_line_id` first, and
**every arm of that branch ends in `continue`**. The fraction and unit guards sat below it.
So they fired only on a fresh handover — the verbal-ask path — and never on a line that
names the request line it fulfils, which is what the Store Issue Queue posts on every time.

Reproduced, independently, by both reviewers:

| probe | before | after |
|---|---|---|
| `Nos.` item, `quantity: "12.5"`, against a real accepted line | **201**, 12.5 trays in Production/WIP | **422** |
| `Nos.` item, `uom: "Kgs."`, against a real accepted line | **201**, `'Kgs.'` persisted and read back in place of the item's | **422** |

The second is precisely the FC-03 failure the UOM audit claimed closed — "a tape figure in
metres filed as Nos is a different number about a different thing, and that reached live
once". The store's own `InputNumber` carried no `precision`, so 12.5 was typable.

**The lesson is about the test, not the code.** `TwoUnitsChainTest` passed throughout,
because every assertion in it posted without a request line id. A guard is only proven on
the paths the tests actually walk, and the path they skipped was the only one in daily use.

**Fixed** by hoisting the unit block above the branch — and *only* the unit block.
Eligibility stays below it, deliberately: whether a material may be asked for was settled
when the ask was accepted, so history stays issuable. What unit a handover is in was never
settled by anything. Proved by mutation: restoring the old ordering fails exactly the new
test, with exactly the old 201.

## P1 — the store could read AND CANCEL production's unsent working paper

The `submitted_at` closure went into `queue()` only. `show` and `cancel` are route-model-
bound in the group both desks read, and neither asked. Request numbers are sequential.

- store-only login → `GET /material-requests/{draft_id}` → **200**, full body
- store-only login → `POST /material-requests/{draft_id}/cancel` → **200**, *cancelled*

`cancel`'s only guard is the lifecycle one (`! isFinal()`), and a draft is not final. The
write is the worse half: production's paper could be torn up before the floor ever sent it.

**Fixed** with one shared gate on both actions, using the same permission constant as the
queue, answering **404 rather than 403** — a 403 confirms the row exists, which is the thing
being kept private.

## P2 — a ghost header, and a ghost scan pointer

`material_request_id` carried no `exists`. An issue could be filed against request
`999999999`: stock moved and the pointer persisted, resolvable by nothing, for ever. The
bag-scan endpoint had the identical hole on `material_request_line_id`. Both now
`exists`-checked. Two test fixtures were themselves passing magic numbers (`77`, `512`) and
now build a real accepted ask.

## P2 — Esc discarded the scans the dialog existed to protect

On the "unfinished receipt" prompt, the destructive choice is the *cancel* role, and antd
routes Esc and a mask click straight to `onCancel` → `clearAllDrafts()`. The most reflexive
keystroke on a modal silently destroyed every saved supplier barcode. `keyboard: false`,
`maskClosable: false`. Discarding is a choice you make, not one you fall into.

## P3 — fixed

`quantity: "1e3"` passed `numeric` and reached bcmath, which answered a **500**; now a 422.
And a test of mine was dead: it compared a `MaterialRequestStatus` enum instance `===` the
string `'draft'`, which is never true, so the fixture it claimed to fix never once did the
thing it described. That file is about filter mechanics and now holds no draft at all.

## P3 — known, bounded, NOT fixed

- **`StoreIssueService::issue()` has no unit guard of its own.** A direct PHP call persisted
  `3.25` and `'Litres'` on a `Nos.` item. There is exactly one HTTP write path into store
  issues and it goes through the FormRequest, so this is reachable only by writing new
  server code — defence in depth, not an open door. Left alone rather than changed on the
  eve of a deploy.
- **The GRN draft-restore machinery has no test.** `vite.config.ts` declares no DOM
  environment, so `restoringDraft`, `pendingDraftKey()` and `loadDraft()` are unreachable
  from the suite; only the pure `lotScanState` is covered. Verified by inspection only.
- **The pre-existing kg-detecting copies elsewhere in the frontend are untouched** (class 3
  in the UOM audit). `permitsFractions()` in `material-flow/words.ts` is the canonical one
  to converge on when that is scoped.

## What held up

Everything else the reviewers attacked: the bogus request-line bypass, the cancelled-request
exemption, the header/line mismatch, the queue's own draft filter across every filter,
paging, search and flag combination, partial-GRN scan discard, the timezone stamp, negative
WIP visibility, the meta plumbing, `MeasurementType` itself, and — proved by mutation —
`TwoUnitsChainTest` being non-vacuous. FC-01, FC-03 and FC-06 all hold on the delta.

## The verification round, and what it found in the fixes themselves

Both reviewers re-ran their own successful probes against the fix commit. All six are
refused, each re-probed rather than re-read, and the central fix re-proved by mutation:
restoring the old ordering fails exactly one test with exactly the old 201. All ten
surrounding workflows still complete — the resin chain end to end, partial and repeat
issues, return to store, all three cancellation paths, blank-uom decimals — verified under
a genuinely store-only login rather than a combined-permission one, which is the only
identity a permission-shaped refusal shows up for.

Four things in the fixes did not survive, and they are worth naming because three of them
were made BY the fixes:

- **The `exists` rules checked existence but not lifecycle.** The store could head an issue
  with production's unsubmitted draft, and a scan on a headerless issue could name any real
  request line. No stock or document harm — the foreign pointer has no reader, and
  `remainingForRequestLines` has zero call sites — but **201-vs-422 is an existence oracle
  for draft ids, which is exactly what the 404 on show/cancel was added to deny.** A fix
  that leaks through a side channel is not finished. Both rules now carry the lifecycle,
  and the scan's line must belong to the issue's own request.
- **The hoist widened exactly one refusal.** An item whose master carries no unit refused
  any caller-supplied one, with a message reading "This material is kept in ." — an
  unusable sentence on a floor screen, and newly reachable on the accepted-ask path
  *because* of the hoist. A blank unit has nothing to disagree with.
- **The quantity rule over-narrowed.** Closing the `1e3` 500 also started refusing `.5`,
  `1.` and `+5`, all of which bcmath accepts and the old code took happily. The rule is now
  exactly what bcmath accepts. Widening a refusal by accident is the failure mode here.
- **The browser mirror disagreed with the server** on `piece.`, `pieces.`, `each.` and
  `ea.` — and in the dangerous direction, the UI refusing what the server permits. Mirrored
  verbatim instead of normalising its own way.

One trap worth recording on its own: `Rule::exists(...)->where($column, $op, $value)` takes
**two** arguments and drops the third in silence. The draft half of the lifecycle rule
worked while the cancelled half did nothing at all. It was caught only because the test
asserted both halves separately — a rule that looks right and half-fails is what a
single-assertion test would have shipped.

Left alone, deliberately: an accepted ask for a **soft-deleted** item cannot be fulfilled.
That contradicts "history stays issuable" for the delete case, but it is a deliberate
earlier decision in this series, hard delete is Super Admin/Owner only (DEC-20260817-002
§3) so the desks that raise and fulfil asks cannot cause it, and the floor can still cancel
a stranded ask. Reversing a deliberate call unattended is not engineering's to make.

Also still true and still not fixed: no service-layer unit guard (one HTTP write path, all
through the FormRequest), and no DOM in the frontend suite, so the GRN draft-restore
machinery is covered by inspection only.

## The third round — a fix that reopened the defect it was fixing

The follow-up commit was verified against the same standard and produced one **P1**, found
by probe rather than by reading:

**The quantity rule was widened; the private guard it feeds was not.** Closing the `1e3`
500 had narrowed the rule, and un-narrowing it admitted `.5`, `1.` and `+5` — while
`isPlainDecimal()`, which gates the whole-number check for counted materials, kept the
narrower spelling. So `+12.5`, `.5` and `+.5` cleared the rule, failed to match the guard's
own pattern, and **skipped the fraction check entirely**: 26 fractional trays reached
Production/WIP with a 201, on both the fresh-handover and the accepted-ask path. The exact
defect the previous commit was written to close, reopened through a spelling.

Two things about it are worth more than the fix:

- **Two copies of one predicate, for the third time in this branch.** `MeasurementType`
  exists because four call sites asked one question three ways; the accepted-ask branch
  broke because eligibility and units were entangled; and this broke because a regex was
  written down twice and only one copy was updated. It is now a single `PLAIN_DECIMAL`
  constant used by both.
- **The new test passed for the wrong reason.** `test_the_quantity_rule_refuses_only_what_
  bcmath_refuses` asserted against a `Kgs.` item — a WEIGHT item, which permits fractions —
  so it returned 201 no matter what the counted-material guard did. A test that cannot fail
  for the reason it names is not covering that reason. The replacement drives seven
  spellings of a fraction through a `Nos.` item on both paths, asserts no tray moved, and
  then asserts `+12` still succeeds; the divergence is pinned by mutation.

Also fixed: the browser mirror trimmed the way JS trims and the server the way PHP trims, so
` nos.` was refused in the browser and permitted by the server — the dangerous
direction again, now normalised to PHP's exact trim set.

Everything else in that commit verified clean, each state actually posted rather than
reasoned about: **`partially_issued` and `issued` can still head an issue, and a second
issue against a part-fulfilled ask still succeeds** — the case where a wrong tightening
would have stopped the factory. Draft- and cancelled-headed issues are refused with a body
byte-identical to the one a nonexistent id returns, so the existence oracle is closed
rather than merely narrowed. A full resin chain walked end to end: RM Store 100 → 40,
Production/WIP 0 → 60, three transfer pairs, **zero consumption rows**. FC-01, FC-03 and
FC-06 all hold.

## The fourth round — a P0 that three rounds of testing could not see

The final gate returned one PASS and one **FAIL**. The blocking finding was not in any of
the logic the previous rounds had been attacking:

**The floor's Material Requests page was dead.** `include_unsubmitted` is validated with
Laravel's `boolean` rule, which accepts `1`, `0`, `"1"` and `"0"` — and **not** `"true"`,
which is exactly what axios puts on the wire for a JS `true`, and exactly what the page was
sending. So the floor asked for its own drafts and got a 422. No error surfaced (the only
axios interceptor handles 401), the table rendered "No data", and because **Submit is a row
action on that table**, a request raised through the still-working modal could never be
sent to the store at all. Work stoppage, not a display bug.

**Why three verification rounds missed it, and this is the part worth keeping:** every
backend test built its query with `http_build_query()`, which encodes PHP `true` as `"1"` —
the one spelling that worked. The tests could not send what the browser sends, so they
could not see what the browser sees. The regression test now writes the query string by
hand and asserts `true`, `TRUE`, `1`, `false` and `0` all behave; mutation-proved against
the exact 422 the reviewer reported.

**And the repo already knew.** `frontend/src/features/production/api.ts` carries a docblock
stating this trap verbatim, ending "Typing the literal makes that mistake a compile error
instead of a blank page" — and the new call site typed the flag `boolean` anyway. Both
halves are fixed: the server coerces (the API is a product surface, and a flag that means
the same thing spelled two ways should not answer one spelling with a dead page), and the
type is now the literal `1`, so the next call site gets a compile error.

### The predicate, written down once, at last

The same drift caused four separate defects on this branch — four call sites classifying a
unit three ways, a rule and its guard spelled differently, a flag validated one way and read
another. `PlainDecimal` is now a single rule class used by **all four quantity doors**:
material request, store issue, return, and bag scan. Consolidating it closed three more
findings at once:

- **P1, the third door.** A fractional return of a counted material succeeded — 484.5 trays
  in the store and 15.5 on the floor simultaneously. Pre-existing and unchanged from main,
  so not a regression; closed because a unit contract true of two doors out of three is not
  a contract.
- **P2.** `1e3` on the material-request side was a **500**, not a 422 — `is_numeric('1e3')`
  is true and `bccomp()` is not. It fired only for counted items, because a weight item
  short-circuits before reaching it. Same defect on the return and bag-scan doors,
  pre-existing.
- **P3.** The request-LINE id was still an existence oracle: a nonexistent line and a real
  line belonging to someone else's request answered differently. The header oracle had been
  closed to byte-identity; this one had not. Both now answer the same body.

Everything the adversarial half checked in the logic held: all six of the owner's required
proofs PROVEN with balances before and after (34 fractional spellings × 2 paths, 14 invalid
id shapes, 26 draft-visibility combinations), `PLAIN_DECIMAL` confirmed the only definition
by mutation, zero 500s across an aggressive sweep, 109 strings agreeing between the browser
mirror and the server classifier with no disagreement in either direction, and a full resin
chain with **zero consumption rows**. FC-01, FC-03, FC-06 hold.

## The fifth round — the gate that could be told apart

One PASS, one FAIL again. The blocking finding defeated a fix this branch had already made,
for the third time in the series, and by the same mechanism each time: **a refusal that
answers differently is a disclosure.**

**The draft-privacy gate was defeated by validation ordering.** The `abort_if` sat in the
CONTROLLER, which runs after the FormRequest. So `POST /material-requests/{id}/cancel` with
a too-short reason answered **422** for a draft that exists and **404** for an id that does
not — and a store-only login could walk the id space with one ordinary request, no crafted
payload and no side effects. The gate's own comment said "404 rather than 403: a 403
confirms the row is there, which is itself the thing being kept private"; a 422 confirms it
just as well.

It is **middleware** now (`EnsureDraftIsProductionsOwn`), because middleware is the only
place that runs before everything else that could answer differently — and it throws the
same `ModelNotFoundException` route-model binding throws, so the body is indistinguishable
too, not merely the status. Mutation-proved: disarming it reproduces exactly the 422.

### The audit that should have run four rounds earlier

Round four's lesson was made this round's FIRST task: **compare what the frontend actually
puts on the wire against the rule that receives it**, with every query string written by
hand. The material-flow surface came back clean — `include_unsubmitted` across 23 spellings
and two logins, `status[]` arrays, cleared antd filters (which axios drops entirely), dates,
JSON nulls. What it found instead were the doors nobody had validated at all:

- **`GET /inventory/store-issues` had no FormRequest.** `$request->string('status')` on raw
  input is a TypeError, so `?status[]=issued`, `?issued_from[]=x` and `?per_page=-5` were
  **500s**; `?status=banana` was silently ignored and `per_page` was uncapped. Two request
  classes now, strict about shape and permissive about meaning: nothing a working screen
  sends changes behaviour.
- **The material-lot door 500s on `1e3`** in three fields (`bcmul`, `bcadd`). Pre-existing.
- **`quantity_requested` sat three lines above the rule that converged** and kept the old
  spelling, so `1e400` reached the decimal cast as a 500. The same predicate, drifting for
  the fourth time — which is the argument for `PlainDecimal` being a class rather than a
  constant, and it now guards every quantity field in the module.
- **The bag-scan door was still an existence oracle.** `Rule::exists` fired IN ADDITION to
  the ownership check, so a nonexistent line produced two errors and a foreign one produced
  one. The issue door had been collapsed to a single body; this one had been left telling
  them apart. One body for all three cases now.

Also: the return modal's quantity input had no precision guard while the issue side's did,
so a storekeeper could type half a tray back and learn about it only from the server.

Everything else held. All six of the owner's proofs PROVEN again with balances before and
after — 16 fractional posts across both paths, 12 invalid-id shapes across five doors with
movements 2→2 and bag scans 0→0, 23 flag spellings, partial→full walking
`submitted → partially_issued → issued`. Browser/server unit mirror: 77 strings, zero
disagreements. Resin chain: `{receipt: 2, transfer_in: 2, transfer_out: 2}`, **consumption
rows: 0**. FC-01, FC-03, FC-06 hold.

## The sixth round — two doors out of three is no doors

Both halves found the same hole independently, which is the strongest signal in the series.

**`POST /material-requests/{id}/submit` was the third route carrying a `{material_request}`,
and leaving it out of the gate defeated the gate.** Laravel runs `SubstituteBindings` ahead
of the unprioritised `module` alias, so route-model binding answered **404 for an id that
does not exist** while the permission check answered **403 for one that does**. A store-only
login enumerated production's unsubmitted drafts with one ordinary POST per id — the same
bit, about the same rows, at the same cost as round five's finding.

The obvious fix does not work, and that is worth recording: appending `own-draft` to a route
inside `module:production` never runs, because the group aborts first. `submit` now sits in
the group both desks may enter, with `own-draft` first and the production-only check after,
so a 403 is reached only for a request the caller already sees in their own queue.
Mutation-proved both ways.

**Seven quantity fields still reached bcmath with `numeric` alone — and one is reachable
from a live screen.** The three stock-movement doors, purchase requisitions and purchase
orders. `1e+21` is what `JSON.stringify` emits for any JavaScript number at or above 1e21,
and the stock page posts straight from an antd `InputNumber`, so a storekeeper holding a key
down reaches the exponent spelling without ever typing an `e`. Probed: `1e+21` was a **500**
on the receipt door and a clean 422 on the `PlainDecimal`-guarded one.

That is the predicate's **fifth** drift on this branch. `PlainDecimal` has moved to
`App\Rules` (it now spans two modules) and `EveryQuantityDoorRefusesAMalformedNumberTest`
names the **doors** rather than the rules, so a new door added without it fails there
instead of in production. Its mutant reproduces the reviewer's exact 500s — and a purchase
order silently ACCEPTING `1e3` as a 201.

**The return guard read the wrong unit.** The modal reads `line.uom`, the unit the handover
was recorded in; the server read `items.uom`, the master's value now. An item's unit is
editable, so after an edit the two disagreed in both directions — including the one this
branch itself named dangerous, the browser blocking a figure the server would take.

### Recorded, not fixed

`SubstituteBindings` beating `EnsureModulePermission` is **repo-wide**: every `module:`-gated
route with a bound model is an existence oracle for any authenticated user (`GET
/inventory/items/1` → 403, `/999999` → 404). The root fix is a global middleware-priority
change, which is not a thing to do unattended on a deploy eve. The `submit` instance was in
scope because **this branch declared drafts private**; nobody has declared item ids private,
so the general case is a separate, owner-visible decision.

Also recorded: a boolean `material_request_id` coerces to `1` (framework-standard for every
id field in the codebase, no screen sends one).

Everything else held. Sixteen probe shapes × {draft, ghost} on `show`/`cancel` — including
HEAD, OPTIONS, form-encoded, malformed JSON, and thirteen odd id spellings — **every pair
identical in status and body**, with the gate failing closed when the permission rows are
absent entirely. Read doors: 41 malformed queries, zero 500s. Bag-scan oracle: seven cases,
one body. Unit mirror: 75 strings, zero disagreements. All six owner proofs re-proven with
balances; 94 invalid-id posts across seven doors left movements 2→2, issues 0→0, bag scans
0→0. Resin chain `{receipt: 1, transfer_in: 2, transfer_out: 2}`, **consumption rows: 0**.
FC-01, FC-03, FC-06 hold.

## The seventh round — the sibling three lines below

Two P1s, both caused by the previous round's fixes. The pattern is now the whole story of
this branch: **one idea written down twice, and only one copy updated.**

**A field, not a door.** `PlainDecimal` went onto two fields of `StorePurchaseOrderRequest`
and missed `lines.*.schedules.*.quantity` three lines below — which is the one that reaches
`bcadd`. It answered `1e3` / `1e+21` / `1e400` with a **500**, and its amend twin (posted by
the same modal) silently **accepted** `1e+21` as a **200**, storing a number nobody typed.
Both reachable from a bare antd `InputNumber` on the live Purchase Orders page — the exact
vector the previous round used to justify a P1. The previous commit's own claim that its
test "names the DOORS rather than the rules, so a new door added without it fails there
instead of in production" was **false as written**: it named doors, and the leak was a field
on a door it already named.

**So the class was measured instead of chased.** A sweep of every FormRequest in the
application found **114 unguarded `numeric` fields** across CRM, Compliance, Finance, HRMS,
Payroll, Quality, Sales, Maintenance, Inventory, Procurement, Production and TallySync. Not
all reach bcmath, but the ones that do answer a 500 or store an exponent.

Fixed here: the **13 fields in this workflow's own chain** — purchase order schedules, the
three amend fields, six goods-receipt fields, MRP net requirements, the material cost
version rate, and the day-bin bag load. `NoDoorInThisWorkflowTakesAnUnguardedNumberTest`
reads the **rule arrays themselves** across those thirteen request classes, so a field added
without a guard fails there; mutation-proved to name the exact field this round found.

**Deliberately NOT fixed: the other ~100.** Putting a stricter rule on payroll, quality and
CRM doors nobody has exercised, on the eve of a deploy, risks refusing work the factory does
today — and this branch has already learned twice that a wrong tightening is worse than the
500 it replaces. The full inventory is above; it is a named, sized follow-up, not a
hand-wave.

**The return guard's unit source was swapped, not closed.** The first attempt read
`items.uom`, the second read `store_issue_lines.uom`. Each closed the hole facing one way
and opened it facing the other, because the two can disagree — and not only through a human
edit: `ItemService::upsertFromTally` overwrites `items.uom` from Tally's `BASEUNITS` on
every masters pull, **unattended**. Now BOTH are consulted and a fraction is refused if
either reading says the material is counted.

With one deliberate exception, which matters more than the rule: **the entire outstanding
quantity may always come back.** A fractional quantity already standing on the floor —
issued before the rule, or reclassified afterwards — would otherwise be unreturnable for
ever. A refusal that traps stock is worse than the state it objects to.

**The draft gate still leaked to a read-only login.** Group middleware run before route
middleware, and the OR-group demands `.manage` for a POST — so an `inventory.view` login was
refused at the group and never reached the gate: 403 for a draft, 404 for a ghost. Same
oracle, one rung further out, for a role the live Roles screen can create. The gate is on
the **group** now, and first. The test sweeps three login shapes × four request forms and is
mutation-proved against exactly that login.

Everything else held: `route:list` confirms all three `{material_request}` routes carry the
gate ahead of every permission answer; the middleware diff around the route move accounts
for every entry with nothing lost; ordinary values still pass every guarded door (procurement
walked end to end, all three stock-movement doors, JSON numeric literals as well as strings);
and rounds one to six were independently re-probed rather than re-read. Resin chain
consumption rows: **0**. FC-01 and FC-06 hold; FC-03 now holds on the return door too.

### Known and recorded, not fixed

- The ~100 remaining unguarded numeric fields outside this workflow (inventory above).
- `SubstituteBindings` runs before `EnsureModulePermission` repo-wide, so every
  `module:`-gated bound route is an existence oracle for any authenticated user. Root fix is
  a global middleware-priority change — an owner-visible decision, not a deploy-eve one.
- `PlainDecimal` refuses a JSON float that stringifies to exponent form (`0.000012` →
  `1.2E-5`). The old rule accepted it and the `decimal(_,4)` column rounded it to `0.0000`,
  so refusing is arguably the better answer; recorded because the Tally mirror posts through
  one of those doors.
- No service-layer unit guard; no DOM in the frontend suite, so the GRN draft-restore
  machinery remains inspection-only.

## The eighth round — two PASSes, and the follow-ups closed anyway

**Both halves PASS.** The first double-PASS of the series, and the evidence is the kind that
is hard to argue with rather than the kind that is merely green.

The escape hatch — the riskiest thing round seven added, because "exactly equals the
outstanding balance" is a comparison and comparisons at four decimal places are where abuse
lives — held against every attack: rounding at 4dp **truncated** rather than over-moving,
off-by-one refused, negative outstanding refused twice over, duplicate line ids rolled the
whole transaction back, zero and malformed spellings refused with no 500s. The reason it
holds is worth keeping: `StoreIssueService` re-reads `quantityOutstanding()` under
`lockForUpdate()` with **the same `bcsub(issued, returned, 4)` arithmetic the validator
uses**, so there is no second definition to drift — which is the failure mode that produced
five of this branch's eight blocking findings.

The both-units matrix is clean across 28 probes; the gate is clean across 7 logins × 3 verbs
× 3 targets with byte-identical bodies and the draft surviving every refusal; and rounds one
to seven were re-probed rather than re-read.

### The follow-ups they named, all closed

- **The structural test was "false as written" AGAIN.** Two classes the same commit guarded
  — `MrpNetRequirementsRequest` and `LoadFactoryDayBinBagRequest` — were not in its `DOORS`
  list, so stripping their guards left it green. The same defect the test exists to prevent,
  inside the test. Both added; the regex now also sees pipe-string rules and arrays holding
  a `Rule::` object.
- **The magnitude class.** `PlainDecimal` closes the SHAPE (`1e3`, `INF`) and says nothing
  about SIZE. A 14-digit quantity — what a held-down key on a number input produces, well
  below the 1e21 where `JSON.stringify` switches to exponent form — cleared every guarded
  door with a 201 and landed in a `decimal(15,4)` column that holds eleven integer digits.
  Live MySQL runs strict, so that is an error rather than a rounded value; and **a figure
  the column cannot hold is wrong whatever the driver does about it**, which is why this was
  fixed on reasoning that does not depend on the MySQL leg the reviewer could not run. 26
  fields bounded, and the test now demands BOTH guards — it immediately caught a
  material-request field that had a shape guard and no bound.
- **A flaky FC-06 pin.** `test_the_issue_never_shows_a_rate_or_an_amount_to_a_store_reader`
  searched the encoded JSON for the substring `rate`, which matches a Faker-generated name —
  "Monserrate". It reads keys and values now. **A guard that fails at random is worse than
  no guard**: it teaches people to re-run it rather than read it.
- **The return modal rounded its input to a whole number**, which made the escape hatch
  untypable in exactly the case it exists for — a legacy fractional quantity standing on the
  floor against a counted line. It now takes decimals when the outstanding balance is
  itself fractional.

### Still recorded, still not fixed

The ~100 unguarded numeric fields outside this workflow; `SubstituteBindings` running before
`EnsureModulePermission` repo-wide (every `module:`-gated bound route is an existence oracle
for any authenticated user — a global middleware-priority change and an owner-visible
decision); `PlainDecimal` refusing a JSON float that stringifies to exponent form; the
boolean-id coercion the framework applies to every id field; no service-layer unit guard; and
no DOM in the frontend suite, so the GRN draft-restore machinery stays inspection-only.

## Deployment is blocked, and not by the code

GitHub Actions is **billing-blocked** on the repository account: every job on `0e55c06`
failed in three seconds, on two attempts, with `The job was not started because recent
account payments have failed or your spending limit needs to be increased`. The previous
commit's MySQL leg passed in 7m28s, so this began between the two.

Two of the owner's four deployment conditions therefore cannot be met: **CI cannot run**, and
**the deploy itself is a GitHub Actions workflow**. Deploying by hand over SSH would skip the
maintenance window, the backup and the migrate-step evidence — the protections that exist
because a deploy once left the floor serving new code on an unmigrated schema. It was not
done. The account needs Settings → Billing & plans.
