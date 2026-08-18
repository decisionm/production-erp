# CLAUDE.md — Tally Sync Agent

A separate TypeScript/Electron codebase inside the ERP repo: the Windows tray
app that bridges the cloud ERP's sync queue to the factory's local Tally.
Root `CLAUDE.md` and `AGENTS.md` still apply here; this file adds only what
is different in this folder.

## Status

**Since 05 Aug 2026 this agent posts real production STOCK JOURNALS to the
factory's live Tally** (first proven sync: batch #45), and real Stock
Journals have been read back through it. Treat it as production software
touching real books, not a prototype.

**The Purchase Order builder (0.3.9, `purchaseOrder.ts`) has never posted
either — by design.** Its shape is measured on the factory's real 12-Aug-2026
exports (structure only; the exports stay outside the repo — Q38) and it is
an ORDER voucher that Tally posts to neither accounts nor stock BY TYPE
(DEC-20260812-002). The cloud stages one only while
`tally-sync.purchase_orders_enabled` is on, and that flag is OFF until the
owner opens the gate (Q35). `docs/tally-sync/PO-VOUCHER-CONTRACT.md` is the
contract; `tests/purchaseOrder.test.js` and its synthetic golden are the
proof. 0.3.9 is built and tested, NOT published.

**The Sales-invoice builder is NOT part of that claim.** It has never
posted to a live Tally and emits no GST tax ledger entries — enabling
Sales sync without validating it first would post GST-less sales vouchers
into real books. The README's scaffold-era warnings stay CURRENT for that
path; for the journal path they are history. Trust CI, the ERP-side
voucher-shape tests, and this file over the README's older paragraphs.

## The one safety rule that matters most here

This is the ONLY code that talks to Tally, and it is **agent-initiated and
one-directional**: it polls the ERP's queue, builds XML, posts, acks. Never
build a path where the cloud reaches into Tally, and never post anything
that did not come through the ERP's queue with its voucher preview. A resin
bag belongs to no machine and no batch (FC-01, `docs/factory/`).

## Working here

- Typecheck: `npm run typecheck`. CI runs `npm ci && npm test` as "Compile
  and test agent" — `npm test` builds with `tsc`, so compilation IS the
  typecheck there
- Tests: `npm test` — a preflight that refuses an empty test set, then the
  build, then `node:test` against the compiled `dist/`. No dependencies
- Package: `npm run package:win` — but real installers come from the
  *Build Tally Sync Agent* CI workflow (Windows runner), and releasing one
  follows the RELEASE RITUAL in the repo root's `DEPLOY.md`: build on CI →
  review gate → **merge, which builds a CANDIDATE ARTIFACT ONLY** → manual
  publish dispatch **on main** with `publish: true`, which rebuilds and
  publishes. Merging does NOT publish and does NOT reach the factory; a
  dispatch from a non-main branch cannot publish even with `publish: true`.
  Publishing IS deploying — the factory agent auto-updates from the
  published feed within hours — so never dispatch with `publish: true` for
  unreviewed code. Deploys of the web app do NOT update the agent
- Site install steps: `SITE-CHECKLIST.md`
- Voucher shape questions: the ERP side owns them —
  `backend/app/Modules/TallySync/` and its tests are the contract
