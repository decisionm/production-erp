# Manufacturing ERP — Development Plan

Companion to `ERP-FEATURES.md` (feature scope) and `TECHNICAL-DOCS.md` (architecture). This document sequences the build.

Stack: React (frontend) + Laravel (API) + MySQL. Deployment model: **single-tenant app** — each company runs its own independent instance and database on its own URL, deployed manually as customers onboard. No multi-tenant SaaS platform, control plane, or provisioning automation for now (see TECHNICAL-DOCS.md §2) — that's an explicit non-goal until/unless company count makes manual deployment painful. Hosting decision (shared/VPS/cloud) deferred until the app is production-ready (build host-agnostic in the meantime — see TECHNICAL-DOCS.md §Deployment).

---

## Guiding principles for sequencing

1. **Core transactional spine first** (Inventory → Purchase → Sales → basic Finance) before India compliance layers on top of it — GST/e-invoicing needs real invoices to attach to.
2. **Keep it a plain single-company app.** No tenancy code, no tenant_id, nothing SaaS-shaped. Deploying to a second company later is a copy-and-configure-a-new-instance exercise, not a code change.
3. **Tally and GST/e-invoicing are the two highest-uncertainty integrations** — spike them early (Phase 1–2), not last, so their constraints inform the finance/inventory data model instead of forcing a rewrite later.
4. **One pilot manufacturing customer, real data, as early as possible.** Internal test data hides workflow gaps (partial deliveries, GRN mismatches, GST edge cases) that only show up with a live user.

---

## Phase 0 — Foundations

**Goal:** a deployable skeleton, not features yet.

- Laravel API skeleton: auth (Sanctum), RBAC/permissions package, base module structure
- React app skeleton: routing, auth flow, layout shell, design system/component library choice
- Basic deployment runbook: the manual steps to stand up a fresh instance for a new company (create DB, run migrations, seed base data, configure `.env`, point domain at it) — a document/checklist is enough for now, no need to script/automate it yet
- CI: lint, test, build pipeline (GitHub Actions or similar)
- Environment strategy: local dev, staging instance

**Exit criteria:** can follow the runbook to stand up a working empty instance from scratch.

---

## Phase 1 — Core Transactional Spine (MVP)

**Goal:** a usable ERP for a single small manufacturer, no compliance layer yet.

- Foundation: company/plant setup, users & roles, master data (items, UOM, customers, vendors, GL accounts)
- Inventory: stock receipt/issue/transfer, warehouses, stock valuation (start with FIFO or moving average — pick one, don't build all methods)
- Procurement: purchase requisition → PO → GRN
- Production (basic): single-level BOM, work orders, material issue to production, finished goods receipt
- Sales: sales order → delivery → invoice
- Finance (basic): GL, AP/AR, manual journal entries, basic P&L/balance sheet
- Basic dashboards (stock levels, open orders)

**Exit criteria:** one pilot customer can run a full order-to-cash and procure-to-pay cycle without leaving the system for anything except statutory filing.

---

## Phase 2 — India Compliance + CRM + Quality core

**Goal:** the system becomes legally usable to actually invoice and pay people in India.

- GST: HSN/SAC on items, tax computation on sales/purchase, GSTR-1/3B-ready reports
- Decide & integrate GSP for e-invoicing (IRN/QR) and e-way bill — see open question in ERP-FEATURES.md
- TDS on purchases (vendor invoices)
- Tally integration spike → build sync agent (start one-directional: ERP → Tally, vouchers only)
- CRM: leads, opportunities, quotations, customer portal (basic)
- Quality (QMS) core: incoming inspection, NCR
- Multi-level BOM, routing, basic MRP (if not already needed by pilot customer)

**Exit criteria:** pilot customer's GST filing and Tally books can be produced/reconciled from ERP data without manual re-entry.

---

## Phase 3 — HRMS + Payroll

**Goal:** HR/payroll fully in-system, including Indian statutory payroll compliance.

- HRMS core: employee master, attendance, leave
- Payroll: salary structure, payroll run, payslips, PF/ESI/PT/LWF, TDS on salary, Form 16
- Employee self-service portal
- Recruitment/onboarding (if needed by customer profile — can slip to Phase 4)

**Exit criteria:** pilot customer runs a full monthly payroll cycle in-system with correct statutory deductions.

---

## Phase 4 — Advanced Manufacturing + Maintenance + Analytics

**Goal:** deepen the manufacturing-specific value (this is what differentiates from generic accounting SaaS).

- Full MRP, capacity planning, subcontracting, batch/serial tracking, scrap/rework
- CMMS: preventive maintenance, asset tracking, spare parts
- Quality: CAPA, calibration, SPC
- Advanced Finance: standard/job costing, budgeting
- BI dashboards, custom report builder
- Vendor & customer self-service portals maturing

---

## Phase 5 — Multi-Company Growth (only if/when it's needed)

**Goal:** handle onboarding more companies without it becoming a bottleneck. Only pursue this phase once manually deploying a new instance per company actually starts hurting — not on a fixed timeline.

- If manual deployment is still manageable, do nothing here — keep deploying instances by hand per the runbook
- If company count grows enough that manual deployment/updates become a real burden (rough threshold: somewhere around 8–10 instances for one person to keep patched), then invest in: a scripted/CI-driven deployment pipeline, and only then consider the fully-isolated-with-control-plane model discussed earlier (or a proper multi-tenant SaaS rebuild) as a deliberate, separate project
- Decide hosting model at scale (see TECHNICAL-DOCS.md — this is explicitly deferred until now)
- Supply chain/logistics module, IoT/shop-floor integration
- Multi-plant/multi-state GST for larger customers

---

## Status as of 2026-07-19

Phases 0–4 are functionally built out (all modules listed below exist under `backend/app/Modules/` with a working API + `frontend/src/features/` UI, and are smoke-tested against the seeded Puducherry bottle-manufacturer demo dataset — see `BottleManufacturingDemoSeeder`). What's actually pending, by phase:

**Phase 0 — Foundations**
- No CI pipeline (`.github/workflows`) exists yet. Lint/test/build (`pint`, `php artisan test`, `npm run typecheck`, `npm run build`) are only run manually per the "Before committing" checklist in `CLAUDE.md`.
- No automated feature-test suite. `backend/tests/` only has PHPUnit's default `ExampleTest.php` (2 tests). Every module has been verified via one-off manual `curl` scripts during development, not via committed, repeatable tests — this is real coverage debt, not just a nice-to-have.
- **RBAC is now actually implemented** (2026-07-19), not just the scaffolding the original line item above implied: real Users CRUD (activate/deactivate, reset password) and a Roles module where roles are built by picking `view`/`manage` per feature module from a live catalog (`App\Modules\Core\Services\PermissionService::MODULES`). Enforced server-side per route group (`App\Http\Middleware\EnsureModulePermission`) and reflected in the SPA's nav (hidden modules a role can't reach). Worth knowing if you touch this: Sanctum's stateful-session guard and spatie/laravel-permission's own guard-name inference interact badly by default (a fresh, unhydrated `Role` instance — e.g. the one Eloquent builds internally for `->withCount('users')` — infers its guard from whatever `config('auth.defaults.guard')` currently is, and `auth:sanctum` mutates that to `'sanctum'` mid-request); `config/auth.php`'s explicit `sanctum` guard entry and `User::guardName()` returning a hardcoded `'web'` are both there specifically to pin that down, not incidental.

