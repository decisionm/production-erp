# HRMS employee load, attendance punch-report import, and the Ask-the-ERP page — design

Date: 2026-09-03. Approved by the owner in session ("yes approved") with the three
defaults below. Three tracks, built in parallel on separate branches.

Decisions taken with the approval:

1. The Pooja punch-report workbook is parsed **in the browser** (SheetJS, a frontend
   dependency only). The backend keeps its recorded rule of no server-side xlsx
   parser (`ImportProductMasterXlsx`, `ConfigurationImportService`); it receives
   plain JSON rows.
2. The Ask-the-ERP page uses the **Anthropic API** (Claude) from the Laravel backend.
   The key lives in the server `.env` (`ANTHROPIC_API_KEY`). Question text, the
   selected table specs and the result rows shown back to the user leave the server.
3. Employee codes are the Pooja app's IDs (`SPP-nn`). The seven seeded demo
   employees (EMP-001..007) are left untouched for HR to archive
   (DEC-20260812-001: rename or archive, never delete).

## Track 1 — Employee master load

**What:** load the 63 people on the owner's two paper lists into the existing
`employees` table.

**Source data:** `backend/database/data/employees-2026-09.json`, one object per
person: `serial`, `list_name` (as printed), `employee_code`, `name` (the Pooja
app spelling where it exists, else the paper spelling), `department`,
`designation`, `status`, `note`. Department and designation come from the Pooja
app (the system HR already runs); where the paper list disagrees the note says so
(B. Suresh: paper says Plant Manager, app says Production Supervisor — app wins,
note kept).

- 57 names match a Pooja ID.
- 6 paper names (58–63, all packing staff) have no Pooja ID yet: code
  `TMP-58`..`TMP-63`, status active, note "no Pooja ID yet — set the real code
  when HR assigns one". `employee_code` is editable on the Employees screen.
- 2 Pooja IDs (SPP-05 Velvizhi, SPP-46 Jayasundari) are absent from the paper
  list and were absent every day of July: imported with status `inactive`, note
  "in Pooja app, not on the September list".
- `date_of_joining` is unknown for everyone: set to `2026-09-01` with the note
  "joining date not supplied", because the column is NOT NULL. HR corrects it.

**Mechanism:** `php artisan hrms:import-employees {path} [--write]` in
`backend/app/Console/Commands/ImportEmployeesJson.php`, delegating to
`EmployeeService::create` / `update`. Dry-run by default (prints create /
update / unchanged counts). Idempotent: matched on `employee_code`; an existing
row is updated only in name/department/designation/status and only when the
value differs; nothing is deleted. Runs against live after deploy, exactly as the
product-master import does.

**Tests:** feature test that the command creates 65 rows on an empty table, is a
no-op on the second run, never touches EMP-001..007, and refuses a duplicate
code inside the file.

## Track 2 — Attendance punch-report import and correction

**Input:** the Pooja "Employee day wise master report" (`.xlsx`, one sheet). One
block per employee, 12 rows: header text (From/To/Employee Name/Department/
Designation/Employee ID), summary line, blank, then `Day`, `Status`, `First In`,
`Last Out`, `Total OT`, `Late In`, `Early Out`, `Total Hrs` across day columns,
then two blanks. Status values seen: `FD`, `HD`, `Absent`, `Week Off`, `-`.
Times are `hh:mm AM/PM` or `-`. Durations are `01h 47m` / `54m` / `-`.

**Browser parse** (`frontend/src/features/hrms/punchReport.ts`, pure, vitest
pinned with a fixture built from the real July file, names replaced):
`parsePunchWorkbook(sheetRows) -> { period: {from,to}, employees: [{ employee_code,
name, department, designation, days: [{ date, status, first_in, last_out, ot_minutes,
late_minutes, early_minutes, worked_minutes }] }], warnings }`. Unparseable
blocks become warnings, never a crash.

**API** (all under `module:hrms`; GET needs view, POST/PATCH need manage):

- `POST /hrms/attendance-imports` body `{ period_from, period_to, source: 'pooja',
  employees: [...] }` → creates one `attendance_imports` row and one
  `attendance_import_lines` row per employee-day. Returns the import with counts.
- `GET /hrms/attendance-imports` (paged list of runs) and
  `GET /hrms/attendance-imports/{id}/lines?issue=&employee=&page=` (server
  search and paging, per the standing list rule).
- `PATCH /hrms/attendance-imports/{id}/lines/{line}` body
  `{ resolution: 'present'|'half_day'|'absent'|'on_leave'|'week_off', check_in?,
  check_out?, notes? }` → stores the correction on the line AND writes the
  employee-day into `attendances` through `AttendanceService::mark` (upsert).
  A line with no issue is applied on import without a correction step.
- `POST /hrms/attendance-imports/{id}/apply` → applies every unresolved
  no-issue line and every resolved line to `attendances`; refuses (422, with the
  count) while unresolved issue lines remain. Marks the run `applied`.

**Tables:**

