# Second opinion on the 02-Sep-2026 decision set (DEC-20260902-002 … -035)

**Read 02-Sep-2026** on branch `claude/factory-workflow-chapters` at `4a9dee1`,
against the 34 canonical records, `docs/factory/CURRENT-DECISIONS.md`,
`FACTORY-CONSTITUTION.md`, `SOURCE-PRIORITY.md`, `PENDING-OWNER-QUESTIONS.md`,
the three workflow chapters and the ground-truth note in this folder.
Review document, not a decision: nothing here binds the factory, and nothing
here proposes a factory value. Every claim carries a `path:line`, a `DEC-…` id,
an `FC-…` id or a `Q…` id.

`scripts/factory-knowledge/check.sh` → `FACTORY KNOWLEDGE: sound`, exit 0 —
run before this file was written and **re-run after it was written**, since
this file sits under `docs/factory/` and cites records by id.
PR #80 is docs-only against `main`: seven paths, all under `docs/factory/`
(`gh pr view 80`, 1907 additions / 13 deletions). Nothing was counted on the
live instance, nothing was posted to Tally, and no existing file was changed
by this review.

**A note on the instrument.** Records are immutable and byte-validated
(`FC-08`; `DEC-20260806-012`), and the owner reads `CURRENT-DECISIONS.md`, not
a raw record. So every defect named below is fixed either by a NEW record from
the owner or by a line in the working chapter — never by editing a record.

## Summary

1. The set is internally sound in rule. I found no pair that contradicts
   another in what the factory must do. I found **three undeclared narrowings**
   (a later record silently tightening an earlier one recorded minutes before)
   and **one uncovered case** (a damaged bin material has no door).
2. Against the constitution: `FC-01`, `FC-02`, `FC-05`, `FC-06` and `FC-07` all
   hold. The one place where a record moves ground without saying so is
   `DEC-20260902-005`'s sentence that resin provenance "is read from the bags
   the Store scanned at issue", against `DEC-20260810-001`'s owner-fixed
   *bin-held-these-lots* wording and the still-open `Q54(d)`.
3. The mermaid map in `00-END-TO-END-FACTORY-WORKFLOW.md` no longer describes
   the factory these records define: eight nodes or arrows are wrong or
   missing, the largest being that finished goods hang off approval rather than
   completion (`DEC-20260902-016`).
4. Every GAP the chapters name is real — I spot-checked the four that a build
   would start from. **Six GAPs are missing**, the load-bearing one being that
   a *typed* Store Issue line still moves material to WIP with no bag scan and
   no resin-pool fold (`StoreIssueService.php:1194-1201`), which is the exact
   silent-costing failure `Q55(b)` warns about.
5. The biggest risk is **rollout asymmetry**: `-017` and `-018` carry a rollout
   order inside the rule; `-035`, `-023` and `-013` carry none, and each of
   them refuses a real live transaction on the day it ships unless a live
   master-data run happens first.
6. **Verdict: merge PR #80, after two docs-only additions to chapter 2.** No
   record in the set is wrong in rule; everything else found here blocks the
   build, not the merge. Details in §6.

---

## 1. Internal consistency among the 34

### 1.1 `-013` vs `-014` — the counted hold, undeclared narrowing

`DEC-20260902-013`: "The hold is on the LINE QUANTITY and needs no bags: it
does not wait for Q87's unit or barcode answer". `DEC-20260902-014`, recorded
in the same session: "the quantity hold of DEC-20260902-013 releases unit by
unit." A quantity hold released unit by unit is a different mechanism from one
that "needs no bags", and `-014` does not say it narrows `-013`.

Two consequences the pair leaves unstated:

- `-013`'s fallback — "For a counted material that carries no bags, the typed
  accepted and rejected quantities stand until Q87 defines how a counted
  arrival is recorded" — has no stated life, because `-014` defined `Q87` the
  same day (`Q87` header now reads RESOLVED,
  `PENDING-OWNER-QUESTIONS.md:2442`). Whether the typed path survives for
  arrivals received *before* the handling-unit build is not said.
- Neither record says whether the handling-unit block is **mandatory** on a
  counted GRN line. This is precisely the trap `DEC-20260831-010` documented
  for weighed materials: "Applied literally to a counted material the rule
  would REQUIRE a block the service REFUSES". A supplier who delivers loose
  cartons on a pallet nobody labels is either refused at the gate or held with
  no unit to release, and the records do not choose.

