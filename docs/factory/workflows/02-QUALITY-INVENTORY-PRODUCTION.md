# Chapter 2: Quality, Inventory and Production

**Status:** Capture in progress — owner walkthrough resumed 02-Sep-2026  
**Owner input captured:** 02-Sep-2026 (continuing)  
**Code and active decisions checked:** pending — see the research note under `research/`  

This chapter continues the walkthrough from the point where Chapter 1 stopped: the
finished-goods Quality checklist and Product Configuration standards. It uses the
same reading rule as the end-to-end document: VERIFIED, GAP, OPEN, REQUIRED.

Nothing in this file is a decision. A REQUIRED line becomes binding only when it
is recorded through the factory decision system in the owner's words.

## 1. Store Issue to Production/WIP

### Owner input (02-Sep-2026)

Verbatim: "store issue production to wip and they will scan and send it"

Reading: the Store makes a Store Issue. The Store Issue moves material from the
Store to the Production/WIP internal location. The Store scans the bags and sends
them to the floor as part of that issue.

### Standing decisions this touches

- Store Issue to Production/WIP, partial and full return, netting of the next
  request: DEC-20260831-005, DEC-20260831-009, DEC-20260901-001.
- Production/WIP is an internal location of the one operational Store:
  DEC-20260830-002.
- The day bin: the supervisor scans the inward bag barcode at load, on the floor:
  DEC-20260807-006.

### Owner answer (02-Sep-2026)

Asked whether the Store's scan at issue is the only scan, verbatim: "store scan
only, floor does not scan again, so it iwll reach the day bin laod".

### Result

- **VERIFIED as a decision:** DEC-20260902-002. The Store scans the bag once, at
  the Store Issue; the floor does not scan again; the issued bag reaches the
  day-bin load. This supersedes the load-time scan clause of DEC-20260807-006.
  Everything else in that decision stands and is restated in the new record.
- **OPEN:** Q94 — whether the ERP keeps a separate day-bin balance beside
  Production/WIP, fed by the Store's scanned bags, and whether the floor records
  the tip-in at all. The code change that moves the scan cannot be designed until
  Q94(a) is answered.
- **VERIFIED (code, 02-Sep-2026):** the Store Issue screen already scans bags —
  `StoreIssueService::scanBag` behind `HandoverPanel.tsx` — and that scan is the
  resin handover path. It writes the stock ledger only: no `day_bin_movements`
  row and no resin-pool entry.
- **GAP:** today the floor's scan at the day-bin load is still the live path and
  the ONLY inflow to the resin pool and the day-bin estimate. Under
  DEC-20260902-002 that scan goes away, so the Store's scan must feed whatever
  Q94(a) names, or the day-bin figure and the resin provenance (DEC-20260810-001)
  go dark. Detail and citations: [research note](research/2026-09-02-quality-inventory-production-ground-truth.md) §1.

## 2. End-of-day return: bin material stays, everything else may come back

### Owner input (02-Sep-2026)

Verbatim: "yes, and the return policy will not applicabel for Pet risen, other
can be send" / "send back end of the day". Asked whether "other" is by name (only
PET resin excluded) or by flow (anything tipped into the common day bin
excluded): "B, only what goes in the day bin".

### Result

- **VERIFIED as a decision:** DEC-20260902-003. Material that goes into the
  common day bin is never returned to the Store. Every other material — still in
  its bag, box or roll — may be sent back at the end of the day, partially or
  fully, as DEC-20260831-005 and DEC-20260901-001 already provide. Those two
  decisions are narrowed to non-bin materials and otherwise unchanged.
- **GAP:** the return screen today accepts any returnable Store Issue line
  (`production-returns/returnable`, research note §1b). It must refuse a
  bin-material line with a plain message.
- **RESOLVED the same day:** Q94(c) by DEC-20260902-004 — see §3. The refusal
  can now be built once the per-item bin-material flag exists.

## 3. The day bin: PET resin only, and Production/WIP is the bin

### Owner input (02-Sep-2026)

