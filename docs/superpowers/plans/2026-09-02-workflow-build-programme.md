# Workflow Build Programme (02-Sep-2026 walkthrough)

**Purpose:** order the code work that the three workflow chapters and the 51
decisions of 02-Sep-2026 (DEC-20260902-002 to -052) require, so that each
sub-project ships as working, tested software on its own, and so that the
rules with a rollout order inside them are switched on only after the live
reads they name.

**Spec:** `docs/factory/workflows/00-END-TO-END-FACTORY-WORKFLOW.md`,
`01-DASHBOARD-AND-PROCUREMENT.md`, `02-QUALITY-INVENTORY-PRODUCTION.md`,
`03-SALES-AND-DISPATCH.md`, and `docs/factory/CURRENT-DECISIONS.md`. The
chapters' GAP lines are the requirements; a decision id beside each task is
the authority.

**How each sub-project runs:** one plan file under `docs/superpowers/plans/`,
one branch off `decisionm/main`, TDD per task, the full suites green before
the PR (`ship-a-pr` skill), merge = live deploy (`deploy-live-verify` skill).
Never a Tally voucher, batch or stock change as a side effect of a build.

## Live reads that gate a switch (read-only, before the sub-project that needs them)

| Read | Why | Gates |
|---|---|---|
| Count of active production products not Ready in the standards workspace | DEC-20260902-017 | enforcing the readiness gate |
| Voucher preview against real batches | DEC-20260902-018 | enforcing the postability gate |
| The live value of the day-bin warehouse setting, and which warehouse `consumptionSource` resolves | Q55(a) | retiring the day-bin setting |
| Count of bags left on hold by a rejection that ended inside a bag | DEC-20260902-012 | the whole-bag inspection build |
| Count of Quality-pending finished goods | DEC-20260902-042 | the finished-goods quality-pending state |
| Count of finished-goods stock with no carton identity (legacy) | DEC-20260902-047 | scan-only dispatch |
| Count of procurement-write holders | DEC-20260902-025 with PR #79 | the self-approval refusal (so a requisition always has an eligible approver) |
| Classification run of live items (DEC-20260827-001) | DEC-20260902-013, -023, -035 | every category refusal |

Each read is a manual, read-only GitHub workflow or an `artisan` command run
through one; ten minutes between SSH sessions.

## Sub-projects, in build order

| # | Sub-project | Decisions | Plan |
|---|---|---|---|
| 1 | **Procurement controls** — requisition self-approval refusal and withdraw; purchase pickers raw and packing by default with "show additional purchasable items", finished goods refused, unclassified needs a reason; vendor classification and default view; state names; rejected quantity and Rejections Out reference on the Supplier Bill; PO-first pinned | -023, -025, -026, -034, -015, chapter 1 §2 | `2026-09-02-procurement-controls.md` |
| 2 | **Approval chain and Start Batch controls** — third four-eyes comparison; override reason required; variance figures on Quality, PM and Accounts screens; added-line category warning; colour-only warning in the readiness gate; `item_active` kept as a refusal; rollout switches documented | -010, -017, -018, -019, -020, -021, -022 | to write |
| 3 | **Finished-goods Quality** — checklist fields beside the count; per-product weight tolerance; observation-type master; sample count; Quality-pending finished-goods state with server refusals on hold, approval and delivery; Store dashboard figures | -006 to -010, -016, -042 | to write |
| 4 | **Incoming Quality and handling units** — inspection screen per GRN line with the evidence fields; whole-bag rejection by barcode selection; counted-arrival quantity hold; handling units on counted GRN lines with one barcode each; unit-wise release; unit scan at issue | -011 to -015, -038 | to write |
| 5 | **Store Issue, day bin and returns** — per-item bin-material flag; resin-pool fold on the Store Issue scan in one transaction; typed line refused for bin material; day-bin page retired behind a query-preserving redirect; bin figure labelled Estimated on Store ↔ Production; return refusals for bin material and loaded masterbatch; damaged resin bag to Quality hold; closing day-bin figure and refusal set carried or dropped by name | -002 to -005, -036, -037, -039, Q55 | to write |
| 6 | **Sales and dispatch** — Promised date; floor job view; order amend, reduce, close-short, cancel with reason; confirmed-only Tally matching, Accounts import screen and unmatched list; scan-required dispatch with the legacy typed path; Store cannot-fulfil reason; dispatch-reversal document; customer Pending state and GSTIN rule; Sales role; production queue by promised date; finished-goods-only picker; dispatch from the hold's location; one dashboard count | -031, -035, -041, -043 to -052 | to write |
| 7 | **Role dashboards** — one shell, role-aware sections, every figure a deep link to its filtered rows | chapter 1 §1, -016, -044 | to write |

Sub-projects 1 and 2 need no live read and change no stock effect; they go
first. Sub-project 5 is the load-bearing one for costing and goes only after
the Q55(a) read. Sub-project 6 is the largest and is split into two plans at
writing time: order and Tally match first, dispatch and reversal second.

## What is expressly not in any sub-project

- Anything Q48 or Q68 (Accounts) would decide.
- The CRM surface (DEC-20260902-052).
- Carton label tare weight and resin grade (Q23, Q24).
- Any master-data change on live: those are the manual workflows, dry run first.
