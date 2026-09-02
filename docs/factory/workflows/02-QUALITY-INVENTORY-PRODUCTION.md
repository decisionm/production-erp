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
- **OPEN (asked one at a time, in this order):**
  1. Weight: the measured sample weight against the frozen standard weight. Is
     there a tolerance, and who states it? Not to be invented.
  2. Visual observations: a maintained list, or free text?
  3. Sample count: entered by the checker each time, or a fixed rule per product?
  4. May a checker who also holds Plant Manager approve their own check? The
     code leaves this as a comment, with no question id.

## Sections still to capture

- Incoming Quality checklist for purchased material.
- Product Configuration standards and what a batch completion checks against them.
- Batch lifecycle: queue, start, run recording, complete, QC, approvals.
- Store, held stock and the finished-goods store acceptance.