- `attendance_imports`: id, source, period_from, period_to, file_name,
  uploaded_by (users), status (`review` | `applied`), employee_count,
  day_count, issue_count, applied_at, timestamps.
- `attendance_import_lines`: id, attendance_import_id, employee_id (nullable —
  null when the code is unknown), employee_code, employee_name, date, raw_status,
  first_in (time, nullable), last_out (time, nullable), ot_minutes, late_minutes,
  early_minutes, worked_minutes, issue (`in_no_out` | `out_no_in` | `no_punch` |
  `unknown_employee` | null), resolution (nullable, the enum above),
  resolved_check_in, resolved_check_out, resolved_by, resolved_at, notes,
  applied_at, timestamps. Unique (attendance_import_id, employee_code, date).

**Issue rules** (server-side, `AttendanceImportService::classify`, unit-tested):

| raw | first_in | last_out | issue | default resolution |
|---|---|---|---|---|
| any | set | missing | `in_no_out` | none — needs a correction |
| any | missing | set | `out_no_in` | none — needs a correction |
| Absent / `-` | missing | missing | `no_punch` | `absent` (one click to confirm, or change to leave) |
| Week Off | — | — | none | `week_off` — stored on the line only; `attendances` has no week-off status, so nothing is written for that day |
| FD | set | set | none | `present` with check_in / check_out |
| HD | set | set | none | `half_day` |
| code not in `employees` | — | — | `unknown_employee` | blocked until the employee exists; a link to the Employees page |

`week_off` is NOT added to `AttendanceStatus`: adding a status to a live enum
touches payroll's day counting, which Q34 says nobody has confirmed. The payroll
export carries week-off from the import lines instead.

**Screen** — HRMS › Attendance Import (`/hrms/attendance-imports`, plus
`/hrms/attendance-imports/:id`):

- Upload button (antd Upload, `.xlsx`), parse in the browser, show the period
  and the counts (employees, days, issues by type), confirm → POST.
- Review table: issue rows first (filter chips: All issues / In without Out /
  Out without In / No punch / Unknown employee / Resolved / Clean), columns
  Employee, Date, Punched (in / out), Issue, Resolution, Notes. Inline
  correction in a small modal: resolution select, time pickers pre-filled from
  the punch, notes. Server search by employee code or name; server paging.
- Apply button, disabled with the remaining-issue count while any issue is
  unresolved. After apply: the download button.
- No explanatory prose on the page (standing rule); labels and counts only.

**Download** — an `ExportKind` (`attendance-month-sheet`, module hrms, permission
`hrms.view`), filter `attendance_import_id`, CSV: one row per employee with
code, name, department, designation, days in period, present, half day, absent,
on leave, week off, worked hours, OT hours, late minutes, early-out minutes,
then one column per day carrying the resolved status code (P / H / A / L / W).
Produced from the import lines so the file matches what was reviewed.

**Tests:** parser vitest on the fixture; service unit tests for classify and
apply refusal; feature tests for the four routes and the export; render test
for the page.

## Track 3 — Ask-the-ERP (schema catalogue + chat)

**Goal:** a chat page where a user asks a question in plain English and gets an
answer computed from the ERP's own database, limited to the modules that user
may view.

### 3.1 Schema catalogue — "one file per table"

`backend/resources/schema-catalogue/<table>.yaml`, one file per business table
(framework tables — cache, jobs, sessions, tokens, activity_log — are excluded).
Shape:

```yaml
table: purchase_orders
module: procurement          # PermissionService::MODULES key; decides who may query it
label: Purchase Orders
purpose: One PO raised on a vendor; lines in purchase_order_lines.
columns:
  - name: id
    type: bigint
    meaning: primary key
  - name: vendor_id
    type: bigint
    meaning: the supplier
    references: vendors.id
  - name: status
    type: string
    meaning: draft | approved | partially_received | closed | cancelled
  - name: total_amount
    type: decimal
    meaning: PO value in INR
    sensitive: rates           # stripped unless the user holds carton-trace.view or finance.view
joins:
  - purchase_order_lines.purchase_order_id = purchase_orders.id
  - vendors.id = purchase_orders.vendor_id
keywords: [po, purchase order, supplier order]
questions:
  - How many open purchase orders does each vendor have?
```

Generation: `php artisan schema:catalogue:generate` reads the live schema
(`Schema::getColumns`, foreign keys) and writes a file per table with
column names, types, nullability and references filled in, and `meaning`
left blank for anything not already annotated. A file that already exists is
merged, never overwritten: annotations survive regeneration. The first pass of
`purpose`, `module`, `meaning`, `sensitive`, `keywords` is written by hand in
this build for every table, using the migrations, models and docs; a test
asserts every non-framework table has a file, every file names a module from
the catalogue, and every column in the database appears in its file.

`sensitive` values: `rates` (purchase / sale rates, costs, amounts — FC-06),
`supplier-identity` (vendor names and contacts — FC-06), `pay` (salary,
payslip figures), `personal` (phone, email, date of birth). Permission needed
to see each: rates → `carton-trace.view` or `finance.view`; supplier-identity →
`procurement.view`; pay → `payroll.view`; personal → `hrms.manage`.

