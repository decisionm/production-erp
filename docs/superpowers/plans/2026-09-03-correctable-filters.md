# Filters for "Earlier batches — still correctable"

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development.

**Goal:** the Shift Production page's "Earlier batches — still correctable" list can be narrowed by batch number, machine, shift, product, date range and "returned by Quality", sorted newest or oldest first, and read a page at a time — serving both "find the one batch I know about" and "work through the backlog".

**Architecture:** the list is already a server read (`listCorrectableEntries` → `GET /production/shift-production-entries?status=pending&correctable=1`), and `ListShiftProductionEntriesRequest` already accepts `work_center_id`, `shift_id`, `status`, `correctable` and `awaiting_correction`. This plan adds the missing filters to that request and its service, replaces the silent walk of every page with a real pager, and puts one control row above the existing card list. No rule changes: `canAmendCompletion` and the server's own eligibility gate are untouched, and the default view shows exactly what it shows today.

**Spec:** the owner's request of 03-Sep-2026, "we need more filter here", answering "both" to find-one-batch versus work-the-backlog.

## Global Constraints

- The DEFAULT view must be what it is today: every correctable batch, newest first, nothing hidden by a date default.
- No change to who may correct a batch or when. `canAmendCompletion`, `correctionLists()`'s parity guard and the server's eligibility filter stay as they are.
- Labels only, no explanatory sentence in the UI (25-Aug standing rule).
- Every list ships with server-side filtering and a real pager (28-Aug standing rule) — that is what this plan adds.
- Test guard `'web'`. Pint clean. Explicit-path staging. Branch `claude/correctable-filters` off `decisionm/main`.
- Before the PR: `cd backend && ./vendor/bin/pint --dirty && php artisan test`; `cd frontend && npm run typecheck && npm run test && npm run build`.

---

### Task 1: the list endpoint takes the missing filters

**Files:**
- Modify: `backend/app/Modules/Production/Http/Requests/ListShiftProductionEntriesRequest.php`. VERIFIED already present, do NOT re-add: `production_date`, `date_from`, `date_to` (with `after_or_equal:date_from`), `work_center_id`, `shift_id`, `batch_status`, `status`, `per_page`, `page`, `correctable`, `awaiting_correction`. ADD only: `item_id` (integer, min 1), `q` (string, max 64 — the batch number, trimmed, ignored when blank), `returned` (`Rule::in(['1','0','true','false'])`, matching the two neighbouring flags), `sort` (`Rule::in(['newest','oldest'])`, default `newest`).
- Modify: `backend/app/Modules/Production/Services/ShiftProductionEntryService.php::paginate()` — apply them. `returned` reuses the existing `whereJsonLength('config_snapshot->quality_returns', '>', 0)` predicate already used by `whereAwaitingCorrection()` and the Quality queue's own `returned` filter. `q` matches the batch number only. `sort` orders by production date then id.
- Test: `backend/tests/Feature/Production/CorrectableFiltersTest.php`

**Interfaces:**
- Consumes: the existing `paginate()` signature and its `correctable`/`status`/`date_from`/`date_to`/`work_center_id`/`shift_id` filters; `ShiftProductionEntryResource`'s `quality_return` key.
- Produces: four NEW query params on `GET /production/shift-production-entries` — `item_id`, `q`, `returned`, `sort`. The date range uses the existing `date_from`/`date_to`.

- [ ] **Step 1: Write the failing test.** Build several completed, pending entries across two machines, two shifts, two products and two production dates, one of them returned by Quality (drive the return through the API as `backend/tests/Feature/Production/ReturnedByQualityVisibleTest.php` does, and reuse its helpers). Assert: no filters returns them all newest first; each filter alone narrows to the right rows; `q` matches a batch number and ignores a blank; `returned=1` keeps only the returned one; `sort=oldest` reverses the order; an unknown `sort` is refused with 422; every filter NARROWS — a row absent without a filter is never present with one.
- [ ] **Step 2: Run it** — `cd backend && php artisan test --filter CorrectableFiltersTest` — FAIL.
- [ ] **Step 3: Implement** in the request and the service, following the shapes already there for `work_center_id` and `shift_id`.
- [ ] **Step 4: Green, then `php artisan test --filter "ShiftProductionEntry|Correctable|ReturnedByQuality"`, then the full backend suite once.**
- [ ] **Step 5: Pint, commit** — `The entry list takes product, date, batch number, returned and sort`.

---

### Task 2: the control row, the sort switch and the pager

**Files:**
- Modify: `frontend/src/features/production/api.ts` — `listShiftProductionEntries`'s params type gains the four new keys; replace `listCorrectableEntries`'s `walkEntryPages(...)` with a single paged read taking the filters and returning the server's meta. Leave `listAwaitingCorrectionEntries`'s walk alone: the amber panel above is a backlog that must stay whole.
- Create: `frontend/src/features/production/correctableFilters.ts` + `.test.ts` — a pure `correctableQuery(filters, page)` building the request params, with the blank-`q` and default-`sort` rules, and `correctableFiltersActive(filters)` for the Clear control.
- Modify: `frontend/src/features/production/pages/ShiftProductionEntryPage.tsx` — one control row above the "Earlier batches — still correctable" heading: a search box for the batch number, Machine, Shift and Product selects, a date range wired to the existing `date_from`/`date_to`, a `Returned` toggle, a `Newest / Oldest` switch, and a Clear that appears only when something is set. Below the cards, a pager driven by the server's meta, 25 a page.

**Interfaces:**
- Consumes: Task 1's query params and the paginated response's meta.
- Produces: `correctableQuery`, `correctableFiltersActive`.

- [ ] **Step 1: Write the failing vitest** for both helpers: a blank search is omitted; `sort` defaults to newest; every set filter appears once; `correctableFiltersActive` is false for the default state and true for each single change.
- [ ] **Step 2: Run it** — FAIL, module not found.
- [ ] **Step 3: Implement** the helpers, then the api change, then the page. Machine, Shift and Product options come from the reads the page already has; do not add a new master read if one is already loaded.
- [ ] **Step 4:** `cd frontend && npx vitest run src/features/production/correctableFilters.test.ts`, then `npm run typecheck && npm run test`.
- [ ] **Step 5: Commit** — `The correctable list can be narrowed, sorted and paged`.

---

### Task 3: suites and PR

- [ ] Full backend and frontend suites, build, `scripts/factory-knowledge/check.sh`.
- [ ] `ship-a-pr`: push, open the PR naming the SHAs the suites passed on, and state plainly that the default view is unchanged and no correction rule moved.

## Self-review

Spec coverage: find-one-batch is served by the search box and the machine filter; work-the-backlog by shift, date, the Returned toggle and oldest-first. Placeholders: Task 1 Step 1 says to reuse the neighbouring test's helpers rather than inventing fixtures, and Task 2 Step 3 says to reuse master reads already on the page — both are only knowable from the code. Type consistency: the six new params are named identically in the request, the api params type and `correctableQuery`.
