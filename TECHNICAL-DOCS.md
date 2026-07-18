# Manufacturing ERP — Technical Documentation

Companion to `ERP-FEATURES.md` (feature scope) and `DEVELOPMENT-PLAN.md` (build sequencing).

---

## 1. Stack

| Layer | Choice | Notes |
|---|---|---|
| Frontend | React + TypeScript (Vite) | Standalone project at `/frontend`, sibling to `/backend` — not nested inside Laravel's `resources/`. SPA calling the Laravel API. `npm run build` writes directly into `backend/public/build/`; Laravel serves that build statically for every non-API route. See §3 for why this is a separate project rather than the more common Laravel-convention `resources/js/` approach. |
| Backend / API | Laravel (latest LTS-equivalent) | REST API, `laravel/sanctum` for auth tokens |
| Database | MySQL | One database per company instance (see §2) |
| Queue/cache | Redis preferred, DB-driver fallback if Redis unavailable on a given host | Needed for background jobs (payroll runs, report generation, Tally sync, email/SMS) |
| Search (optional, later) | MySQL full-text initially; Meilisearch/Typesense if item/customer search grows large | Not needed for MVP |

---

## 2. Deployment Model: Single-Tenant App, One Instance per Company

**Decision:** multi-tenant SaaS is explicitly not a near-term goal. Build a clean **single-tenant** application — one company, one database, one deployment. Each customer gets their own separately deployed instance on their own URL (e.g. `companyname.yourdomain.com` or their own domain), fully independent of every other customer's instance. No shared control plane, no tenant registry, no provisioning automation required for now.

### What this means concretely

