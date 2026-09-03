# Returned by Quality: make a sent-back batch visible

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** a batch Quality has sent back to production is visibly marked as such — who returned it, when, and why — on the Quality queue and on the Shift Floor entry, and can be filtered for on both.

**Architecture:** the data already exists. `ShiftProductionEntryService::returnToProduction` appends `{returned_by, returned_at, reason, cleared_quality_check}` to `config_snapshot['quality_returns']` on every return. Nothing exposes it. This plan surfaces the LAST return through the entry resource, filters on its presence, and renders a tag. No new column, no new rule, no routing change: a returned batch stays completed and awaiting a quality check, exactly as today, and no machine-level step exists or is added.

**Tech Stack:** Laravel 12 (PHPUnit), React + TypeScript (Ant Design, TanStack Query, vitest), Pint.

**Spec:** the owner's request of 03-Sep-2026: "we need to work on the queue with more filter, the send back items from QA; we don't need send back to actual machine, shift floor is enough". The return rule itself is unchanged (`returnToProduction`, gated on `quality.manage`, batch completed and status pending).

## Global Constraints

- No factory rule changes. Nothing may alter who can return, when, or what a return does to stock.
- `config_snapshot` is written only by the service that owns it; readers never write it.
- Labels only, no explanatory sentence in the UI (25-Aug standing rule).
- The name shown is resolved through the user relation, never a raw id in the UI.
- Test permission guard `'web'`. Pint clean. Explicit-path staging. Branch `claude/returned-by-quality` off `decisionm/main`.
- Before the PR: `cd backend && ./vendor/bin/pint --dirty && php artisan test`; `cd frontend && npm run typecheck && npm run test && npm run build`.

---

### Task 1: The entry resource answers "was this sent back by Quality?"

**Files:**
- Modify: `backend/app/Modules/Production/Http/Resources/ShiftProductionEntryResource.php`
- Test: `backend/tests/Feature/Production/ReturnedByQualityVisibleTest.php`

**Interfaces:**
- Consumes: `config_snapshot['quality_returns']`, a list of `{returned_by: int|null, returned_at: string, reason: string, cleared_quality_check: bool}` appended by `ShiftProductionEntryService::returnToProduction`.
- Produces: resource key `quality_return` — `null` when the list is absent or empty, else `{ returned_by_name: string|null, returned_at: string, reason: string, times: int }` from the LAST entry, where `times` is the length of the list.

- [ ] **Step 1: Write the failing test.** Build a completed, pending entry the way `backend/tests/Feature/BatchQualityStageTest.php` does (copy its fixture helper), record a quality check through the API, then call `.../return-to-production` with a reason. Assert the entry's show/index payload carries `quality_return.reason` equal to the reason given, `quality_return.returned_by_name` equal to the returning user's name, `quality_return.times` equal to 1, and that a never-returned entry carries `quality_return` as null. Add a second return and assert `times` is 2 and the reason is the newer one.
- [ ] **Step 2: Run it** — `cd backend && php artisan test --filter ReturnedByQualityVisibleTest` — FAIL, key absent.
- [ ] **Step 3: Add the key.** In the resource, read the list defensively (it may be absent, or hold rows written before this key existed), take the last row, and resolve the name through the `User` model by id — batch-safe: resolve it with a single lookup, not one query per row in a list response. If the resource already loads a relation for `completed_by` or similar, follow that pattern; otherwise resolve the name in the service that builds the list and pass it through, rather than querying inside a resource used in collections.
- [ ] **Step 4: Run the test green, then `php artisan test --filter "ShiftProductionEntry|BatchQuality"`.**
- [ ] **Step 5: Pint, commit** — `A batch sent back by Quality says so in its payload`.

---

### Task 2: The Quality queue and the Shift Floor show and filter it

**Files:**
- Modify: `backend/app/Modules/Quality/Http/Controllers/BatchQualityQueueController.php` and whatever request/service backs it — add an optional `returned=1` filter that keeps only entries whose `config_snapshot->quality_returns` is a non-empty list.
- Modify: `frontend/src/features/production/types.ts` (add `quality_return`), `frontend/src/features/quality/pages/ProductionQcPage.tsx` (tag + filter), and the Shift Floor entry view (`frontend/src/features/production/pages/ShiftProductionEntryPage.tsx`) — tag only.
- Create: `frontend/src/features/quality/returnedByQuality.ts` + `.test.ts` — a pure helper `returnedTagText(qualityReturn)` returning `null` or `Returned by Quality` / `Returned by Quality x2`.
- Test: extend `ReturnedByQualityVisibleTest` for the filter.

**Interfaces:**
- Consumes: Task 1's `quality_return` key.
- Produces: query param `returned=1` on the Quality queue endpoint; helper `returnedTagText`.

- [ ] **Step 1: Write the failing tests** — backend: the queue returns both entries by default and only the returned one with `returned=1`; frontend: the helper's three cases (null, once, twice).
- [ ] **Step 2: Run them** — both FAIL.
- [ ] **Step 3: Implement.** Filter with a JSON predicate the database supports (`whereJsonLength('config_snapshot->quality_returns', '>', 0)` where available; if the column is text on SQLite in tests, use whatever the codebase already does for JSON reads — check `config_snapshot` usages first and follow them). On the Quality page add a `Returned` toggle beside the existing controls and render the tag in the batch row; on the Shift Floor entry render the same tag with the reason in a tooltip.
- [ ] **Step 4: Green, then the full frontend suite and `php artisan test --filter Quality`.**
- [ ] **Step 5: Pint, commit** — `Quality can see, and filter for, the batches it sent back`.

---

### Task 3: Suites and PR

- [ ] Full backend and frontend suites, build, `scripts/factory-knowledge/check.sh`.
- [ ] `ship-a-pr`: push, open the PR naming the SHAs the suites passed on, and state plainly that no rule changed and no batch routing changed.

## Self-review

Spec coverage: the owner asked for the sent-back items to be visible and filterable, with no machine-level step — Tasks 1 and 2 cover both, and no routing is touched. Placeholders: two steps say "follow the existing pattern" for the name lookup and the JSON predicate, because both are only knowable from the code; each says what to do with what is found. Type consistency: `quality_return` is the same shape in the resource, the TypeScript type and the helper.