### 1.2 `-011` vs `-012` — who types the rejected quantity

`DEC-20260902-011`: "The inspector records: … the accepted and rejected
quantities." `DEC-20260902-012`: "The inspector must not type a rejected
quantity, and the screen must not accept a figure that would split a bag."
`-012` narrows `-011` for weighed materials and says so only obliquely, by
reserving typed quantities "for a counted material that carries no bags". A
reader of `CURRENT-DECISIONS.md` sees both sentences and must reconcile them.

### 1.3 `-006` vs `-007` — "the measured weight"

`DEC-20260902-006`: the screen "records the sample count, the measured weight,
the standard weight … and the visual observations." `DEC-20260902-007`:
"Quality records the sample count and the TOTAL measured sample weight; the ERP
calculates the average measured weight per piece". `-006`'s "measured weight"
is per-piece-or-total ambiguous and `-007` resolves it silently. Low severity —
`-007` is unambiguous on its own — but it is the third instance of the same
class.

### 1.4 `-002` vs `-005` — "the one event" that has a second door

`DEC-20260902-002`: "the Store scans each bag's inward barcode as it issues the
bag". `DEC-20260902-005`: that scan "is the one event that both moves PET resin
into Production/WIP and feeds the PET resin weighted-average pool". Neither
record closes the *typed* Store Issue line, which today moves the same material
to WIP with no scan and no pool entry (§4.2). The rule is stated as if one door
exists; two do.

### 1.5 `-003` / `-004` — good sequencing, one uncovered case

The pair is well built: `-003` makes the return rule follow the *flow* and
expressly parks "how the ERP knows which items go into the day bin" as
`Q94(c)`; `-004` answers it with a per-item flag set by a person. No conflict.

The uncovered case is a **damaged bin material**. `-003`: bin material "is not
returned, not partially, not fully, not at the end of the day"; and, separately,
"A damaged return of a returnable material still goes to quality inspection
(DEC-20260901-003)." A torn PET-resin bag standing on the floor is therefore
not returnable, and `DEC-20260901-003`'s damaged path is reached only through a
return — so damaged resin has no door to Quality and no door to scrap. Neither
record names the case. This needs an owner answer, not an agent's reading.

### 1.6 `-030` vs `-031` — declared, but the two sentences sit side by side

`DEC-20260902-030`: "The ERP must not automatically rank customers, move holds,
or infer priority from order value, customer name, promised date or previous
sales." `DEC-20260902-031`: "PENDING PRODUCTION REQUESTS ARE ORDERED BY THE
SALES LINE'S PROMISED DATE, EARLIEST FIRST". `-031` declares the reconciliation
in its own text ("a queue sort order and not a priority rule … DEC-20260902-028,
DEC-20260902-029 and DEC-20260902-030 stand unchanged"), so this is **not** an
undeclared contradiction. It is flagged because the generated view puts the two
sentences one after another, and because the only thing separating them in the
build is `-031`'s flag that a request "moved since Production last looked".

### 1.7 The four category rules are consistent but deliberately unlike

Same field, four treatments, each declared: refuse on category for sales orders
(`-035`), filter-with-a-deliberate-override for purchase documents (`-023`),
informational-only for store requests and issues (`-027`), and
warn-but-never-block at completion (`-019`). No contradiction — but `-019`
carries a precondition the others do not: "Category-based restrictions on
consumption may be introduced ONLY through a new decision, after every active
live item has been reviewed and correctly categorised by a person." `-035`
enforces on category with no such precondition (§5.1).

### 1.8 The approval-chain gates hold together

`-010`, `-017`, `-018`, `-022` and `-025` do not collide. `-010`'s claim that
`allow_same_user` relaxes the new comparison "exactly as it relaxes the other
two" matches the flag's stated scope in code — "SCOPE is the accountant gate
and the quality gate" (`backend/config/production.php:127`) — and `-010`
answers precisely the question that comment
parks ("Raise it with the factory before adding a third comparison here",
`backend/config/production.php:136-145`).

One gap inside `-022`: "The Plant Manager and Accounts must see these figures
before they sign" — no record says whether *seeing* is enforced (an
acknowledgement) or merely displayed. `-018`'s postability refusal is the only
gate on that screen.

### 1.9 `-024` → `-025` is the system working

