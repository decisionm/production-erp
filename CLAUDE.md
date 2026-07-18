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
/backend/             Laravel project — the API, plus the built SPA at runtime
/frontend/            standalone React/TypeScript project (Vite) — not a Laravel resource, its own codebase
```

Two separate projects, deployed as one unit. `frontend/` has its own `package.json`, own `tsconfig.json`, own tooling — it is not nested inside `backend/resources/`. `npm run build` in `frontend/` writes straight into `backend/public/build/`; Laravel's catch-all web route (`backend/routes/web.php`) serves that build's `index.html` for every non-API path. One `git pull` + `composer install` + (`cd frontend && npm install && npm run build`) + point-domain-at-`backend/` is still the entire deployment — this split cost nothing operationally, it's purely an organizational/scalability improvement over nesting React inside Laravel's resource folder.

## Architecture decisions already made — don't relitigate these

1. **`frontend/` and `backend/` are separate projects, not React nested inside Laravel's `resources/`.** Rejected the Laravel-convention approach (React in `resources/js/` + Blade `@vite()` shell) deliberately: at ERP scale (8-10 large modules, meant to grow into a big codebase) a full-blown SPA nested inside a backend framework's resource folder under-signals that it's a first-class, independently reasoned-about codebase, and couples frontend tooling/history to the PHP project. The frontend still builds directly into `backend/public/build/` and Laravel serves it statically — same single-deployable-unit simplicity, cleaner separation. See `TECHNICAL-DOCS.md` §3 for the full rationale.
2. **Deployment model: one instance per company**, each with its own database, own URL, own `.env`. Not multi-tenant SaaS — there is no `tenant_id` anywhere and there should never be one added casually. See `TECHNICAL-DOCS.md` §2. Revisit only if manual per-company deployment actually becomes a bottleneck (rough threshold ~8-10 instances) — that's a deliberate future decision, not something to pre-build.
3. **The API is a real, versioned, reusable product surface** — `routes/api.php` under `/api/v1/*` — not a private implementation detail of the bundled SPA. It may be consumed by other apps (mobile, third-party integrations) later. This is why Inertia.js was deliberately rejected in favor of a genuine JSON API + separate SPA, and why auth supports both session (SPA, same-origin) and token (external clients) via Sanctum simultaneously. Never build a feature that only works for the bundled frontend — always go through `/api/v1`.
4. **Hosting (shared hosting vs VPS) is an explicitly deferred decision.** Don't assume Redis, persistent queue workers, or WebSockets are available — check `TECHNICAL-DOCS.md` §8 before relying on any of those.

## Stack

- **Backend (`backend/`):** Laravel (PHP 8.3+), MySQL, Sanctum (auth), spatie/laravel-permission (RBAC), spatie/laravel-activitylog (audit trail), Pint (style), PHPUnit (testing, via `php artisan test`)
- **Frontend (`frontend/`):** React + TypeScript (Vite), React Router, TanStack Query (server state), Zustand (client state), React Hook Form + Zod (forms/validation), Ant Design (component library), Axios

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

`frontend/src/features/auth/` and `frontend/src/features/dashboard/` are the reference implementations. Every module gets its own feature folder:

```
frontend/src/features/<module>/
  api.ts        functions calling the module's /api/v1 endpoints via the shared axios instance
  store.ts       zustand store, only if the module needs client-side state beyond server state
  types.ts       TypeScript types matching the module's API Resource shapes
  pages/         route-level components
```

- All API calls go through `frontend/src/lib/api.ts` (`api`, the shared axios instance) — never a new axios instance per feature.
- Server data fetching/caching goes through TanStack Query, not `useEffect` + manual state, except where `ProtectedRoute.tsx`'s bootstrap check already sets a precedent for a one-off fetch.
- New top-level routes are added in `frontend/src/app/App.tsx`.
- Path alias `@/` maps to `frontend/src/` (see `frontend/tsconfig.json` / `frontend/vite.config.ts`) — use it instead of relative `../../..` imports.

## Before committing

```bash
cd backend
./vendor/bin/pint          # PHP style — must be clean
php artisan test           # backend tests

cd ../frontend
npm run typecheck          # TypeScript type-check — must be clean
npm run build              # confirms the frontend actually builds into backend/public/build
```

## Local dev

Two terminals — `frontend/` and `backend/` are independent projects and run independent dev servers:

```bash
cd backend && composer run dev     # php artisan serve (localhost:8000)
cd frontend && npm run dev         # vite dev server (localhost:5173)
```

Visit the Vite dev server (`:5173`), not the Laravel one, during frontend development — `frontend/vite.config.ts` proxies `/api` and `/sanctum` requests through to Laravel so the browser sees everything as same-origin (no CORS, matches production behavior).

Two local-dev gotchas that will look like broken auth if you hit them:
- **Sanctum stateful domains** must include whatever port you're actually browsing from. `.env` already lists `127.0.0.1:8000` and `127.0.0.1:5173` — if you use a different port, add it to `SANCTUM_STATEFUL_DOMAINS` or login/me will silently 419/401.
- **Use `127.0.0.1`, not `localhost`, when testing with curl/tools outside the browser.** On machines where something else is also listening on the same port, `localhost` can resolve to `::1` (IPv6) and silently hit the wrong process instead of Laravel/Vite. Both dev servers are configured to bind IPv4 explicitly for this reason — keep addressing them the same way.
