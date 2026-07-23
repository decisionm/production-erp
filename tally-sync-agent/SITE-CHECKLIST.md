# Factory Site-Visit Checklist — Tally Sync Agent Install

Internal, development-side checklist — deliberately kept **out** of the client questionnaire (`tally-query.txt`), per team decision: these are things *we* verify on the Tally machine during the install visit, not questions the accountant answers on paper. Companion to the master plan's §5 (security) and this project's README.

## Before the visit

- [ ] Agent Windows installer built (`npm run package:win`) and copied to a USB stick / download link
- [ ] An **agent token** generated in the target ERP instance (Tally Sync → Agent Tokens), named after the site (e.g. "Agent - Puducherry"), plaintext copied somewhere retrievable during the visit — it's shown only once
- [ ] ERP instance URL confirmed reachable from a phone on the factory's network (proves their internet works for outbound HTTPS)

## On the Tally machine

- [ ] **Which PC runs Tally?** Note its Windows version and whether it stays **on during all production shifts** (incl. night shift). If it's shut down at night, agree who powers it on / whether it should stay on
- [ ] **TallyPrime version** (Help → About) — note the exact release; XML behaviour varies by release
- [ ] **Licensed, not education mode** — education mode restricts voucher dates and invalidates testing
- [ ] **Gateway enabled:** F1 → Settings → Connectivity → "TallyPrime acts as" = **Both** (or Server), port **9000**
- [ ] **Gateway reachable locally:** `curl http://127.0.0.1:9000` from the same machine answers (any response = alive)
- [ ] **Gateway NOT reachable from outside:** from another device on the LAN, port 9000 should be refused unless we explicitly decide the agent runs on a different LAN machine. Check Windows Firewall inbound rules — Tally's installer sometimes opens the port wide
- [ ] **Company loaded:** the real production company (not a test/demo company) is open in Tally, and its **exact name** (spelling/spacing) recorded for the agent's Settings
- [ ] **TallyVault:** note whether the company requires a vault password to open — if yes, sync only works while someone has opened the company that day; agree on the operating routine
- [ ] **Financial year:** note "books beginning from" — vouchers dated outside the active year are rejected

## Agent install

- [ ] Run the installer; SmartScreen warning expected while unsigned ("More info → Run anyway")
- [ ] Settings filled: cloud URL, agent token, Tally host (`127.0.0.1`) + port (`9000`), exact company name, poll interval (90s default)
- [ ] Tray icon shows connected; "Sync Now" runs without error against an empty queue
- [ ] Auto-start verified: reboot the PC once, agent reappears in tray without login to the app
- [ ] Audit log location noted and rotating (tray → View Logs)

## Proof of life (exit criteria for the visit)

- [ ] One test voucher (Sales or Journal) queued from the ERP reaches Tally and appears in the correct company
- [ ] The ERP's Tally Sync dashboard shows it **synced** (ack round-trip works)
- [ ] Tally restarted mid-test once: queued voucher survives and syncs after Tally is back
- [ ] The voucher's XML request/response pair captured from the agent log for the repo's validation records

## Leave-behind rules (tell whoever minds the PC)

1. Keep this PC on and Tally open with the company loaded; if it's off, production sync simply waits — nothing is lost
2. Don't expose port 9000 beyond this machine; the agent is the only thing that talks to it
3. If the agent tray icon shows red/errors for more than a shift, call us — don't reinstall anything
