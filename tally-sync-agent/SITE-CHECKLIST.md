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

## Upgrading an existing site (installing over a running agent)

The steps above are the FIRST install. An upgrade is smaller, but two of its
steps exist because skipping them has already caused trouble once each:

- [ ] **Quit the running tray app first** (right-click tray icon → Quit).
      Installing while an old build is alive is how two agents once polled the
      same queue at the same time (the v0.1.5 confusion)
- [ ] Get the new installer. The normal source is the ERP's own page:
      **Tally Sync → Settings → "Download Tally Sync Agent (Windows)"** —
      but that button serves whatever was last PUBLISHED, so first check the
      "Latest version: X · built DATE" line under it says the version you
      came to install. If it still shows the old version (a pre-review build
      is deliberately not published there), carry the installer by USB or a
      trusted download instead
- [ ] Run it — same folder, over the old install. SmartScreen warning
      expected while unsigned ("More info → Run anyway")
- [ ] **Verify settings survived:** tray icon reappears; open Settings… —
      cloud URL, agent token, Tally host (`127.0.0.1`), port (`9000`), exact
      company name, poll interval must all still be filled in. They live in the
      user profile (`%APPDATA%`), which the installer does not touch. **If
      anything is blank, STOP and call — do not retype the token from memory**
      (it is shown only once at creation; a blank here means issuing a new one)
- [ ] Normal operation intact: vouchers sync as before within a couple of
      minutes (or "Sync Vouchers Now" runs clean against an empty queue)

## First probed Stock Summary read (v0.3.2+)

Background, so the caution makes sense: on 07 Aug 2026 the one-shot read
(v0.2.0) crashed the live Tally from ONE click; the group-chunked read
(v0.3.0) wedged TallyPrime twice on its first chunk; and v0.3.1's heavy
fetch of a 12-item ungrouped scope hung it a third time even though a
canary had passed on a named group. Since v0.3.2 EVERY scope is light-
probed immediately before its own heavy request, the ungrouped scope is
always read one item at a time (as is any group over the cap), the risky
scopes run last, and an item whose single-item fetch times out is
BLACKLISTED on disk — named in the log, skipped by every later run — so
each attempt either completes or eliminates exactly one culprit. The
button is disabled while a read runs and the tray narrates every step.
Treat the first run as a small test:

- [ ] **Quiet window only:** after production hours, no batches posting
- [ ] Click **Read Stock Summary (preview only)** — ONCE
- [ ] Watch the tray label: "listing stock items…" → "checking Tally's group
      scoping (canary…)" → "group 1/N — …" counting up (an oversized group
      shows "item 45/612" style progress and takes a few minutes — that is
      normal) → "sending to ERP for preview…" → a final line like
      "✓ Last stock read: 653 line(s) sent for preview — nothing imported".
      Tally should stay responsive throughout
- [ ] If the read stops saying a **canary failed**: nothing heavy was sent,
      Tally is fine, and no retry will help — tell the developers. That stop
      is the protection working, not a malfunction
- [ ] **Abort rule:** if Tally visibly goes sluggish during any scope, stop —
      click nothing further. **Restart Tally FIRST** (Task Manager → end the
      TallyPrime task if the window is frozen → reopen → load the company),
      then click the read once more: it resumes where it stopped instead of
      starting over. (A second click while it runs does nothing — the button
      is disabled; that protection is the point)
- [ ] If the final line warns **"INCOMPLETE COVERAGE"**, the snapshot is still
      safe (nothing invented, nothing imported) — but tell the developers
      before anyone trusts it as an opening position

## Leave-behind rules (tell whoever minds the PC)

1. Keep this PC on and Tally open with the company loaded; if it's off, production sync simply waits — nothing is lost
2. Don't expose port 9000 beyond this machine; the agent is the only thing that talks to it
3. If the agent tray icon shows red/errors for more than a shift, call us — don't reinstall anything