`-024`'s own source names the risk ("The rejection clause is the agent's
reading … if that reading is wrong, say so and it is withdrawn"), and `-025`
supersedes it solely to withdraw that sentence. This is the correct instrument
and the correct paper trail; it is worth noting because it is the model the
narrowings in §1.1–§1.3 did not follow.

### 1.10 Bookkeeping: `Q59(d)`

`DEC-20260902-023` resolves "Q59(a), and Q59(d) for purchase documents only".
`Q59(d)` also asks what a document does with an unclassified item generally.
`-027` (informational) and `-035` (refused) answer it in substance for the other
two document types, but neither says it does, and the question's header now
reads RESOLVED (`PENDING-OWNER-QUESTIONS.md:1436-1450`). Bookkeeping only, but
`-035`'s claim that it "resolves Q59 in full" rests on that inference.

---

## 2. Consistency with the constitution and earlier decisions

### 2.1 `FC-01` and `DEC-20260810-001` — the provenance sentence moved

`DEC-20260902-005`: "Resin provenance (DEC-20260810-001) is read from the bags
the Store scanned at issue." `DEC-20260810-001` fixes the wording of that
provenance: "Resin provenance is the CALCULATED day-bin attribution … and the
wording must always say bin-held-these-lots: the 1-Aug resin boundary stands,
no bag-to-batch physical identity is ever claimed (FC-01)."

`Q54(d)` states the distinction in the owner's terms: "'issued from the store on
this issue' is EXACT, unlike the day-bin attribution which is calculated over a
shift window — and a trace that mixes an exact statement and a calculated one
under one sentence would overstate the calculated half"
(`PENDING-OWNER-QUESTIONS.md:1139`, clause (d), still open).

`-005` changes the *source* of the provenance without changing, reserving or
superseding the owner-fixed sentence, and without naming `Q54(d)`. The record
itself claims nothing that breaches `FC-01`; the risk is in the build, which
could read provenance from a scanned bag and keep a sentence written for a
calculated attribution. This is the single item in the set I would most want an
owner sentence for before code is written.

### 2.2 `DEC-20260807-007` — one number, three jobs, no stated precedence

`-005` makes the Production/WIP balance of PET resin the day-bin figure. That
same number is now simultaneously:

- the drifting estimate `DEC-20260807-007` binds — "every screen showing the
  figure must present it as an ESTIMATE that drifts over time, never as a
  counted fact";
- the "quantity already available in Production/WIP" that `DEC-20260831-005`
  subtracts when netting the next material request; and
