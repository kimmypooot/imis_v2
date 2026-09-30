# Architecture

This document describes the technical foundation of the CSC RO VIII
Next-Generation IMIS and distinguishes what is **IMPLEMENTED** (Phase 0 —
foundation, Phase 1 — authentication) from what is **PLANNED** (future
phases). It is not a description of business features that do not exist yet.

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
  versioning; shares Laravel's default error bag plus a whitelisted
  `auth.user` (`id`, `name`, `email` only — never the password hash or
  remember token) so any page can read who is logged in without each
  controller repeating it.
- No Ziggy/route-helper package is installed; frontend links to backend
  routes use plain path strings (e.g. `/login`) since the route set is
  small and stable. Revisit if the route surface grows significantly.

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

## Authentication — IMPLEMENTED (Phase 1)

Session-based authentication using Laravel's native `Auth`/`Password`
facades and session guard. No third-party auth package (Breeze, Fortify,
Jetstream) was installed — the flows are small enough to hand-roll directly
against `App\Models\User`, which keeps the dependency count minimal and
avoids pulling in scaffolding (e.g. registration) that isn't wanted yet.

**Routes** (`routes/auth.php`, required from `routes/web.php`):

| Method | URI                          | Name                   | Middleware      |
|--------|------------------------------|-------------------------|------------------|
| GET    | `/login`                    | `login`                 | `guest`          |
| POST   | `/login`                    | —                        | `guest`          |
| POST   | `/logout`                   | `logout`                 | `auth`           |
| GET    | `/forgot-password`          | `password.request`       | `guest`          |
| POST   | `/forgot-password`          | `password.email`         | `guest`, `throttle:6,1` |
| GET    | `/reset-password/{token}`   | `password.reset`         | `guest`          |
| POST   | `/reset-password`           | `password.store`         | `guest`          |
| GET    | `/verify-email`             | `verification.notice`    | `auth`           |
| POST   | `/email/verification-notification` | `verification.send` | `auth`, `throttle:6,1` |
| GET    | `/verify-email/{id}/{hash}` | `verification.verify`    | `auth`, `signed`, `throttle:6,1` |
| GET    | `/dashboard`                 | `dashboard`               | `auth`           |

`/dashboard` is a deliberately minimal placeholder proving the middleware
boundary works — it is **not** the future portal/admin dashboard.

**Login** (`App\Http\Requests\Auth\LoginRequest`): looks up the user by
email, checks the password with `Hash::check()`, and only then checks
`status`. Wrong email/password and a correct-password-but-inactive account
produce distinct messages (`auth.failed` vs. an explicit "account is
inactive" message) per the product requirement, while password-reset
requests deliberately give one identical message regardless of whether the
account exists. Failed attempts (including inactive-account attempts) are
rate-limited via `RateLimiter` at 5/minute per email+IP; on success the
limiter is cleared and the session ID is regenerated
(`$request->session()->regenerate()`).

**Account status** (`App\Enums\UserStatus`: `Active` | `Inactive`, backed by
a `users.status` enum column, default `active`): enforced only inside
`LoginRequest::authenticate()` — there is no way to bypass it by hitting a
route directly, since no route other than login creates a session.

**Remember me**: uses Laravel's built-in `Auth::login($user, $remember)`
recaller-cookie mechanism — no custom persistent-login code.

**Password reset**: Laravel's `Password` broker (`password_reset_tokens`
table, 60-minute expiry, 60-second throttle already configured in
`config/auth.php`) — `PasswordResetLinkController` and
`NewPasswordController`. Route names (`password.reset`,
`verification.verify`) match what Laravel's built-in `ResetPassword` and
`VerifyEmail` notifications expect by default, so no notification
customization was needed.

**Email verification**: `User` implements `MustVerifyEmail`; standard
prompt/notification/verify controllers exist and are wired up, but
`/dashboard` does **not** require the `verified` middleware yet — it's
implemented and ready, just not enforced, so it can be turned on later
(add `verified` to the `/dashboard` route middleware) without further
plumbing. `MAIL_MAILER=log` locally, so verification/reset emails land in
`storage/logs/laravel.log` rather than requiring real SMTP.

**No self-registration**: there is intentionally no public "sign up" route
in Phase 1. How accounts get created (admin-provisioned, RSU-provisioned,
etc.) is a Phase 2+ decision tied to authorization/user management.

**Password policy**: `AppServiceProvider::boot()` sets
`Password::defaults()` to minimum 8 characters with letters and numbers,
plus HaveIBeenPwned's `uncompromised()` check in production only (skipped
locally to avoid a network call on every test run).

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
Authentication (IMPLEMENTED — Phase 1)
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
