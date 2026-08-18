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