Verbatim: "only pet resin and masterbatch go in the day bin, are we still going to
maintain a seperage page for day bin"; corrected in the next message: "masterbacth
willbe loaded to the machine level not in the day bin". The two drafts and a Codex
review the owner forwarded were then restated by the owner as their own
instruction, with the boundaries now carried by DEC-20260902-004 and
DEC-20260902-005.

### Result

- **VERIFIED as a decision:** DEC-20260902-004. Only PET resin goes into the
  common day bin. Masterbatch is loaded or dosed at the machine and is not a bin
  material. Once masterbatch is in the machine's production system it cannot be
  returned; only unused masterbatch still identifiable in its container can come
  back at the end of the day. Masterbatch consumption stays recorded against the
  batch. The bin-material flag is set per item by an authorised person; the Tally
  stock group may only propose it. An item nobody has flagged is not a bin
  material.
- **VERIFIED as a decision:** DEC-20260902-005. Production/WIP is the day bin
  for PET resin only. The Store's scan at issue is the one event that moves the
  resin into Production/WIP and feeds the resin pool. The floor records nothing
  at the tip-in. The bin balance is the Production/WIP balance of PET resin, a
  drifting estimate. No separate day-bin page, balance or daily action.
  Historical day-bin rows stay untouched.
- **GAP (code):** there is no per-item bin-material flag; the day-bin page and
  its warehouse setting still exist; the Store Issue scan does not feed the resin
  pool; the floor's scan at load is still the live path. The build that follows
  these two decisions is: add the flag, move the resin-pool fold to the Store
  Issue scan, retire the day-bin page and setting, surface the bin figure on the
  Store ↔ Production page, and make the return screen refuse a flagged item.
- **GAP (code):** the day-bin load today accepts any weighed bag, masterbatch
  included; after the build only a flagged item may be treated as bin material.

### Build notes (engineering boundaries inside the two decisions)

Proposed in a Codex review the owner forwarded on 02-Sep-2026; kept where they
sit inside DEC-20260902-004 and DEC-20260902-005 and need no new owner question.

1. **No "loaded" event or status for masterbatch.** The return screen accepts
   only what the Store verifies in an identifiable container; everything else
   is batch consumption. Nothing tracks when masterbatch entered a machine.
2. **The bin-material flag lives on the item master** under the existing
   `inventory.manage` permission that already gates item writes. No new
   permission. The screen proposes the flag where the Tally stock group says
   PET; a person confirms it; nothing sets it automatically.
3. **One transaction at the Store Issue scan:** the stock movement, the bag-scan
   record and the resin-pool fold commit together or not at all. A retried scan
   must not fold the resin twice. A partial issue folds only the issued kg.
4. **The day-bin page retires behind a query-preserving redirect** to the
   Store ↔ Production page, the same component pattern the retired
   material-flow URLs already use in `App.tsx`. Every `day_bin_movements` row
   and every other historical record stays unchanged.

## 4. Finished-goods Quality checklist

### Owner input (02-Sep-2026)

Verbatim: "finished goods quality checklist: checker sees batch, sample count,
weight, visual defects".

Reading: the Quality checker opens a completed batch and works one screen that
shows the batch, takes the number of pieces sampled, takes the sample weight and
compares it with the standard weight, and records the visual defects found.

### Current application

- There is no checklist. The quality stage is a COUNT: reviewed, ok and rejected
  pieces plus an optional note, refused unless reviewed = ok + rejected and
  rejected ≤ produced (`StoreBatchQualityCheckRequest`).
- Four eyes already hold: the checker needs `quality.manage` and cannot be the
  person who completed the batch. The Plant Manager cannot approve until the
  check is recorded (FC-05).
- Rejected pieces are issued out of finished goods and received as scrap at the
  run's frozen unit weight (FC-02, DEC-20260805-001).
- A standard weight exists for every run: the frozen unit weight taken at Start
  Batch from configuration → standard → item master (DEC-20260805-005). Nothing
  compares a measured weight against it today.
- No defect master is verified. The configuration workspace has a Scrap Reasons
  tab, which records why scrap arose, not what a checker saw on a sample.
