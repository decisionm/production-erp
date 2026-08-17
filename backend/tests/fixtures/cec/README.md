# CEC golden files — the owner's sample only

**Status: no sample on file. CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED.**

This directory holds the factory's own CEC sample, when the owner supplies
it, and nothing else. Until then it holds only this file, and
`tests/Feature/Production/CecGoldenTest.php` SKIPS with

    CEC sample not on file — format authority is the owner's

## What a golden file is

A golden file is a CEC **exactly as the factory produces it today** — the
owner's document for one production date (one shift, or the whole day),
saved as CSV verbatim: `<name>.golden.csv`. It is evidence, not a design.
Nobody on the engineering side writes one, edits one, tidies one, or
"reconstructs" one from the ERP's figures: a CEC layout that did not come
from the owner is an invented factory document, and the repo does not carry
those (AGENTS.md — never invent a factory value; FC-06 — no rates or
supplier identity in documentation, so a sample that carries them is not
placed here as-is either; ask the owner for a redacted one).

The CEC's DATA is already live (`GET /api/v1/production/cec`,
`CecReportService`): a thin composition of the Shift Summary and the
completed entries of the date, no new arithmetic. What is missing is the
FORMAT — which figure sits in which cell — and only the sample can say.

## The day a sample lands

1. Place the owner's file here as `<name>.golden.csv`, unchanged.
2. Beside it write the harness's **reading guide**, `<name>.golden.json`,
   transcribed FROM the sample (this is engineering, not authorship — every
   entry points at a cell the owner's file already has):

   ```json
   {
     "production_date": "2026-08-10",
     "shift_id": null,
     "seed": "<name>.seed.php",
     "cells": [
       { "row": 3, "column": 2, "cec": "shifts.0.machines.0.batches.0.expected_pieces" },
       { "row": 9, "column": 5, "cec": "shifts.0.summary.actual_production_kg" }
     ]
   }
   ```

   - `production_date` / `shift_id` — the date (and shift, or null for the
     whole day) the sample is for.
   - `seed` — a PHP file in this directory returning a `Closure(): void`
     that creates the day's records the sample was written from (the
     shifts, machines, items, completed entries, the supervisor's
     target/power row, logs) — the ERP's own records of that day, never
     figures typed to make the test pass.
   - `cells` — every figure cell of the sample, addressed by 0-based
     row/column, and the dot-path into the CEC data it must equal.
3. Run `php artisan test tests/Feature/Production/CecGoldenTest.php`. The
   harness seeds the day, reads the CEC, and asserts for every mapped cell
   that **the sample == the CEC == the Shift Summary == Completed
   Production** (the CEC's summary blocks against
   `GET /production/shift-summaries/report`, its batch figures against
   `GET /production/shift-production-entries`). A `.golden.csv` with no
   reading guide beside it FAILS with a message saying so — a sample on
   file is a call to transcribe it, never something to silently ignore.
4. Only then does `CecExport` (the Downloads slot, blocked today) grow the
   columns and rows the sample shows.
