---
name: record-factory-decision
description: Use when the owner confirms a factory rule, reverses one, or answers a pending question — turns the confirmation into an immutable decision record. NOT for ideas, proposals, or anything the owner has not explicitly confirmed.
---

# Record a factory decision

A decision exists when the OWNER has confirmed it and an ARTIFACT evidences
it. Anything less goes to `docs/factory/PENDING-OWNER-QUESTIONS.md` instead.

## Procedure

1. **Check it is actually a confirmation.** The owner's own words, deciding —
   not musing, not you inferring. If in doubt, it is pending, not decided.
2. **Check the evidence.** You need at least one of: a PR number, a commit
   SHA, a Tally journal, the workbook, a dated owner message you can quote.
   Session memory alone is NOT evidence — stop and use PENDING instead.
3. **Read the current decisions in the same scope** in
   `docs/factory/CURRENT-DECISIONS.md`. If the new confirmation contradicts
   one, this is a SUPERSESSION — note the old id. The validator cannot catch
   semantic conflicts; this step is where they are caught.
4. **Record it:**
   ```
   python3 scripts/factory-knowledge/record_decision.py \
     --statement "<plain English, self-contained>" \
     --scope <flow> [--scope <flow>] \
     --confirmed-by owner --confirmed-at YYYY-MM-DD \
     --source-type <pr|commit|tally-journal|workbook|owner-message|owner-confirmation|physical-process> \
     --source-ref "<the exact artifact(s)>" \
     [--supersedes DEC-...]
   ```
5. **Regenerate and validate:**
   `python3 scripts/factory-knowledge/generate_current.py && scripts/factory-knowledge/check.sh`
6. If this answers a pending question, mark that entry resolved with the new
   decision id. Commit the record, the regenerated view, and the question
   edit together.

## Never

- Edit an existing record's statement (history is immutable — supersede it).
- Hand-edit `CURRENT-DECISIONS.md` (generated; validation will fail).
- Record on the owner's behalf because "it's obviously what they meant".
