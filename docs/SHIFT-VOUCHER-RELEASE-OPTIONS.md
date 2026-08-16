# Shift-voucher consolidation — the release rule the owner must pick

**Status: DECIDED — see DEC-20260807-010 / -002 / -003** (07-Aug-2026,
owner): per-shift granularity; release when the shift has ended AND the
voucher has been idle ≥N minutes (default 15) since its last merge, with a
manual accountant "Release now" override — the A+D+override option below;
posting gate unchanged. Q15 and Q17 are resolved; Q16 (flip boundary and
reading the live env first) stays open until the deploy-day flip. The rest
of this paper is preserved as the option analysis that fed the decision.

Related pending question: **Q11** — the accountant's own practice is ONE
consolidated journal per **day**. Per-shift consolidation gives 3 vouchers a
day (roughly 10× fewer than today's per-batch posting, but still 3× the
accountant's habit). Worth settling Q11 and Q17 in the same conversation.

## What exists today (verified 07-Aug-2026, all references current)

- `backend/config/tally-sync.php:31` — `voucher_granularity`, from env
  `TALLY_VOUCHER_GRANULARITY`. At the time of this paper the packaged
  default was `'batch'`: one Stock Journal per approved entry (`SPE-{id}`),
  i.e. per product per machine — the behaviour the owner wanted
  consolidated. (Since the 07-Aug-2026 flip, DEC-20260807-014, the packaged
  default is `'shift'`; `'batch'` stays selectable via the env.)
- **Shift mode already exists**:
  `TallySyncService::enqueueShiftVoucher` (`TallySyncService.php:577`)
  aggregates everything a `(production_date, shift)` produced into one
  Stock Journal `SJ-{Ymd}-S{shift_id}` — production summed per (item, FG
  godown), consumption per (item, issuing warehouse) with godown-name
  resolution and the packing-store split applied (`:697`). Membership rides
  the scalar FK `shift_production_entries.tally_sync_entry_id`, so an entry
  is in exactly one voucher by construction. Entries already vouchered under
  batch mode and local-only fixtures are excluded from the sweep
  (`:596-617`) — a mid-stream flip cannot double-post. Covered by
  `ShiftVoucherGranularityTest` and `VoucherPostedOnceTest`.
- **Why flipping the env today would NOT deliver the ask**: a later approval
  merges into the shift voucher only while it is `Pending` **and**
  `delivered_at IS NULL` (`TallySyncService.php:640-646`), and `pending()`
  stamps `delivered_at` on every agent poll (`:780-783`). The agent polls
  every 90 seconds (`tally-sync-agent/src/config.ts:36`). So the merge
  window is at most 90 s after the first approval; each later approval opens
  a follow-up voucher (`SJ-…-2`, `-3`, …). A real shift, approved entry by
  entry, still yields roughly one voucher per approval — renamed, not
  consolidated.
- **The `delivered_at` guard is correct and stays.** It is the idempotency
  line between a lost acknowledgement and the same voucher posted twice
  into live books (`:767-779`, `VoucherPostedOnceTest`). Consolidation must
  come from a **server-side release rule** — a shift voucher is not offered
  to the agent until the shift is done collecting — not from weakening the
  guard.
- **The tray's "Sync Now" cannot be the release mechanism as-is**: it calls
  the same poll-translate-post-ack cycle (`tally-sync-agent/src/sync.ts:267`,
  `runSyncCycle` → `fetchPending` → `/pending`), which would deliver — and
  thereby freeze — a half-collected voucher. Any manual release has to be a
  server-side state change, not an agent-side poll.

**One structural fact that shapes every option below**: a shift voucher is
*created by the first approval*, not by the shift itself. Approvals run
through the multi-stage review chain and routinely land **after** the shift
has ended — sometimes hours after. A release rule keyed only to shift-end
time therefore often fires before, or the moment, the voucher first exists.

**Scheduler note**: `routes/console.php` currently schedules nothing, and
whether the live host runs `artisan schedule:run` via cron is an explicitly
unresolved hosting question (`TECHNICAL-DOCS.md` §8). A time-based rule does
not strictly need cron — it can be evaluated lazily inside `pending()`
("don't offer this voucher yet"), which the agent already drives every 90 s
— but that is an implementation note, not a design decision.

## The options

### A — release when the shift's `end_time` passes

The `Shift` model already computes overnight production dates
(`Shift.php:39-41`, `NightShiftProductionDateTest`), so "this shift, this
production date, has ended" is answerable for Shift C (22:00→06:00) too.

- **Cost**: small; the shift's own times drive it, so a re-timed shift stays
  correct (shifts are keyed on start time — DEC-20260806-007).
- **Edge — late approvals (the big one)**: any entry approved after the
  shift's voucher has been released opens a `-2` follow-up voucher, exactly
  as the synced-voucher path does today
  (`ShiftVoucherGranularityTest::test_an_entry_approved_after_the_shift_voucher_synced_opens_a_follow_up_voucher`).
  Because approvals mostly happen after shift end, **A alone can degenerate
  to today's fragmentation**: the first post-shift approval creates the
  voucher, the rule says "shift over — release", the next poll freezes it,
  and every later approval is a follow-up. A alone is only sufficient if
  approvals reliably happen during the shift.
- **Edge**: a shift with zero approvals releases nothing — fine.

### B — fixed clock times (06:00 / 14:00 / 22:00)

- **Cost**: cheapest to state, but it duplicates the shift table in
  disguise. The factory's shifts ARE 06:00/14:00/22:00 (DEC-20260806-007),
  so today B ≡ A — until someone re-times a shift and the constants silently
  disagree with the data.
- **Edge**: identical late-approval degeneration as A, plus the constant
  drift risk. Hard to recommend over A on any axis.

### C — manual accountant release

A release button on the voucher, server-side (sets the voucher offerable;
`pending()` skips unreleased shift vouchers). The existing
`VoucherPreviewService` renders the voucher for eyeballing before it goes —
this is the only option that answers Q17 (accountant preview) directly, and
it is the closest to the accountant-trust concern in Q11.

- **Cost**: a small UI + an RBAC permission, and a **human in the loop
  forever**: an unreleased voucher holds its entries out of Tally
  indefinitely — stock in Tally lags until someone clicks. Sundays,
  holidays, leave.
- **Edge**: an approval after release still opens a `-2` follow-up needing
  its own release. Multiple pending shift vouchers accumulate if unattended.
- **Note**: this is NOT the tray's "Sync Now" — see above; that button
  cannot do this job.

### D — idle-hold: release N minutes after the voucher's last merge

Withhold a shift voucher until nothing new has merged into it for N minutes
(each merge rebuilds the payload, `TallySyncService.php:671-685`, touching
the row). Evaluated lazily in `pending()` — no scheduler needed.

- **Cost**: one tunable N and no calendar knowledge. Tracks how approvals
  actually arrive rather than when the shift nominally ended.
- **Edge — N too small**: two approvals N+ε apart split into voucher and
  follow-up — fragmentation returns, just slower.
- **Edge — N too large**: every voucher waits N after its last approval even
  when the batch of approvals is clearly done; Tally always lags by ≥N.
- **Edge**: a steady drip of approvals each < N apart legitimately holds the
  voucher for the whole drip — that is the feature, but it means no upper
  bound on delay without a companion rule.

### A+D combined, manual override kept (the prior reviewer's recommendation — one option among the others, not the decision)

Release when **both** hold: the shift has ended (A) **and** the voucher has
been idle N minutes (D); keep a manual "release now" (C's mechanism) as the
override for the day someone needs the books current immediately.

- **Why the combination**: A supplies the "shift is done collecting" floor,
  D absorbs the post-shift approval trickle that defeats A alone, and the
  override covers the tail (a straggler approval expected tomorrow need not
  hold tonight's voucher).
- **Cost**: the sum of A's and D's small parts plus C's button; three
  concepts to explain to the accountant instead of one.
- **Edge**: an approval landing after A+D released still opens a `-2`
  follow-up — no rule short of "hold forever" avoids that; the follow-up
  path is tested and correct.

## What this paper deliberately does not decide

- Which rule (Q15), the flip boundary (Q16), accountant preview (Q17) —
  the owner's, per FC/AGENTS authority rules.
- N's value, permission names, UI shape — scoped only after Q15 is answered.
- Whether live flips at all before Q11 (telling the accountant) is closed.

## Flip mechanics, whenever the owner picks (for the record, not for now)

- The archived delivery plan (`docs/archive/SHIFT-REDESIGN-DELIVERY.md`)
  gated the flip on the accountant's Day-Book preference plus a live tracer,
  queue drained first. The code guard (`:596-617`) makes a mid-stream flip
  safe against double-posting, but a mid-day flip leaves that date half
  batch-shaped, half shift-shaped in the Day Book — hence Q16: flip at a
  date boundary (before Shift A's first approval) so no date is mixed.
- The LIVE instance's current `TALLY_VOUCHER_GRANULARITY` could not be read
  from here (see Q16 note): the deploy pipeline rsync-excludes `.env`
  (`.github/workflows/deploy.yml:128`) so the repo never sees it, and the
  read-only `tally-sync-status` workflow is unusable during the GitHub
  Actions outage (07-Aug). Every artifact points to `batch` — the default,
  a delivery plan that explicitly deferred the flip, no decision record and
  no workflow setting the key — but that is inference. Verify before any
  flip: SSH `grep TALLY_VOUCHER_GRANULARITY .env` on the live box, or the
  `tally-sync-status` workflow once Actions returns (a queued `SPE-{id}`
  voucher number = batch mode; `SJ-{Ymd}-S{n}` = shift mode).