- Details and citations: [research note](research/2026-09-02-quality-inventory-production-ground-truth.md) §3.

### Owner answer (02-Sep-2026)

Asked whether the checklist replaces the count or sits beside it, the owner
confirmed: it sits beside the count as supporting evidence; the OK and rejected
count remains the only action that changes finished-goods stock and books scrap;
no second stock path; tolerance, sample-size rule and defect list are asked
separately, one at a time.

### Result

- **VERIFIED as a decision:** DEC-20260902-006. The Quality screen after a
  completed batch shows the batch and records sample count, measured weight,
  standard weight (the run's frozen unit weight) and visual observations. That
  record is evidence. The reviewed/OK/rejected count stays the only stock and
  scrap action, under four eyes.
- **GAP:** none of the four evidence fields exists; the screen today takes only
  the count and a note. The build adds the fields beside the count on the same
  screen and changes no stock effect.
- **VERIFIED as a decision:** DEC-20260902-007, the weight check. Quality enters
  the sample count and the total measured sample weight; the ERP divides to get
  the average per piece and compares it with the frozen standard weight using
  the tolerance configured for that product. No default tolerance. Until it is
  configured, both figures show and no verdict is given. Out of tolerance is a
  warning and evidence only; it never moves stock, books scrap or blocks the
  chain. The tolerance figure per product is master data a person enters.
- **GAP:** no tolerance column exists on the product standard, and no screen
  compares a sample weight against the frozen unit weight.
- **VERIFIED as a decision:** DEC-20260902-008, visual observations. A
  maintained list of observation types, master data entered by an authorised
  person, ticked by the checker with an optional count per type, plus one free
  note. Evidence only; never a stock, scrap or approval effect. The entries are
  not invented; until a person enters them the screen offers the note alone.
- **GAP:** no observation-type master exists and the screen has only the note.
- **VERIFIED as a decision:** DEC-20260902-009, the sample count. The checker
  enters how many pieces were sampled each time. No minimum, fixed count or
  per-product rule. Evidence only. A per-product minimum would be a new decision
  adding a warning on top.
- **VERIFIED as a decision:** DEC-20260902-010, the checker rule. The person
  who performs the quality check cannot approve the same batch as Plant Manager.
  The existing allow-same-user flag may relax it only when explicitly enabled
  for a one-person operation, never automatically because someone holds both
  roles. The system records who performed both actions.
- **GAP:** the Plant Manager approval has no comparison against the checker
  today; the build adds the third comparison beside the two that exist, under
  the same flag.

Section 4 is complete: DEC-20260902-006 to -010 cover the screen, the weight
check, the observations, the sample count and the checker rule.

## 5. Incoming Quality for purchased material

### Current application

- Every arrival waits for incoming QA before the Store may issue it
  (DEC-20260831-011). A bag born on a GRN is created `waiting_qc`; the hold is
  enforced at the balance and outranks every outflow door (DEC-20260825-001).
- The inspection is one per GRN line and must cover the whole line: inspected =
  received, accepted + rejected = inspected. A partial inspection is refused.
  Fields: inspected, accepted, rejected quantities, date, notes. Result is pass,
  fail or partial.
- Rejected kilograms leave stock through a "Rejections Out" issue. Whole bags
  that fit the rejected figure flip to `rejected_qc`; the rest flip to
  `in_store`. A bag the rejection ends inside stays `waiting_qc` with a note,
  because splitting one bag's kilograms is an open owner decision with no
  question number.
- Nothing reaches Tally for a rejection. The voucher shape is named open inside
  DEC-20260825-001, DEC-20260830-001, DEC-20260901-003 and DEC-20260901-006, and
  is Accounts' to answer.
- Counted materials (cartons, trays, pouches) create no bags and so are not held.
  How a counted material records what arrived is Q87.
- The page offers a New Inspection form and a per-row view. There is no
  checklist: no sample count, no measured weight against the bag or lot weight,
  no observation list.
- Citations: [research note](research/2026-09-02-quality-inventory-production-ground-truth.md) §2.

### Owner input (02-Sep-2026)

Verbatim: "incoming inspector sees PO, supplier, lot, bag count, sample weight,
visual defects and refer codex and take it only if you okay", with a Codex draft
of the screen. Asked whether the supplier is hidden as FC-06 stands or shown:
"A. Keep the supplier hidden from Quality as FC-06 stands. The PO and GRN
references provide enough traceability without showing supplier details or
purchase rates."

### Result

- **VERIFIED:** the hold, the whole-line inspection, the accept/reject split and
  the Rejections Out issue are built and tested.
- **VERIFIED as a decision:** DEC-20260902-011, the incoming screen. One screen
  per GRN line. Read-only: GRN and PO reference, received date, material,
  received quantity and unit, lot, and for weighed materials the bag count,
  barcodes and receipt weights. No supplier, no rate (FC-06). The inspector
  records bags sampled, measured sample weight, observations from the
  maintained list, one note, and the accepted and rejected quantities. Inspected
  is the full received quantity, never typed. Evidence only; the quantities stay
  the only stock action. Counted packaging hides the bag and weight fields until
  Q87.
- **GAP:** the screen today asks for the inspected quantity and has no sampled
  bags, sample weight or observation fields; the build removes the typed
  inspected figure, adds the evidence fields, and keeps the quantities' stock
  effect exactly as it is.
- **VERIFIED as a decision:** DEC-20260902-012, whole bags only. The inspector
  selects the rejected bag barcodes; the ERP computes the rejected kilograms and
  the accepted remainder. No typed rejected figure, no split bag, no bag left on
  hold after inspection. Counted materials keep typed quantities until Q87.
- **GAP:** the screen today takes a typed rejected quantity and leaves a bag the
  figure ends inside on hold; the build replaces the figure with bag selection.
  Any bag already stuck on hold on live is counted before the build, never
  assumed.
- **VERIFIED as a decision:** DEC-20260902-013. A counted arrival waits for
  incoming QA on its whole line quantity, no bags needed, enforced at the
  balance like the weighed hold. Rejected pieces leave stock through Rejections
  Out. Q87 part two closed.
- **GAP:** today a counted line is issuable the moment its GRN is saved, and a
  counted rejection moves no stock; the build adds the quantity hold and the
  Rejections Out issue for counted lines.
- **VERIFIED as a decision:** DEC-20260902-014. One unit of a counted arrival
  is the supplier's handling unit — bundle, bale, pallet, roll or box. The GRN
  records the unit count, the pieces per unit and one barcode per unit; the
  pieces must sum to the received quantity. Quality accepts or rejects whole
  units; the Store scans the unit at issue; a part-issued unit keeps its
  barcode with a reduced balance. No label per piece; no supplier or rate on
  the label. Q87 closed in full.
- **GAP:** the GRN refuses a lots block for any item not in kilograms; the
  build adds handling units for counted materials, their labels, the unit-wise
  hold release and the unit scan at issue.
- **VERIFIED as a decision:** DEC-20260902-015. No Tally voucher for an
  incoming rejection. The rejected quantity and the Rejections Out reference
  are shown on the Supplier Bill screen against the GRN line so Accounts can
  match the supplier's debit or credit note; Accounts makes the Tally entry from
  the paper. Q68 untouched. Whether the bill may exceed the accepted quantity
  is expressly not decided: the bill stays a record of the paper.
- **GAP:** the Supplier Bill screen does not show the inspection's rejected
  quantity or Rejections Out reference against a GRN line today.

Section 5 is complete: DEC-20260902-011 to -015 cover the screen, whole-bag
rejection, the counted-material hold, handling units and the Tally boundary.

## 6. Finished goods into the Store: no acceptance stage

### Owner input (02-Sep-2026)

Asked whether a Store-acceptance step should exist (Q78's open half): "A" — no
separate stage, with the Codex text quoted in the record's source.

### Result

- **VERIFIED as a decision:** DEC-20260902-016. Complete Batch records the
  finished goods in the Store, as today. Quality, Plant Manager and Accounts
  follow. The Store does not sign again. Its dashboard shows completed,
  Quality-pending, approved and rejected finished-goods quantities. A count
  difference goes back to Production through the existing return-to-production
  action. Q78 closed in full.
- **VERIFIED (code):** completion already receives produced pieces into the
  batch's finished-goods warehouse and return-to-production already exists
  under the quality permission (research note §3b, §3d).
- **GAP:** the Store dashboard has no finished-goods block with those four
  figures; this joins the role-dashboard requirement in the end-to-end map.

## 7. Product standards and the Start Batch gate

### Current application

- A readiness gate checks item active, unit, weight, cycle time, cavities,
  packing count, colour, Tally item, Tally godown and an active machine. As
  shipped it is watch-only (`production.readiness.enforced` is false): every
  gap is a named warning and the batch starts. Only a wrong-product packaging
  or an unresolvable finished-goods location refuses.
- The standards workspace lists every product with its numbered gaps in views
  ready, incomplete and all.
- Citations: [research note](research/2026-09-02-quality-inventory-production-ground-truth.md) §3e–§3f.

### Owner input (02-Sep-2026)

Asked whether the gate should refuse: "A", with the Codex text quoted in the
record's source.

### Result

- **VERIFIED as a decision:** DEC-20260902-017. The gate is enforced: Start
  Batch refuses a product missing unit, weight, cycle time, cavities, packing
  count, Tally item, Tally godown or an active machine configuration, naming
  every missing field. Colour stays a warning. Rollout: not immediately; first
  the workspace is run against live data and every active production product
  is corrected with verified values by an authorised person, nothing invented;
  then the switch is thrown. Inactive or unused products do not delay it.
- **GAP (rollout, not code):** the live readiness view has not been run for
  this purpose. The count of active products not Ready is taken on live, never
  assumed, before the switch.
- **VERIFIED (code):** the switch, the per-check severities and the named-gap
  strings already exist; the build is the rollout and the colour-only warning
  check.

## 8. Batch lifecycle: the approval chain's posting gate

### Current application

- Chain: complete → quality check → Plant Manager → Accounts → synced or
  failed. Accounts approval queues the Stock Journal (one per shift,
  DEC-20260807-010).
- A posting gate can refuse Accounts approval when the voucher is not
  postable; shipped watch-only (`require_postable_voucher` false). A 31-Jul
  owner line lived only in the config comment.
- Citations: [research note](research/2026-09-02-quality-inventory-production-ground-truth.md) §3b, §3f, §4.

### Result

- **VERIFIED as a decision:** DEC-20260902-018. Accounts approval refuses a
  batch whose Stock Journal cannot post, naming the cause. Same rollout as the
  readiness gate: Tally masters loaded and the preview checked against real
  batches on live first, then the switch. Fixture batches exempt.
- **GAP (rollout, not code):** the switch exists; the live preview run and the
  master-data corrections have not been done for this purpose.

## 9. Completion: what a run may consume

### Result

- **VERIFIED as a decision:** DEC-20260902-019. Complete Batch refuses only
  finished goods and the run's own product. Any other off-plan item is an added
  line with a reason and an authorised person. A spare, tooling or unclassified
  item shows its category with a warning and is not blocked; item, quantity,
  reason and person are audited. Nothing is classified automatically. Category
  restrictions come only through a new decision once every active live item is
  categorised. Q90 closed.
- **GAP:** the completion drawer does not show the added item's category or a
  warning today; the refusal set and the audit already exist.

## 10. Start Batch: which packaging

### Result

- **VERIFIED as a decision:** DEC-20260902-020. At most one optional default
  packaging. One option selects itself; several with a default use it; several
  without a default ask the supervisor at Start Batch. The choice is saved in
  the batch snapshot. No forced default. Q45 closed.
- **VERIFIED (code):** the at-most-one-default rule, the Shift Floor question
  on a real choice, and the snapshot already exist; the build confirms the
  single-option auto-select and that the ask happens at Start Batch.

## 11. Start Batch: an override needs a reason

### Result

- **VERIFIED as a decision:** DEC-20260902-021. Of the ten workbook Factory
  Rules, one is enforced: a reason whenever the supervisor overrides the
  configured cycle time or cavities at Start Batch. Limits still refuse; a
  reason never bypasses one. Reason, original value, selected value and person
  go to the snapshot and audit. The other nine stay reference values, Not in
  use. Q93 closed.
- **GAP:** the override reason is recorded when given but not demanded; the
  build makes it required and adds the reader beside the colour map.

## 12. Completion and approvals: variance is advisory

### Result

- **VERIFIED as a decision:** DEC-20260902-022. Expected, actual, variance
  percent and unaccounted kilograms are shown at Complete Batch, Quality
  review, Plant Manager approval and Accounts approval. Both must see them
  before signing. No automatic refusal. Both blocking settings stay disabled;
  no workbook figure is copied. Thresholds, if ever, come through a new
  decision.
- **GAP:** the figures exist at completion; the build shows them on the Quality
  screen and both approval screens.

## 13. Chapter 1 leftovers recorded here

- **VERIFIED as a decision:** DEC-20260902-023, Q59(a). Requisition and PO
  pickers show Raw Material and Packing Material by default. Consumables,
  spares or tooling and unclassified items sit behind a deliberate "show
  additional purchasable items" choice, unclassified with a warning and a
  reason from an authorised person. Finished goods never appear. Every such
  purchase follows the full PR, PO, GRN, incoming Quality and stock workflow.
  Q59(b) and (c) stay open.
- **GAP:** the picker calls the all-items endpoint and the backend accepts any
  existing item; the build adds the default filter, the deliberate choice, the
  finished-goods refusal and the unclassified reason.

- **VERIFIED as a decision:** DEC-20260902-025, the requisition approver
  (supersedes -024 only to withdraw an inferred clause). Any procurement-write
  holder except the requester approves; self-approval refused with a clear
  message; requester, approver and time recorded; no Administrator bypass; the
  Store raises but cannot approve its own. Rejection stays an approver action
  with no requester comparison. A requester withdraws or cancels their own
  requisition through a separate action.
- **GAP:** approval records the approver but never compares them with the
  requester; no withdraw or cancel action exists for a requester. The build
  adds the comparison, the message and the withdraw action.
- **VERIFIED as a decision:** DEC-20260902-026, vendor classification. Five
  classes: Resin; Packaging; Consumables, Spares and Tooling; Service; Other.
  One or more per vendor, set by a person with procurement write; the Tally
  ledger group only proposes. Vendors tab and PO picker show the first three by
  default; Service, Other and Unclassified through an explicit filter.
  Classification never blocks selecting a vendor. Existing vendors stay
  unclassified until reviewed.
- **GAP:** no classification column, no filter, no proposal from the ledger
  group; every live vendor starts unclassified.

- **VERIFIED as a decision:** DEC-20260902-027, Q59(b). The production-input
  flag is the only eligibility rule for store requests and issues; category is
  informational; a flag-category conflict warns and never changes the flag;
  flag changes are audited. Q59(c) stays open.
- **VERIFIED (code):** requests and issues already refuse an item without the
  flag. **GAP:** no conflict warning and no audit of who set the flag.

## 14. Store and held stock

### Current application

- The Store holds finished goods against a confirmed sales-order line, refuses
  to hold more than exists, sends a shortage to Production, and performs the
  dispatch (DEC-20260831-012, DEC-20260901-005). Tally originates the invoice;
  the ERP imports and matches it.
- When two lines want the same bottles the first hold keeps the stock; a Store
  user may re-point a hold with a reason, audited.

### Result

- **VERIFIED as a decision:** DEC-20260902-028, Q62(a). The ERP never moves a
  hold on its own. First hold wins; only a person re-points it.
- **VERIFIED as a decision:** DEC-20260902-029, Q62(b). The Store re-points a
  hold on its own judgement, at once, with lines, quantity, reason and person
  recorded. No approval step.
- **OPEN (one at a time):** Q62(c) customer priority; (d) production request
  queue order; (e) when a request is answered; (f) parallel lines for planning
  dates.

## Sections still to capture

- None. Chapter 2 is complete once Q62(b)–(f) are answered or deferred.