- **The app has zero tenancy concept in the code.** No `tenant_id`, no per-tenant scoping, no landlord/tenant distinction anywhere in Laravel or React. It's a normal single-company Laravel app, same as if you were building this for one client only.
- **"Multi-tenancy" happens entirely at the infra level, manually**, by deploying another independent copy of the same codebase with its own database when a new company signs up. From the code's perspective there is only ever one company.
- **This significantly simplifies Phase 0** (see `DEVELOPMENT-PLAN.md`) — no provisioning script, no control plane app, no update-rollout tooling needed to start. A short manual deployment runbook is enough at low customer counts.
- **Keep this decision revisitable, not baked in.** Avoid anything that would make it painful to later centralize (e.g. don't hardcode single-company assumptions into things like file storage paths or cron job names in a way that would need rework). If/when you have enough customers that manually redeploying N separate instances for every bug fix becomes painful, that's the trigger to revisit the fully-isolated multi-tenant model described in an earlier draft of this doc (control plane + provisioning script) — not before.

### Per-instance components

- One Laravel app + one MySQL database per company, own `.env` (DB credentials, app key, mail/SMS credentials)
- Own subdomain or custom domain, own SSL cert
- Own queue worker/scheduler process (or shares a host's cron/queue setup if several company instances end up co-located on the same server)

### Packaging

- Simplest: each company's instance is just a normal Laravel deployment (PHP-FPM + Nginx/Apache vhost, own DB) on whatever host is chosen later
- If Docker is used anyway for consistency between dev/staging/prod, each company instance is simply "run this container against this company's `.env`" — no orchestration needed, just repeat the same `docker run`/`docker-compose up` per company on its own host or same host under a different port/vhost
- No need to decide k8s/orchestration now — only relevant if you later centralize many instances

---

## 3. Application Architecture

### Backend (Laravel) module structure

Organize by domain module, not generic MVC buckets, so the ERP modules stay navigable as the app grows:

```
app/
  Modules/
    Inventory/      (Models, Http/Controllers, Services, Requests)
    Procurement/
    Production/
    Sales/
    Finance/
    HRMS/
    Payroll/
    Quality/
    Maintenance/
    Compliance/      (GST, TDS, e-invoicing, e-way bill logic)
    TallySync/
  Shared/            (cross-module: users, roles, audit log, notifications, documents)
```

- Each module: own migrations, own Eloquent models, own service classes for business logic (keep controllers thin), own form request validation classes.
- Cross-module references (e.g. Sales order consuming Inventory stock) go through service interfaces, not direct Eloquent queries into another module's tables — keeps modules loosely coupled so features from `ERP-FEATURES.md` can be built/toggled independently.
- Background jobs (`app/Jobs/`) for anything slow or external: payroll run, GST report generation, Tally sync, email/SMS, PDF generation (invoices/payslips).

### API design

- REST, versioned from day one: `/api/v1/...`
- Auth: Laravel Sanctum (SPA token/cookie auth since React is a first-party client, not third-party API consumers — simpler than OAuth/Passport for this use case)
- Standard response envelope (data/meta/errors) and consistent pagination
- Form Request classes for validation, API Resource classes for response shaping (don't return raw Eloquent models)
- RBAC via a permissions package (e.g. `spatie/laravel-permission`) — roles like Admin, Plant Manager, Accountant, Store Keeper, HR mapped to module-level and action-level permissions

### Frontend (React) — `/frontend`, a standalone project

**Why not nest React inside Laravel's `resources/js/`?** That's the more common Laravel-ecosystem convention (it's how the official Breeze/Jetstream React and Inertia starter kits do it), and it was the initial approach here too. Reconsidered once the app's actual shape became clear: an ERP with 8-10 large modules is a big frontend codebase in its own right, and the API is explicitly meant to be reusable by other clients later — bundling the frontend inside the backend framework's resource folder understates that separation (mixed git history, competing tooling roots, "second-class citizen" feel). Moving it to a sibling `/frontend` project costs nothing operationally: `npm run build` still writes straight into `backend/public/build/`, and Laravel's catch-all route still just serves that build's `index.html` statically — same single-deployable-unit, same shared-hosting compatibility, cleaner separation.

- Feature-folder structure mirroring backend modules (`src/features/inventory`, `src/features/sales`, etc.)
- State: server state via React Query/TanStack Query (handles caching, refetching — good fit for CRUD-heavy ERP screens); local/UI state via a light store (Zustand) — avoid a heavy global Redux store for what's mostly server data
- Forms: React Hook Form + schema validation (Zod) matching backend validation rules where practical
- Component library: **Ant Design** — ERP UIs are data-table/form heavy; Ant Design's Table/Form/DatePicker primitives are purpose-built for exactly this (it was originally built by Alibaba for admin/dashboard apps)
- Routing: role-based route guards matching backend permissions
- Local dev: Vite's dev server proxies `/api` and `/sanctum` to the Laravel dev server (see `frontend/vite.config.ts`), so the browser always sees same-origin requests — no CORS config needed in either environment

---

## 4. Database

- One MySQL database per company instance, same schema (migrations) across all instances since they share one codebase
- Migrations live in the shared codebase; a fresh company deployment runs them once at setup, an existing instance runs new migrations on each update/redeploy
- Standard conventions: singular model / plural table, foreign keys with `on delete restrict` by default for transactional data (don't cascade-delete financial records), soft deletes on master data (items, customers, vendors) so history isn't lost when something is "deleted"
- Audit trail: a generic `audit_logs` table (or a package like `spatie/laravel-activitylog`) capturing who changed what, when — needed for financial/payroll data regardless of module
- Money/quantity fields: use decimal columns (not float) for anything financial or stock-quantity related

---

## 5. India Compliance Layer (technical notes)

- Tax computation, HSN/SAC mapping, and GST return report generation live in the `Compliance` module, invoked from Sales/Purchase modules rather than duplicating tax logic in each
- e-Invoicing (IRN) / e-Way Bill: integrate via a GSP (GST Suvidha Provider — e.g. ClearTax, Vayana, MasterGST) API rather than the raw NIC APIs directly; wrap the chosen GSP behind an internal interface (`EInvoiceProviderInterface`) so the provider can be swapped without touching calling code
- TDS/PF/ESI/PT calculations: encode as configurable rule tables (rates/slabs change with government notifications) rather than hardcoded constants — expect to update these periodically
- Keep a compliance calendar/due-date job that queues reminders (GST filing, TDS deposit, PF/ESI due dates)

---

## 6. Tally Integration (technical notes)

- Tally (Prime/ERP 9) exposes a local XML-over-HTTP API on port 9000 on the machine it runs on — it is **not** cloud-reachable directly
- Since the ERP is (eventually) cloud-hosted and Tally typically runs on-prem at the customer's office, integration needs a **local sync agent**: a small lightweight service (could be a simple scheduled script, or a minimal Laravel/Node process) installed on the customer's LAN that:
  1. Polls the cloud ERP API for pending vouchers/masters to push to Tally
  2. Talks to local Tally via its XML API to create/update vouchers
  3. Reports sync status back to the cloud ERP
- Start one-directional (ERP → Tally) to reduce conflict-resolution complexity; only build two-way sync if a real customer need justifies it
- Store sync status/errors per voucher so failures are visible and retryable, not silent

---

## 7. Security

- Each company instance is fully isolated by virtue of being a separate deployment — reinforce with unique `.env` secrets (`APP_KEY`, DB credentials) per instance, never reused across companies
- All API traffic over HTTPS (subdomain or custom domain SSL per instance — wildcard cert if using a shared `*.yourdomain.com` pattern, or per-domain cert via Let's Encrypt if custom domains)
- Password hashing via Laravel defaults (bcrypt/argon2), enforce password policy, add 2FA for admin/finance/payroll roles at minimum
- Rate limiting on API (Laravel's built-in throttle middleware)
- File uploads (documents, attachments) scanned/validated by type & size, stored outside webroot
- Backups: automated DB backups per company instance — each instance needs its own backup schedule, not a shared one
- Sensitive fields (bank account numbers, PAN, Aadhaar if ever stored) — encrypt at rest (Laravel's encrypted casts)

---

## 8. Deployment (host-agnostic by design — decision deferred)

Per the plan, the hosting decision (shared hosting / VPS / cloud) is deferred until the app is production-ready. To keep that decision genuinely open, build with these constraints regardless of eventual host:

- 12-factor config: all environment-specific values via `.env`, nothing hardcoded
- No reliance on features that only exist on specific hosts (e.g. don't assume a particular process manager)
- Queue worker and scheduler (`artisan schedule:run`) needs are documented explicitly so whatever host is chosen is checked against them before committing (this was the concern raised earlier against classic shared hosting — recheck it at decision time rather than assuming it's resolved)
- If Docker is used (§2), deployment becomes "run this container with this company's .env" on literally any host that supports Docker — maximizes future flexibility and makes it trivial to spin up the next company's instance later

---

## 9. Environments

- **Local dev:** docker-compose (or plain `artisan serve`) with one sample company DB + app instance, seeded demo data
- **Staging:** one persistent staging instance for QA before rolling an update out to real company instances
- **Production:** one instance per company, each deployed and updated independently (manual redeploy is fine at low company counts; revisit tooling per §2 if that stops scaling)

---

## 10. Testing

- Backend: Pest/PHPUnit — unit tests for service classes (especially tax/payroll calculation logic — these need to be correct, not just tested for coverage), feature tests for API endpoints
- Frontend: component tests (React Testing Library) for complex forms (BOM builder, payroll run screen); E2E (Playwright/Cypress) for the critical paths — order-to-cash, procure-to-pay, payroll run
- Test migrations run cleanly against a fresh database as part of CI — this doubles as the check that a brand-new company deployment will set up correctly

---

## Open Items to Confirm

- Component library choice (Ant Design vs. MUI vs. other) for React
- GSP provider selection for e-invoicing/e-way bill
- Docker vs. plain process deployment per company instance for Phase 0
- Redis availability assumption — confirm at hosting decision time