**Phase 2 — India Compliance + CRM + Quality**
- GST computation/rates/registrations/GSTR-ready reports, CRM (leads/opportunities/quotations), Quality core (incoming inspection, NCR), multi-level BOM/routing/MRP, and one-directional Tally sync (ERP → Tally, vouchers) are all built.
- **e-Invoicing (IRN/QR) and e-way bill** — not started. Needs a GSP vendor decision first (see open question in `ERP-FEATURES.md`).
- **TDS on purchases** (vendor invoices) — not built. Only sales-side GST exists in the Compliance module.
- **Customer portal** — not built.

**Phase 3 — HRMS + Payroll**
- HRMS core (employee master, attendance, leave) and payroll run/payslip generation with **PF and ESI** are built and verified (`PayrollRunService::calculatePf/calculateEsi`).
- **Professional Tax, LWF, TDS on salary, Form 16** — not implemented.
- **Employee self-service portal** — not built.
- Recruitment/onboarding — not built (explicitly allowed to slip past Phase 3 per this doc).

**Phase 4 — Advanced Manufacturing + Maintenance + Analytics**
- Full MRP, capacity planning, subcontracting, batch/serial tracking, and scrap/rework are built (capacity/subcontracting/batch-serial/scrap-rework all added 2026-07-19).
- CMMS (preventive maintenance schedules, asset tracking) and Quality CAPA/calibration (measuring instruments)/SPC are built.
- Maintenance parts consumption goes through generic stock issue — there's no dedicated spare-parts module (min/max stocking levels, kitting), just plain inventory items used on maintenance work orders.
- **Advanced Finance: standard/job costing, budgeting** — not built. Finance module currently covers GL, AP/AR, manual journal entries, and basic P&L/balance sheet only.
- **BI dashboards, custom report builder** — not built beyond the fixed dashboards/reports already shipped per module.
- **Vendor & customer self-service portals** — not built.

**Phase 5 — Multi-Company Growth**
- Correctly untouched — still gated on the ~8–10 instance threshold, not a fixed timeline.

---

## Suggested team shape (indicative, adjust to actual headcount)

| Role | Phase 0–1 | Phase 2–3 | Phase 4–5 |
|---|---|---|---|
| Laravel/backend dev | 2 | 2–3 | 2–3 |
| React/frontend dev | 1–2 | 2 | 2 |
| DevOps (deployment/hosting) | 1 (part-time) | 1 (part-time) | 1 (full-time, only if Phase 5 triggers) |
| Domain/functional (manufacturing + accounting knowledge) | 1 | 1 | 1–2 |
| QA | shared | 1 | 1 |

---

## Key risks to track

- **Tally sync reliability** — Tally has no cloud API; the local sync agent is a real engineering risk, spike it early (Phase 2), don't assume it "just works."
- **GSP integration cost/complexity** — e-invoicing/e-way bill APIs vary by provider; get pricing and API docs before committing GST architecture.
- **Manual deployment drift** — with each company on its own manually-deployed instance, it's easy for instances to end up on different code versions if updates aren't tracked. Keep a simple log of which company is on which version, even without full automation.
- **Scope creep from feature list** — `ERP-FEATURES.md` is a brainstorm, not a spec. Re-confirm MVP scope with the actual pilot customer's real workflow before building Phase 1, don't build every bullet.