### 3.2 Retrieval

`SchemaRetriever::forQuestion(User $user, string $question, ?array $previousTables)`:

1. Allowed tables = catalogue entries whose `module` the user may view
   (`hasAnyPermission([module.view, module.manage])`).
2. Score each allowed table by lexical match of the question against label,
   keywords, column names and sample questions (stemmed tokens; no embeddings —
   nothing to host). Tables already used by the previous turn get a bonus.
3. Take the top N (8) plus every table they reference through `joins` that is
   also allowed. That set, rendered compactly, is the model's only knowledge of
   the schema.

### 3.3 Generation and execution

`AskErpService::ask(User $user, Conversation $c, string $question)`:

1. Build the prompt: system text (MySQL, SELECT only, use only the tables
   given, always alias, limit rows, prefer aggregates, answer in one sentence
   plus a SQL block), the table specs from 3.2, the last four turns of this
   conversation (question, SQL, answer), then the question.
2. Call the Anthropic Messages API (`config('ask-erp.model')`, default
   `claude-sonnet-5`; timeout 45 s) through a small `AnthropicClient` on
   Laravel's HTTP client. The response is parsed for a single ```sql block and
   a sentence of explanation.
3. `SqlGuard::check($sql, $allowedTables, $columnsToStrip)`: one statement;
   begins with SELECT or WITH; refuses `;`, comments, `INTO`, `FOR UPDATE`,
   `LOCK`, `LOAD_FILE`, `SLEEP`, `BENCHMARK`, `information_schema`,
   `performance_schema`, `mysql.`; extracts every identifier after FROM / JOIN
   (including inside subqueries and CTE bodies) and refuses any not in the
   allowed set; refuses `SELECT *` on a table that has a sensitive column the
   user may not see and refuses a named sensitive column outright; appends
   `LIMIT 200` when no LIMIT is present. Refusal is a 422 with the reason and
   is logged.
4. Execute on the `ask_erp` database connection (same database, configurable
   read-only user via `ASK_ERP_DB_USERNAME` / `ASK_ERP_DB_PASSWORD`, falling
   back to the main credentials; statement timeout via `SET SESSION
   MAX_EXECUTION_TIME=10000`). Rows are returned as-is; the guard has already
   removed what must not be seen.
5. Persist: `ask_erp_conversations` (id, user_id, title, timestamps) and
   `ask_erp_messages` (id, conversation_id, role, question, sql, answer,
   tables_used JSON, row_count, error, duration_ms, timestamps). Result rows
   are not stored (they are re-runnable).
6. Return `{ answer, sql, columns, rows, row_count, truncated, chart:
   {type:'bar'|'line', x, y} | null }`. Chart suggestion is a pure function
   (`chartFor(columns, rows)`: exactly one text/date column and one numeric
   column, 2..60 rows → bar; date column → line).

Failure paths: no API key configured → 503 with "Ask ERP is not configured";
model timeout → 504; model returned no SQL → 422 "could not turn that into a
query"; SQL error → 422 with the MySQL message; guard refusal → 422.

### 3.4 Permission, adoption, routes

- New catalogue module `assistant` ("Ask ERP") in `PermissionService::MODULES`;
  Administrator receives it through the seeder as with every module. Others get
  it through the Roles screen.
- Routes under `Route::prefix('ask-erp')->middleware('module:assistant')`:
  `GET conversations`, `POST conversations`, `GET conversations/{id}`,
  `POST conversations/{id}/ask`, `GET catalogue` (the tables this user may
  query, with labels — the page shows them as chips).
- `ADOPTED_MODULES` gets `assistant`; sidebar entry "Ask ERP" placed directly
  after Dashboard (a standalone leaf, `module: 'assistant'`). Nav test
  updated.

### 3.5 Page

`/ask-erp` (`frontend/src/features/ask-erp/`): conversation list on the left
(new / recent), message thread on the right. A user bubble, then an assistant
card: the sentence, the result table (antd Table, client-paged, sticky
header), the chart (no chart library is added — a small inline SVG bar/line
component), a "Show SQL" toggle, and "Download CSV", built in the browser from
the rows the page already holds (at most 200 rows, already delivered; the
Export Center stays for server-side pulls). Table chips above the input show what this user can ask
about. Errors render inline with the server's message. Enter sends; a request
in flight disables the input.

**Tests:** SqlGuard unit tests (allowed / refused matrix, sensitive-column
stripping, LIMIT appending, subquery table extraction); SchemaRetriever tests
(permission filter, ranking); catalogue completeness test; AskErpService
feature test with a faked Anthropic client; frontend vitest for `chartFor` and
the response reducer; render test for the page.

## Out of scope

Payroll computation changes; employee self-service; leave from the punch file;
biometric device integration; embeddings; writing anything to the database from
the chat page.
