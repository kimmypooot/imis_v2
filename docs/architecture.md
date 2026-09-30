# Architecture

This document describes the technical foundation of the CSC RO VIII
Next-Generation IMIS and distinguishes what is **IMPLEMENTED** (Phase 0)
from what is **PLANNED** (future phases). It is not a description of
business features that do not exist yet.

## Overall Architecture

The application is a server-driven SPA built with Laravel and Inertia.js:
Laravel owns routing, controllers, data, and authorization; Vue 3 owns the
rendering of pages; Inertia is the glue that lets a Laravel controller
return a Vue component with props, with no separate REST/JSON API layer or
client-side router to maintain.

```
Browser  <-->  Vite-built Vue 3 SPA shell  <-->  Inertia  <-->  Laravel (routes, controllers, Eloquent)  <-->  MySQL
```

### Laravel — IMPLEMENTED
- Application framework: routing, service container, Eloquent ORM,
  migrations, configuration, validation.
- `bootstrap/app.php` registers `HandleInertiaRequests` on the `web`
  middleware group.
- Health check available at `/up` (Laravel's built-in health route).

### Vue — IMPLEMENTED
- Vue 3 with the Composition API (`<script setup>`), JavaScript only.
- Page components live in `resources/js/Pages/`; each is rendered by an
  Inertia response from a controller/route.
- Layouts (`resources/js/Layouts/`) wrap pages via `defineOptions({ layout })`.
- Shared components live in `resources/js/Components/` (e.g. `Button.vue`),
  established as a pattern rather than a full library — more are added as
  features need them.

### Inertia — IMPLEMENTED
- `inertiajs/inertia-laravel` (server) + `@inertiajs/vue3` (client).
- Root Blade template: `resources/views/app.blade.php`.
- `HandleInertiaRequests` middleware controls shared props and asset
  versioning; currently shares only Laravel's default error bag.

### Tailwind CSS — IMPLEMENTED
- Tailwind v4, configured CSS-first in `resources/css/app.css`.
- A single `@theme` block defines CSC brand colors (`--color-csc-blue`,
  `--color-csc-red`) and semantic tokens (`--color-primary`,
  `--color-surface`, `--color-border`, `--color-focus-ring`, etc.) so the
  design system can be changed from one place instead of per-component.
- Typeface: Poppins, self-hosted at build time via
  `laravel-vite-plugin/fonts` (no external font request at runtime).

### MySQL — IMPLEMENTED
- Default connection: MySQL/MariaDB, utf8mb4, InnoDB, strict mode
  (Laravel's default `mysql` connection config, unchanged).
- Only framework-required tables exist: `users`, `cache`, `jobs`, and their
  supporting tables, created via migrations.

### Vite — IMPLEMENTED
- Single entry pair: `resources/css/app.css` and `resources/js/app.js`.
- `@vitejs/plugin-vue` compiles Vue SFCs; `@tailwindcss/vite` compiles
  Tailwind; `laravel-vite-plugin` wires asset paths into Blade and
  self-hosts fonts.

## Backend / Frontend Boundary — IMPLEMENTED

- Controllers return `Inertia::render('PageName', [...])`; they never
  return raw JSON for page navigation. A future JSON API (if needed for
  external integrations) would be a distinct, explicitly versioned layer.
- Validation, authorization, and business logic belong in the backend
  (`app/Http/Requests`, policies, and services as they're introduced) —
  Vue components render data and dispatch Inertia requests, nothing more.
- No server secrets are passed to the frontend; only explicit, whitelisted
  props defined per page.

## Future Modular Architecture — PLANNED

Modules are intended to be introduced in this order, each depending on the
ones before it:

```
Authentication
    -> Authorization (roles/permissions)
    -> Portal (public-facing shell)
    -> Regional Support Units
    -> Services
    -> Systems / Applications directory
    -> Committees
    -> Announcements
    -> Administration (Super Admin dashboard)
    -> Audit (activity logs)
    -> Future integrations
```

None of these exist yet. Phase 0 only ensures the project structure
(`app/Actions`, `app/Policies`, `app/Services`, `resources/js/Composables`,
etc.) can accommodate them without restructuring — those directories are
created when the first module that needs them is built, not preemptively.

### Future Authentication Architecture — PLANNED
Laravel's session-based auth (or Sanctum, if API/mobile access is needed)
guarding Inertia routes; login/registration pages under `AuthLayout`.

### Future Authorization Architecture — PLANNED
Role/permission model via Laravel policies and gates, enforced both in
controllers (source of truth) and reflected in the UI (hiding actions the
user cannot perform).

### Future Portal Architecture — PLANNED
Public pages (Services, Systems/Applications directory, Committees,
Announcements) under `PortalLayout`, backed by their own Eloquent models
and admin-managed content.

### Future Application/Integration Architecture — PLANNED
Any external system integrations (e.g. other CSC systems) are planned as
explicit, versioned service classes under `app/Services`, never embedded
directly in controllers.

## Error Handling Foundation — IMPLEMENTED / PLANNED

- IMPLEMENTED: Laravel's exception handler in `bootstrap/app.php` already
  renders JSON for API/XHR requests (`shouldRenderJsonWhen`), which Inertia
  relies on for validation error handling.
- PLANNED: dedicated Inertia error pages for 401/403/404/419/422/429/500,
  added when the routes that can trigger them exist.
