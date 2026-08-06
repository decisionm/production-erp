# AGENTS.md — shared rules for every agent in this repo

For Claude, Cursor, Codex and any other agent. Short on purpose: the detail
lives where it is pointed to, once, and nowhere else.

## Authority

- **The owner is the only authority for factory business decisions.** An
  agent proposes; the owner decides. A discussion, an inference, a test
  result, a transcript, or an agent's memory is **never** a decision.
- Owner decisions live in `docs/factory/decisions/` as immutable records.
  A changed decision is a NEW record superseding the old — history is never
  rewritten or deleted. Records are TOOL-WRITTEN only (canonical JSON,
  validated byte-for-byte — DEC-20260806-012); humans read
  `CURRENT-DECISIONS.md`, never a raw record file (FC-08).
- Open questions live in `docs/factory/PENDING-OWNER-QUESTIONS.md`. If you
  need an answer that is not recorded: add the question there and stop that
  part. Do not choose for the factory.

## Before reasoning about the factory

1. Read `docs/factory/FACTORY-CONSTITUTION.md` — the durable boundaries.
2. Read `docs/factory/CURRENT-DECISIONS.md` (generated) for the scope you
   are touching; the full records are in `decisions/`.
3. Read ORIGINAL evidence before derived data or old transcripts —
   `docs/factory/SOURCE-PRIORITY.md` says what outranks what, and
   `docs/factory/sources/manifest.yaml` says where the originals are.
4. Run `scripts/factory-knowledge/check.sh` — if the knowledge system is
   broken, fix or report that before trusting it.

## Evidence discipline

- **Memory is not evidence.** A factory claim needs an artifact: a PR, a
  commit, a Tally journal, the workbook, a dated owner message.
- Never invent a factory value — a weight, a cycle time, a dose, a Tally
  name. A missing figure is reported missing, never interpolated. This rule
  has been earned: a derived bag weight reached live once and was withdrawn
  (PR #128).
- Count on the LIVE instance, never on dev fixtures, when the question is
  about live data.

## Hard safety lines (all agents, no exceptions)

- Do not post a Tally voucher, create/cancel a production batch, or change
  production stock as a side effect of any documentation or tooling task.
- Live master-data changes go through the manual GitHub workflows,
  **dry-run first**, write only after reading the dry run.
- Do not put secrets, tokens, purchase rates, or private Tally contents in
  documentation. Purchase rates are Owner/Accounts only (FC-06).
- A resin bag belongs to no machine and no batch (FC-01).

## Review chain for agent-built work

Builder (with testing evidence) → Cursor review → Codex verification →
owner. Work lands on a branch and is not merged before that chain completes.
