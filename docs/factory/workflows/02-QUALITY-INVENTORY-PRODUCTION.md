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
- **OPEN:** Q94(c) — how the ERP knows which items are bin materials once the
  floor no longer scans at the bin. No item flag records it today, and whether
  masterbatch goes into the bin is stated nowhere. The refusal cannot be built
  until this is answered.

## Sections still to capture

- Incoming Quality checklist for purchased material.
- Finished-goods Quality checklist.
- Product Configuration standards and what a batch completion checks against them.
- Batch lifecycle: queue, start, run recording, complete, QC, approvals.
- Store, held stock and the finished-goods store acceptance.
