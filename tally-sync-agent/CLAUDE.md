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

- Typecheck: `npm run typecheck` (CI runs it as "Tally sync agent typecheck")
- Package: `npm run package:win` — but real installers come from the
  *Build Tally Sync Agent* CI workflow (Windows runner), and releasing one
  follows the RELEASE RITUAL in the repo root's `DEPLOY.md`: build on CI →
  review gate → manual publish dispatch. Publishing IS deploying — the
  factory agent auto-updates from the published feed — so never dispatch
  with `publish: true` for unreviewed code. Deploys of the web app do NOT
  update the agent
- Site install steps: `SITE-CHECKLIST.md`
- Voucher shape questions: the ERP side owns them —
  `backend/app/Modules/TallySync/` and its tests are the contract