- a `stock_balances` row that the Tally reconcile compares, which
  `DEC-20260807-007` itself calls stock truth ("Stock TRUTH comes from the Tally
  reconcile (DEC-20260806-009), not from this figure").

`-005` acknowledges the drift and the owner accepts it. What neither `-005` nor
chapter 2 §3 says is which treatment governs where — in particular whether the
Store ↔ Production page may present the resin row as a balance beside other
materials' balances when `DEC-20260807-007` forbids presenting it as a counted
fact.

### 2.3 Supersessions that are correctly declared

- `DEC-20260807-006` → `-002`: the record file carries
  `"status": "superseded"` and `"superseded_by": "DEC-20260902-002"`, and `-002`
  restates every surviving clause so nothing is lost.
- `DEC-20260825-001` / `DEC-20260831-011` → `-013`: "retires the limit under
  which a counted arrival was issuable the moment its GRN was saved", stated.
- `DEC-20260831-005` / `DEC-20260901-001` → `-003`: "This NARROWS those two
  decisions to non-bin materials and changes nothing else in them", stated.
- `-024` → `-025`, as above.

### 2.4 `DEC-20260827-001` / `-002` — enforcement is legitimately switched on

`DEC-20260827-001` says its classification "does NOT switch on any enforcement:
which categories each document may use is Q59 and stays open". `-023`, `-027`
and `-035` are the Q59 answers, so switching enforcement on is proper. What no
02-Sep record carries forward is `-827-001`'s other clause: "Applying it to live
master data is a separate, deliberate run of the manual master-data workflow,
dry-run read first." See §5.1.

### 2.5 `FC-05`, `FC-06`, `FC-07`, `FC-02` — all intact

- `FC-05`: `-010` adds a third comparison; `FC-05`'s text is silent on
  checker-vs-PM, so this is an addition, not an override. Note that `FC-05`'s
  wording now understates the rule in force, and changing an FC entry "requires
  owner confirmation and a superseding note — never a silent edit"
  (`FACTORY-CONSTITUTION.md`, preamble). No 02-Sep record proposes that.
- `FC-06`: `-011` refuses to widen it ("FC-06 stands as written"), `-014` keeps
  supplier and rate off the label, `-026` keeps classification rate-free.
- `FC-07`: `-017` keeps colour a warning "because a clear product requires no
  masterbatch (FC-07)" — correct.
- `FC-02`: `-006` leaves the OK/rejected count as the only scrap booking.
- `DEC-20260830-002`: `-005` folds the bin into WIP without destroying the
  protected invariant — "issued to production but not yet consumed" is still the
  WIP row's job.
- `DEC-20260831-012` / `DEC-20260901-005` / `DEC-20260901-007`: `-016`,
  `-028`…`-033` and `-019` restate rather than move them.

---

## 3. Against the workflow map

The mermaid flowchart in `00-END-TO-END-FACTORY-WORKFLOW.md` predates most of
the set. Wrong or missing, in map order:

1. **`productionApproval --> finishedStock["Finished goods stock"]` is wrong.**
   `DEC-20260902-016`: "Complete Batch records the finished goods in the Store's
   finished-goods location, as it does today." The arrow belongs on
   `completeBatch`; approval is not what puts stock in the Store.
2. **`storeProduction["Store issues material to Production"]` does not show the
   scan.** After `-002` the Store's bag scan at issue is the factory's only
   scan and the only traceability event on that path.
3. **There is no Production/WIP node and no day bin.** `-005` makes
   Production/WIP the day bin for PET resin; the map jumps from the store issue
   straight to a planning node.
4. **`storeProduction --> productionPlan` is the wrong arrow.** Planning is
   driven by sales demand and the queue (`-031`, `-033`), not by a store issue.
5. **No end-of-day return arrow.** `DEC-20260831-005`, `DEC-20260901-001` and
   `-003` make the return a real stage with a refusal for bin materials.
6. **`usableMaterial --> barcodeLabels["Bag or lot labels"]` is in the wrong
   order.** Bag identity is created at GRN, before inspection (chapter 1 §5:
   "Bag barcodes are created at GRN time"), and `-014` puts one handling-unit
   barcode on the GRN too. Labels hang off `goodsReceipt`.
7. **No handling units anywhere**, and no counted-material hold — `-013` and
   `-014` add both.
8. **No rejection → supplier-bill link.** `-015` requires the rejected quantity
   and the Rejections Out reference to be visible on the Supplier Bill screen
   against the GRN line; the map shows only `goodsReceipt --> supplierBill`.
9. **`requisitionApproval -->|"No"| rejectedRequest["Return or reject"]`
   conflates two different acts.** `-025`: "REJECTION remains an approver
   action … A requester may WITHDRAW or CANCEL their own requisition through a
   separate action of their own, which is not a rejection."
10. **`productionQueue` carries no ordering.** `-031` (promised date, manual
    position sticky) and `-032` (a queued request retires when covered) are the
    queue's rules now.

Correct as drawn, and worth saying: there is **no store-acceptance node**, which
is what `-016` decided; `purchaseOrder -.-> tallyPurchaseOrder` is real
(`TallySyncService.php:288-300`, `DEC-20260812-002`); and the Store's dispatch
chain `salesHold → dispatchQuality → storeDispatch` matches `DEC-20260901-005`
and `Q27`'s resolution.

The **role-dashboard table** needs one addition: `-016` requires the Store
dashboard to show finished goods "completed, Quality-pending, approved and
rejected … each opening the rows behind the figure". The Store row currently
asks for requests, issues, QC waiting, held sales stock and dispatch.

---

## 4. Against the code, via the research note's citations

### 4.1 The GAPs the chapters name are real

Spot-checked the four a build would start from:

- **No per-item bin-material flag** (`-004`): `grep -rn "is_bin_material|bin_material" backend/app backend/database`
  returns nothing.
- **No weight tolerance** (`-007`): `grep -rn "tolerance" ProductionStandard.php`
  and `grep -rln "weight_tolerance" backend/app backend/database` return
  nothing.
- **Readiness gate shipped watch-only** (`-017`):
  `backend/config/production.php:553` `'enforced' => env('PROD_READINESS_ENFORCED', false)`.
- **`-034` needs no code**, and chapter 1 §5 correctly records no GAP: the GRN
  request already requires a PO —
  `backend/app/Modules/Procurement/Http/Requests/StoreGoodsReceiptRequest.php:139`,
  `'purchase_order_id' => ['required', …]`.

### 4.2 Six GAPs the chapters do not list

**(a) The typed Store Issue line bypasses the scan — the load-bearing omission.**
`-005` makes the Store's scan the one event that feeds the resin pool. But
`StoreIssueService::issue` writes a plain store → WIP transfer for a typed line
with no bag, no scan record and no pool call
(`backend/app/Modules/Inventory/Services/StoreIssueService.php:1194-1201`), and
the request permits any active `is_production_input` item on a fresh handover
(`StoreStoreIssueRequest.php:283-293`). Chapter 2 §3's build list says "move
the resin-pool fold to the Store Issue scan" and stops there. Unless the typed
path is refused for a bin material — or made to fold — the pool loses its
inflow exactly as `Q55(b)` warns: "every batch would silently drop from
pool-priced to average-fallback or unpriced, and the first sign would be
costing that quietly stopped meaning what it used to."

**(b) `recordClosingDayBin` is not in the retirement list.** `-005` retires "the
separate day-bin page, balance or daily day-bin action" and says "The floor
records nothing when it tips a bag into the bin." The floor still records a
closing day-bin figure at completion and at handover
(`ShiftProductionEntryService.php:779`, `:1624`, `:3608`), which the research
note names as one of the ledger's two live writers (§1a). Whether it survives,
and what it means once the bin is the WIP balance, is unlisted.

**(c) `Q55(c)`'s refusal set is unlisted.** "Which of the Day Bin's refusals
should survive its retirement? The old refusal set is the contract"
(`PENDING-OWNER-QUESTIONS.md:1218`, clause (c)) — the 422 with no bin
configured, the balance acknowledgement above `machine_balance_ack_kg`
(`backend/config/production.php:91`, read at
`FactoryDayBinService.php:373`), the return/count balance guards, the block on
cancelling a batch with day-bin movements, and the deliberate null-not-zero
consumption. Chapter 2 §3's five build steps name none of them.

**(d) The `consumptionSource` day-bin branch and the unread live setting.**
`-005` retires "its day-bin warehouse setting", but the resolver's second
precedence step still reads that warehouse
(`FactoryWarehouseResolver::consumptionSource`, research note §1c), and
`Q55(a)` records that nobody has read the live value: "When it names a DISTINCT
warehouse, deleting it **moves which warehouse new completions decrement, and
therefore the godown lineage of new vouchers.**" One live read settles it; the
chapter does not ask for it.

**(e) `-017` omits `item_active`.** The decision enumerates eight refusals plus
colour-as-warning. The gate has ten checks, and `item_active` is `block`
(`backend/config/production.php:556`). Enforcing exactly the decision's list
would drop a refusal the shipped gate already carries — the refusal-set
question that has bitten this repo before.

**(f) `-035`'s "active" half.** "A SALES ORDER MAY CONTAIN ACTIVE FINISHED GOOD
ITEMS ONLY." The request validates only `'lines.*.item_id' => ['required',
'integer', 'exists:items,id']`
(`backend/app/Modules/Sales/Http/Requests/StoreSalesOrderRequest.php:30`) — it
carries an `is_active` check for the customer (`:19`) and none for the item, and
`SalesOrderService` has no category or active guard. Chapter 2 §14's GAP names
only the category half.

Minor: `-026` makes classification multi-valued ("A vendor may have one or more
of them"). Chapter 2 §13's GAP says "no classification column" — a
multi-valued classification is not a column.

---

## 5. Risks and blind spots

### 5.1 Rollout asymmetry — three rules that refuse a live transaction on day one

`-017` and `-018` put the rollout order **inside the rule** ("ROLLOUT ORDER,
which is part of the rule"). Three others do not:

- **`-035`** refuses a sales order whose item is not category `Finished Good`.
  No 02-Sep record says the live items have categories. The repository evidence
  points the other way: every seeder writes items with no category, no
  migration classifies, and `DEC-20260827-001`'s application to live "is a
  separate, deliberate run" (research note §5). If the run has not happened,
  `-035` refuses **every** sales order on the day it ships.
- **`-023`** shows Raw Material and Packing Material by default. On unclassified
  live data the default picker is empty and every item needs the deliberate
  override plus a reason.
- **`-013`** holds every counted GRN line from every outflow door until an
  incoming inspection exists. Packaging is issuable today the moment its GRN is
  saved; on day one a carton arrival stops at the Store until Quality inspects
  it, and the counted inspection screen is itself part of the same build.

The same live-data dependency runs the other way for `-019`: the shipped
refusal set excludes `finished_good` **by category**, so on unclassified live
data that refusal cannot fire at all. `-019` records it as "a confirmation of
the shipped refusal set"; on live it may currently be confirming a rule that
never triggers. Count before building.

### 5.2 `-025` plus the Store role can deadlock a requisition

`DEC-20260902-025`: "Any user with the procurement write permission may approve
a Purchase Requisition, except the person who raised it … there is no
Administrator exemption." `DEC-20260902-001` (open PR #79) grants the Store role
`procurement.view` and `procurement.manage` in full and names Vasanth its first
holder. If the live count of `procurement.manage` holders who are not also the
usual requester is small, a requisition raised by the sole holder can never be
approved. Neither record names that count as something to take first.

### 5.3 PR #79 / PR #80 — not an id collision, a file collision

The id is handled: `-002`'s source explains that it "is minted as the second id
of the day because open PR #79 … already claims the first". The collision is in
the shared files, and the diffs overlap line for line:

- `docs/factory/PENDING-OWNER-QUESTIONS.md`, the **Q78 entry**. PR #79's hunk
  (`@@ -2109,7 +2109,9 @@`) rewrites the paragraph beginning "The Storekeeper
  role now exists as a definition (`roles:define-storekeeper`, dry-run first)"
  and carries the old header line — "— PARTLY RESOLVED" — as context. PR #80
  changed that same header to "— RESOLVED" and inserted a resolution block
  immediately above that same paragraph (`PENDING-OWNER-QUESTIONS.md:2146-2157`).
  A textual conflict is near-certain, and the two texts also disagree in
  substance: `DEC-20260902-001` says "The store-acceptance half of Q78 remains
  OPEN and is not decided here", while `-016` closes Q78 in full.
- `docs/factory/CURRENT-DECISIONS.md`, the index at the top of the file: PR #79
  appends its `DEC-20260902-001` bullet at `@@ -9,8 +9,10 @@`, in the same
  region PR #80's 34 bullets land.

Whichever merges second must resolve both by hand and regenerate the view, or
`check.sh`'s generated-view freshness check fails on `main`.

### 5.4 Whose words are in the record

`SOURCE-PRIORITY.md` puts "latest explicit owner confirmation — dated, in the
owner's own words" at rung 1 and "agent analysis, session memory, or an old
transcript" at rung 7. Across this set the owner's own words are frequently a
single letter — "A, record it and ask the next question" — with every
substantive clause supplied by a Codex text the owner then forwarded and the
record adopts "as their own instruction". That is true of `-007`, `-010`,
`-012`, `-014`, `-016`, `-017`, `-019`, `-020`, `-021`, `-022`, `-023`, `-026`,
`-027`, `-030`, `-033` and `-035`. Each record discloses it in its source field,
which is right; the pattern is worth naming once because it is a property of
the whole day rather than of any one record.

Two records sit further out and should be read again with the owner:

- **`-029`**: the owner first answered "A" alongside a Codex text choosing B;
  asked to say which in their own words, the answer was "we can go with your
  recommndation of A". The rule rests on the owner endorsing the agent's pick.
- **`-031`**: the owner first said A (raised order, manual reordering), a Codex
  review argued B, the agent accepted B, and the owner then confirmed "B". The
  recorded queue rule is the opposite of the owner's first answer. It is
  declared in the source, and it is the most fragile rule in the set.

The counter-example is **`-015`**, where the agent declined a Codex clause
("that line was NOT adopted because no such limit exists in SupplierBillService
… and the owner did not state it in their own words") and named it as not
decided. That, and the `-024` → `-025` withdrawal, show the discipline was
being applied — which is what makes the two cases above legible rather than
hidden.

### 5.5 Costing goes quiet before it goes wrong

Restating `Q55(b)` because `-005` decides the rule without stating a build
order: the floor scan is the only caller of `ResinPoolService::fold` in the
application. Retiring it before a store-issue-side inflow exists breaks nothing
visibly. `-017` and `-018` both say "not immediately, first prove it on live";
`-005` says nothing equivalent, and this is the case that most needs it.

### 5.6 `-003`'s refusal protects nothing until a person acts

`-004`: "An item nobody has flagged is NOT a bin material." So on the day the
return refusal ships, no item is a bin material and resin is returnable until
somebody flags it. Conversely, once PET resin is flagged, an unopened bag on
the floor becomes unreturnable — `-005` concedes "the ERP cannot tell an
unopened bag standing on the floor from resin in the bin, and the owner accepts
that" — and, with §1.5's damaged case, a torn bag has nowhere to go at all.

### 5.7 Smaller ones

- `-022`'s "must see these figures before they sign" has no stated mechanism
  (§1.8).
- `-021` and `-022` require the other nine workbook rows to read "Not in use" on
  screen; `-033` invokes the owner's standing 25-Aug rule that floor users do
  not read page text. The two constraints meet on the Factory Rules tab and
  nobody has said how.
- `-012` correctly demands a live count of bags already stuck on hold. `-013`
  demands no equivalent count of counted stock already received and issuable.

---

## 6. Verdict

**Merge PR #80, once the two chapter-2 additions below are in it.** The
decision set itself is clean: it is docs-only against `main`, `check.sh`
returns `FACTORY KNOWLEDGE: sound` (exit 0), every supersession in the record
files is declared, and no record is wrong in rule. Nothing here justifies
holding the factory's own written record of the day it answered these
questions. The two additions are docs-only, live in files the PR already
touches, and are what stops the next build from starting blind — so they go in
before the merge rather than after it.

**Fix inside this PR (cheap, and in files it already touches):**

1. Chapter 2 §5 and §4 should name the three narrowings — `-011`/`-012`,
   `-013`/`-014`, `-006`/`-007` — so a reader of the chapter is not left to
   reconcile the generated view alone. The records themselves stay untouched
   (`FC-08`).
2. Chapter 2's GAP lists should add the six missing build items in §4.2, above
   all the typed Store Issue line (§4.2a).

**Build-blocking, not merge-blocking** — these belong in the build brief and in
the next owner session, and each is named above: the rollout order for `-035`,
`-023` and `-013` (§5.1); the resin-pool inflow before the floor scan retires
(§5.5); `Q55(a)` and `Q55(c)` (§4.2c, §4.2d); `-017`'s `item_active` omission
(§4.2e); `-035`'s active half (§4.2f); `recordClosingDayBin` (§4.2b).

**Owner questions I would raise before code, not after** — none of them has an
answer an agent may supply:

- What happens to a **damaged bin material** (§1.5).
- What the **carton trace sentence** becomes when provenance is read from the
  Store's scanned bags, against `DEC-20260810-001` and open `Q54(d)` (§2.1).
- Whether a **counted arrival must** arrive in handling units, and what the GRN
  does when it does not (§1.1).
- Which treatment governs the **resin figure** on the Store ↔ Production page,
  given `DEC-20260807-007` (§2.2).
- Whether `-029` and `-031` still read as the owner's own rules (§5.4).

---

## Could not ground

Live reads this review deliberately did not take, and on which several findings
above depend:

- The live value of `production_day_bin_warehouse_id` — `Q55(a)`; it decides
  whether retiring the day-bin branch moves the godown lineage of new vouchers.
- Whether `DEC-20260827-001`'s classification has ever been applied to live
  items. Repository evidence says seeded data is uncategorised and no migration
  classifies (research note §5); the live state is unread, and §5.1's
  day-one-refusal risk turns on it.
- The count of live logins holding `procurement.manage`, and who raises
  requisitions today — §5.2's deadlock risk.
- Whether any bag sits on `waiting_qc` on live under the behaviour `-012`
  retires, and whether counted stock already received would be caught by
  `-013`'s hold.
- The live values of `production_wip_warehouse_id` and
  `quality_hold_warehouse_id`, and whether a `QC-HOLD` warehouse row exists.

Factory facts that are missing rather than unread, and are not supplied here:

- Whether masterbatch is physically returnable in an identifiable container on
  this floor, beyond `-004`'s rule that it is.
- Any weight tolerance, sample-size minimum, visual-observation type, variance
  threshold or parallel-machine count — every one is master data a person
  enters (`-007`, `-008`, `-009`, `-021`, `-022`, `-033`), and none is proposed
  here.
- What one handling unit is for any specific packaging item the factory buys.

Not verified by running: I read code only along the research note's citations
and the six checks in §4. No test suite was run, and no claim above rests on
one having passed.
