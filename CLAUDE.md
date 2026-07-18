# CLAUDE.md — Manufacturing ERP

Instructions for Claude Code (and any future contributor) working in this repo. Read this before touching `backend/`.

## What this project is

A Manufacturing ERP for the Indian market (GST/TDS/PF/ESI compliance, Tally integration) covering Inventory, Production, Procurement, Sales/CRM, Finance, HRMS, Payroll, Quality, and Maintenance. Full context lives in three planning docs at repo root — read them before making architecture-level decisions, don't re-derive from scratch:

- `ERP-FEATURES.md` — full feature brainstorm across all modules (a checklist, not a spec — confirm real scope before building any given item)
- `DEVELOPMENT-PLAN.md` — phased build order (Phase 0 foundations → Phase 1 core transactional spine → Phase 2 India compliance → Phase 3 HRMS/Payroll → Phase 4 advanced manufacturing → Phase 5 multi-company growth, triggered only if needed)
- `TECHNICAL-DOCS.md` — architecture rationale (deployment model, stack choices, India compliance/Tally integration notes)

## Repo layout

```
/                     planning docs (this file, ERP-FEATURES.md, DEVELOPMENT-PLAN.md, TECHNICAL-DOCS.md)
/backend/             the entire application — one Laravel project, single deployable unit
```

There is no separate frontend project. `backend/` is it.

## Architecture decisions already made — don't relitigate these

1. **One Laravel project, not split frontend/backend repos.** React lives inside `backend/resources/js/`. Laravel's Vite integration builds it; one Blade view (`resources/views/app.blade.php`) serves the SPA shell via a catch-all route. This is what makes the app deployable to shared hosting: `composer install && npm run build`, upload/point-domain-at `backend/`, done.
2. **Deployment model: one instance per company**, each with its own database, own URL, own `.env`. Not multi-tenant SaaS — there is no `tenant_id` anywhere and there should never be one added casually. See `TECHNICAL-DOCS.md` §2. Revisit only if manual per-company deployment actually becomes a bottleneck (rough threshold ~8-10 instances) — that's a deliberate future decision, not something to pre-build.
3. **The API is a real, versioned, reusable product surface** — `routes/api.php` under `/api/v1/*` — not a private implementation detail of the bundled SPA. It may be consumed by other apps (mobile, third-party integrations) later. This is why Inertia.js was deliberately rejected in favor of a genuine JSON API + separate SPA, and why auth supports both session (SPA, same-origin) and token (external clients) via Sanctum simultaneously. Never build a feature that only works for the bundled frontend — always go through `/api/v1`.
4. **Hosting (shared hosting vs VPS) is an explicitly deferred decision.** Don't assume Redis, persistent queue workers, or WebSockets are available — check `TECHNICAL-DOCS.md` §8 before relying on any of those.

## Stack

- **Backend:** Laravel (PHP 8.3+), MySQL, Sanctum (auth), spatie/laravel-permission (RBAC), spatie/laravel-activitylog (audit trail), Pint (style), PHPUnit (testing, via `php artisan test`)
- **Frontend:** React + TypeScript inside `resources/js/`, React Router, TanStack Query (server state), Zustand (client state), React Hook Form + Zod (forms/validation), Ant Design (component library), Axios

## The module pattern — every ERP module follows this, no exceptions

`app/Modules/Core/` is the reference implementation. Every future module (`Inventory`, `Production`, `Sales`, `Finance`, `HRMS`, `Payroll`, `Quality`, `Maintenance`, `Compliance`, `TallySync`, ...) copies this exact shape:

```
app/Modules/<ModuleName>/
  Http/
    Controllers/   thin — inject a Service, call one method, return a Resource. No business logic here.
    Requests/      FormRequest per write action (validation lives here, not in the controller)
    Resources/     JsonResource per entity shape returned to clients
  Models/          Eloquent models scoped to this module
  Services/        business logic lives here — the only layer that talks to models directly
```

Reference files to copy the pattern from:
- `backend/app/Modules/Core/Http/Controllers/UserController.php` — thin controller
- `backend/app/Modules/Core/Services/UserService.php` — service class doing the actual work
- `backend/app/Modules/Core/Http/Requests/StoreUserRequest.php` — FormRequest validation
- `backend/app/Modules/Core/Http/Resources/UserResource.php` — response shaping
- `backend/app/Modules/Core/Http/Controllers/AuthController.php` — session-based auth against Sanctum

Rules:
- Controllers never contain validation logic or direct Eloquent queries — that's what Requests and Services are for.
- Cross-module reads/writes go through the other module's Service class, never directly through its Eloquent models. Keeps modules loosely coupled so `ERP-FEATURES.md` items can be built independently.
- New API routes are added to `routes/api.php` under `/v1`, grouped by module, guarded by `auth:sanctum` unless deliberately public (like `/auth/login`).
- Money and stock-quantity columns: always `decimal`, never `float`.
- Anything a user can "delete" that has transactional history (items, customers, vendors) uses soft deletes, not hard deletes.

## The frontend pattern

`resources/js/features/auth/` and `resources/js/features/dashboard/` are the reference implementations. Every module gets its own feature folder:

```
resources/js/features/<module>/
  api.ts        functions calling the module's /api/v1 endpoints via the shared axios instance
  store.ts       zustand store, only if the module needs client-side state beyond server state
  types.ts       TypeScript types matching the module's API Resource shapes
  pages/         route-level components
```

- All API calls go through `resources/js/lib/api.ts` (`api`, the shared axios instance) — never a new axios instance per feature.
- Server data fetching/caching goes through TanStack Query, not `useEffect` + manual state, except where `ProtectedRoute.tsx`'s bootstrap check already sets a precedent for a one-off fetch.
- New top-level routes are added in `resources/js/app/App.tsx`.
- Path alias `@/` maps to `resources/js/` (see `tsconfig.json` / `vite.config.js`) — use it instead of relative `../../..` imports.

## Before committing

```bash
cd backend
./vendor/bin/pint          # PHP style — must be clean
npx tsc --noEmit           # TypeScript type-check — must be clean
php artisan test           # backend tests
npm run build              # confirms the frontend actually builds
```

## Local dev

```bash
cd backend
composer run dev   # runs php artisan serve + queue listener + logs + vite dev server together
```

Default Sanctum stateful domains assume port 8000 (`127.0.0.1:8000`, `localhost`) — if you run the server on a different port locally, add it to `SANCTUM_STATEFUL_DOMAINS` in `.env` or SPA auth (login/me) will silently 419/401.
