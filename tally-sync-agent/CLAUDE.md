# CLAUDE.md — Tally Sync Agent

A separate TypeScript/Electron codebase inside the ERP repo: the Windows tray
app that bridges the cloud ERP's sync queue to the factory's local Tally.
Root `CLAUDE.md` and `AGENTS.md` still apply here; this file adds only what
is different in this folder.

## Status

**Since 05 Aug 2026 this agent posts real vouchers to the factory's live
Tally** (first proven sync: batch #45), and real Stock Journals have been
read back through it. Treat it as production software touching real books,
not a prototype. The README's body below its status line is scaffold-era
(22 Jul) and marked as such there — trust CI, the ERP-side voucher tests,
and this file over those paragraphs.

## The one safety rule that matters most here

This is the ONLY code that talks to Tally, and it is **agent-initiated and
one-directional**: it polls the ERP's queue, builds XML, posts, acks. Never
build a path where the cloud reaches into Tally, and never post anything
that did not come through the ERP's queue with its voucher preview. A resin
bag belongs to no machine and no batch (FC-01, `docs/factory/`).

## Working here

- Typecheck: `npm run typecheck` (CI runs it as "Tally sync agent typecheck")
- Package: `npm run package:win` — installed on the factory PC by hand;
  deploys of the web app do NOT update the agent
- Site install steps: `SITE-CHECKLIST.md`
- Voucher shape questions: the ERP side owns them —
  `backend/app/Modules/TallySync/` and its tests are the contract
