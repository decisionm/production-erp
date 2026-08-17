# Tally Sync Agent

The local Windows tray app from `docs/archive/TALLY-SYNC-MASTER-PLAN.md` §11/Phase 3 (archived) — bridges the ERP's cloud Tally sync queue to a local Tally installation's XML-HTTP gateway. Runs on-site, on the same machine as Tally (or one that can reach it on the LAN); the cloud ERP never talks to Tally directly.

**Status: the STOCK/MANUFACTURING JOURNAL path is in production since 05 Aug 2026** — posting real production Stock Journals to the factory's live Tally (first proven sync: batch #45) and reading real Stock Journals back. That claim is deliberately scoped: **the Sales-invoice builder (`src/tally/voucherBuilders/salesInvoice.ts`) has NEVER been validated against a live Tally and still emits no GST tax ledger entries (CGST/SGST/IGST)** — do not enable Sales voucher sync on the strength of this status line; its scaffold-era warnings below remain CURRENT for that path. Everything else below this line was written at scaffold stage (22 Jul): for the journal path, read it as pre-production history; for the Sales path, read it as still true. `CLAUDE.md` in this folder carries the current operating rules.

## Architecture

```
Tray app (this machine)
  ├─ sync.ts        — poll loop: fetch pending → build XML → post to Tally → ack/fail back to cloud → snapshot
  ├─ cloudApi.ts     — GET /tally-sync/pending, POST .../ack, .../fail, .../snapshot (Sanctum bearer token)
  ├─ snapshot.ts     — (0.3.8) after each post: {xml, sha256, what Tally answered} uploaded to the cloud as a
  │                    record — after ack/fail, never before; a failed upload is one warn line, never a failed sync
  ├─ version.ts      — the agent's version from package.json, stamped on every snapshot (testable outside Electron)
  ├─ tally/client.ts — POST XML to http://<tallyHost>:<tallyPort>, parse response body for real result
  ├─ tally/voucherBuilders/ — one file per Tally voucher type, dispatched by tally_voucher_type
  ├─ tray.ts         — tray icon + menu (status, Sync Now, Pause, View Logs, Settings, Quit)
  ├─ settings-window/ — small BrowserWindow, IPC bridge to read/write config
  ├─ config.ts        — electron-store, persisted in the OS's per-user app-data folder
  └─ logger.ts         — rotating file log (the audit trail the master plan's §5 requires)
```

## Setup

```bash
npm install
npm run dev      # builds + launches in dev mode (works on macOS/Linux for development;
                  # the app itself is Windows-only in practice, since that's where Tally runs)
```

On first launch with no config, the Settings window opens automatically. Fill in:

| Field | Where it comes from |
|---|---|
| Cloud API base URL | Your ERP instance, e.g. `https://erpdemo.amrtech.in/api/v1` |
| Cloud API token | A Sanctum token scoped to `tally-sync:poll` + `tally-sync:report` — see below |
| Tally host / port | `127.0.0.1` / `9000` if the agent runs on the same machine as Tally |
| Tally company name | Must match exactly what's shown in Tally, and that company must be loaded in the Tally UI |
| Poll interval | Seconds between sync attempts — 90 is a reasonable default per the master plan's §3 |

### Getting a Sanctum token

In the ERP, go to **Tally Sync → Agent Tokens** and click **Generate Token**. Name it after the machine/installation it's for (e.g. "Agent - Puducherry Line 1") — each install should get its own token so any one of them can be revoked independently without affecting the others. The plaintext token is shown exactly once; copy it straight into this app's Settings window's "Cloud API token" field, since it can't be retrieved again afterward (only revoked and reissued).

Tokens are scoped to exactly `tally-sync:poll` + `tally-sync:report` and belong to a dedicated, password-less service account (`tally-sync-agent@system.local`) that the ERP auto-provisions the first time a token is issued — it's excluded from the regular Users list and can't log in interactively.

## Packaging for Windows

```bash
npm run package:win
```

Produces a signed-if-configured NSIS installer under `release/`. Code signing isn't set up in this scaffold — an unsigned installer will trigger a Windows SmartScreen warning on first run; get a code-signing certificate before real rollout if that matters to you (a self-published internal tool can often get away without one, but it's a rough first-run experience for non-technical staff).

`app.setLoginItemSettings({ openAtLogin: true })` in `main.ts` handles auto-start — no separate Task Scheduler entry needed.

## What still needs a real Tally instance to finish

This is the honest gap — none of the following can be verified without a live Tally install, which wasn't available while scaffolding this:

1. **Validate the XML voucher shape.** Per the master plan's §3 gotcha: create one real Sales voucher and one Journal voucher by hand in Tally, export each as XML (Gateway of Tally → Display/Export → the voucher → Export), and compare tag-by-tag against `src/tally/voucherBuilders/salesInvoice.ts` / `journalEntry.ts`. Fix whatever doesn't match — Tally's accepted structure varies a little by version, so treat the current builders as a starting skeleton, not a finished implementation. `salesInvoice.ts` in particular assumes a single "Sales Account" ledger and doesn't yet emit GST tax ledger entries (CGST/SGST/IGST) — those need the real ledger names from the target company's chart of accounts.
2. **Confirm `tally/client.ts`'s response parsing is checking the right fields.** `postVoucherXml()` looks for `CREATED`/`ALTERED`/`ERRORS`/`LINEERROR` in Tally's response — log `rawResponse` on a real failure and adjust if the actual shape differs.
3. **Add the Manufacturing Journal voucher builder** (master plan Phase 4) — once Phase 2's `ShiftProductionEntry` extension (material consumption, regrind/loss split, approval gate) ships on the cloud side, this agent needs a third builder file plus one new `case` in `voucherBuilders/index.ts`. No other agent code changes.
4. **Masters pull (Phase 5)** — nothing here yet. Needs an AlterID-tracking poll loop (separate, slower interval than the production-sync loop) plus a new cloud-side upsert endpoint this agent posts to.

## Known dependency notes

**This paragraph previously said the `electron-builder` advisories were left unfixed. On candidate 0.3.6 they are fixed.** `electron-builder` is pinned EXACTLY to `26.15.3` (no caret — the tool that builds the factory installer must not drift without review), which cleared the packaging-chain advisories; one remaining transitive patch took the rest. As of this candidate both `npm audit` and `npm audit --omit=dev` report **0 vulnerabilities**, so the runtime closure and the build/packaging toolchain are clean together. Electron itself is current.

**What that does NOT establish.** These are dependency-resolution and audit facts only. `npm run package:win` has not been run for this candidate — it requires Windows — so the NSIS installer, `latest.yml`, the `.blockmap` and the `tally-sync-agent-setup-<version>.exe` filename remain **unverified by execution** on `electron-builder` 26. Nothing here claims this candidate has been packaged, published, installed, or deployed. Confirming the artifact needs a non-publishing Windows CI run; publishing is a separate, deliberate manual dispatch (see the release ritual in the repo root's `DEPLOY.md`).

## Security reminders (from the master plan §5 — don't skip these)

- Tally's port 9000 has **no authentication of its own**. Never expose it beyond localhost/LAN.
- This agent should be the *only* thing that ever talks to port 9000 — the cloud app never does, and never should.
- All real authentication lives on the agent→cloud hop (the Sanctum token above), not anywhere near Tally.
- Keep the shift-close **approval gate** (master plan §4a) in place on the cloud side — it's the actual access-control checkpoint compensating for Tally's gateway having none.
