# Architecture

This document describes the technical foundation of the CSC RO VIII
Next-Generation IMIS and distinguishes what is **IMPLEMENTED** (Phase 0 —
foundation, Phase 1 — authentication, Phase 2 — authorization, Phase 3 —
UI/UX design system & application shell, Phase 4 — IMIS portal foundation &
dashboard, Phase 5 — organizational structure & Regional Support Units,
Phase 6 — services directory, through Phase 18 — dashboard systems directory; see
each section below) from what is **PLANNED** (future phases).
It is not a description of business features that do not exist yet.

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
- Shared components live in `resources/js/Components/`, organized by kind
  (see the Phase 3 section below) — a real component library now, not just
  a pattern.

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

## Authorization — IMPLEMENTED (Phase 2)

Role/permission authorization built on Laravel's native Gate and Policy
system — no third-party package (Spatie, etc.) was installed. It sits on
top of Phase 1 authentication without changing it:

```
User -> Roles -> Permissions -> Gate/Policy checks -> Controller/Action -> Resource
```

**Data model** (`database/migrations/2026_09_30_09000{0..3}_*`):

| Table             | Purpose                                   | Key constraints |
|--------------------|--------------------------------------------|------------------|
| `roles`            | `name` (unique), `display_name`, `description`, `status` (`active`/`inactive`) | — |
| `permissions`      | `name` (unique, `resource.action`), `display_name`, `description` | — |
| `role_user`        | user ↔ role pivot                          | unique (`user_id`, `role_id`), cascade delete |
| `permission_role`  | role ↔ permission pivot                    | unique (`role_id`, `permission_id`), cascade delete |

A user may hold multiple roles; a role may hold multiple permissions. The
unique composite constraints make duplicate assignments a database-level
impossibility, not just an application-level check.

**Models**: `App\Models\Role` and `App\Models\Permission` (both
`#[Fillable]`-scoped like `User`), with `Role::users()`/`Role::permissions()`
and `Permission::roles()` `BelongsToMany` relationships.

**`App\Models\User`** gained:
- `roles(): BelongsToMany` and `permissions(): Collection` (computed by
  eager-loading `roles.permissions` and flattening — there is no native
  Eloquent relation for "many-to-many through many-to-many", so this is a
  method, not a formal relation).
- `hasRole()`, `hasAnyRole()`, `hasAllRoles()`, `hasPermission()`,
  `hasAnyPermission()`, `hasAllPermissions()` — all resolve from an
  already-loaded `roles`/`roles.permissions` relation on the model
  instance (`loadMissing`), so repeated checks on the same request's
  `$request->user()` do not re-query.

**Stable identifiers** (`App\Enums\RoleName`, `App\Enums\PermissionName`):
backed enums so role/permission machine names are never hardcoded as bare
strings in more than one place. `RoleName::SuperAdmin` (`super-admin`) is
the only role the application logic itself needs to recognize by name —
every other role is just a database row future modules create through the
roles UI.

**Super Administrator**: identified purely by holding the `super-admin`
role — never by email or user ID. `AppServiceProvider::configureAuthorization()`
registers a single `Gate::before` callback:

```php
Gate::before(fn (User $user) => $user->hasRole(RoleName::SuperAdmin) ? true : null);
```

returning `null` (not `false`) for non-super-admins so every other ability
check falls through to its normal policy/gate resolution instead of being
force-denied.

**Gate integration**: every permission name is also registered as its own
Gate ability (`Gate::define($name, fn (User $user) => $user->hasPermission($name))`),
so `$user->can('users.view')` works directly without a bespoke
authorization API. The permission name list is cached
(`Cache::rememberForever`, key `AppServiceProvider::PERMISSION_CACHE_KEY`)
so this doesn't re-query on every request; `App\Support\PermissionCache::flush()`
invalidates it and is called wherever permission rows change (currently
only `RolePermissionSeeder`, since this phase has no permission-creation
UI — the next module to add one must call it too).

**Policies** (`app/Policies`, auto-discovered by Laravel's
model-to-policy naming convention — no explicit registration needed):
`RolePolicy` (`viewAny`/`view`/`create`/`update`/`delete`/`managePermissions`),
`PermissionPolicy` (`viewAny`/`view`), `UserPolicy` (`view`/`assignRoles`).
Each ability defers to `$user->hasPermission('resource.action')` — the
Super Administrator bypass is handled once, centrally, by `Gate::before`,
so no policy method re-checks for it.

**Middleware** (`app/Http/Middleware/EnsureUserHasRole`,
`EnsureUserHasPermission`; aliased in `bootstrap/app.php` as `role:` and
`permission:`): route-boundary guards only (`->middleware('permission:roles.view')`).
Every route they protect is *also* independently authorized in its
controller via `$this->authorize()` — middleware alone is never treated as
sufficient, since a route can be reordered or reused.

**Mass assignment / privilege escalation**: `StoreRoleRequest`,
`UpdateRoleRequest`, and `AssignPermissionsRequest` validate role/permission
fields explicitly (no raw `$request->all()` ever reaches a model). Most
importantly, `App\Http\Requests\User\AssignRolesRequest` runs a
`Validator::after()` check that rejects any attempt to assign the
`super-admin` role unless the actor already holds it — independent of the
frontend, so it cannot be bypassed by hand-crafting the PUT request. Roles
and permissions themselves declare only their intended columns as
`#[Fillable]`, so an unexpected key like `id` in a request payload is
silently dropped by Eloquent rather than mass-assigned.

**Routes** (`routes/admin.php`, required from `routes/web.php`, all under
`auth` + the relevant `permission:` middleware):

| Method | URI                        | Name                     | Permission required |
|--------|-----------------------------|---------------------------|----------------------|
| GET    | `/authorization-demo`       | `authorization.demo`      | any authenticated user |
| GET    | `/roles`                    | `roles.index`              | `roles.view` |
| GET    | `/roles/create`             | `roles.create`             | `roles.create` |
| POST   | `/roles`                    | `roles.store`              | `roles.create` |
| GET    | `/roles/{role}/edit`        | `roles.edit`                | `roles.update` |
| PUT    | `/roles/{role}`             | `roles.update`              | `roles.update` |
| PUT    | `/roles/{role}/permissions` | `roles.assign-permissions`  | `permissions.manage` |
| GET    | `/permissions`              | `permissions.index`        | `permissions.view` |
| GET    | `/users/{user}`             | `users.show`                | `users.view` |
| PUT    | `/users/{user}/roles`       | `users.assign-roles`        | `users.update` |

At the time this phase shipped, these screens were deliberately the
minimum needed to prove the architecture, not a full user-administration
module. Phase 9 ("Users & Roles Management") later built the full CRUD
module on top of this exact foundation — see that section for the current
route table and the privilege-escalation guards it added.

**403 handling**: `bootstrap/app.php` renders `Errors/403.vue` (via
`Inertia::render(...)->toResponse($request)->setStatusCode(403)`) for any
`HttpExceptionInterface` with a 403 status — covering both
`$this->authorize()` failures (Laravel converts `AuthorizationException`
to `AccessDeniedHttpException` before custom renderers run) and
`abort(403)` from the role/permission middleware. Unauthenticated requests
are unaffected — they still redirect to `/login` via the existing `auth`
middleware/guest guard, exactly as in Phase 1.

**Frontend authorization support**: `HandleInertiaRequests::share()` adds
`auth.roles` (array of role names) and `auth.permissions` (array of
permission names) alongside the existing `auth.user`. The
`useAuthorization()` composable (`resources/js/Composables/useAuthorization.js`)
reads those props and exposes `can()`, `canAny()`, `canAll()`, `hasRole()`,
`hasAnyRole()`, `hasAllRoles()` for conditionally rendering UI (e.g. hiding
a nav link). This is explicitly **not** a security boundary — every route
it could be used to hide is independently authorized server-side, so
manually visiting a hidden URL without permission still returns 403.

**Demonstration page**: `/authorization-demo`
(`AuthorizationDemoController` → `resources/js/Pages/Authorization/Demo.vue`)
shows the authenticated user's roles, permissions, and the result of
example server-computed `hasRole()`/`can()` checks. It is not the future
admin dashboard.

## UI/UX Design System & Application Shell — IMPLEMENTED (Phase 3)

Pure frontend/UI infrastructure — no new business logic, tables, routes with
real data, or authorization rules. It establishes the reusable visual
system and authenticated application shell every future module builds on,
and normalizes the ad hoc markup Phases 1–2 wrote before this system
existed.

### Design tokens (`resources/css/app.css`, `@theme`)
Everything is a CSS custom property under one `@theme` block — components
reference the semantic names (`bg-primary`, `text-danger`, …), never a hex
value directly:

- **Brand**: `--color-csc-blue` (`#2A338F`), `--color-csc-red` (`#EC1C2D`),
  plus a hover/dark shade of each.
- **Semantic surfaces/text**: `--color-primary`, `--color-accent`,
  `--color-surface`, `--color-surface-muted`, `--color-border`,
  `--color-text`, `--color-text-muted`, `--color-focus-ring` (from Phase 0).
- **Semantic feedback** (new in Phase 3): `--color-success`,
  `--color-warning`, `--color-danger` (aliases `--color-csc-red`),
  `--color-info` (aliases `--color-csc-blue`) — each with a paired `-bg` and
  `-border` tint for badges/alerts.
- **Radius scale**: `--radius-sm/md/lg`, so `rounded-*` utilities stay
  consistent app-wide.
- **Typography**: Poppins (unchanged from Phase 0, self-hosted at build
  time), Tailwind's default type scale — no custom scale was needed, since
  the app's hierarchy (page title → section heading → body → helper text)
  maps cleanly onto `text-xl/lg/sm/xs` + `font-semibold/medium`. The
  Design System demo page (`/design-system`) shows the exact class for
  each level.

No gradients, glassmorphism, neon, or heavy shadows/animation were
introduced — flat surfaces, a 1px border, and `shadow-xs`/`shadow-sm` only,
consistent with a government-office visual identity.

### Component organization (`resources/js/Components/`)
```
Components/
    UI/           Button, Card, Badge, EmptyState, Spinner, Icon, Table,
                  PageHeader, ErrorPage
    Forms/        TextInput, PasswordInput, Checkbox, Radio, Select,
                  Textarea, FileInput, InputLabel, HelperText, InputError,
                  FormGroup
    Navigation/    Breadcrumbs, SidebarNav, UserMenu
    Feedback/      Alert, Modal, ConfirmDialog
    AuthBrandHeader.vue   (Auth-specific, stays at the root)
```
Every component is presentation/behavior only — `Button.vue` has no idea
what a "role" or "user" is; page-specific logic (what a button *does*)
lives in the page that uses it, never in the shared component.

Key components:
- **Button** (`UI/Button.vue`): `primary`/`secondary`/`danger`/`ghost`/`link`
  variants, `loading` (shows a spinner, sets `aria-busy`, disables the
  button so a slow request can't be double-submitted), `disabled`,
  `icon-only` (pair with an `aria-label`).
- **Table** (`UI/Table.vue`): a styled `<table>` wrapper, not a data-grid —
  consumers still write plain `<thead>`/`<tbody>` markup into the default
  slot. Takes `loading` (spinner) and `empty` (renders the `#empty` slot,
  normally an `EmptyState`) so every list page handles those two states the
  same way.
- **Modal** (`Feedback/Modal.vue`): `role="dialog"` `aria-modal="true"`,
  Escape to close, backdrop click to close, focus moves into the dialog on
  open and back to the triggering element on close, background scroll
  locked while open. `ConfirmDialog` wraps it for destructive actions
  (delete/disable/revoke) — this is the app's replacement for the
  browser's native `confirm()`, and its `processing` prop disables both
  buttons during the request to prevent a duplicate submit.
- **Alert** (`Feedback/Alert.vue`): `success`/`info`/`warning`/`danger`
  tones, optional `dismissible`. Used for status/flash messages across the
  Auth pages (Phase 1) instead of each page hand-rolling a `role="status"`
  div.
- **Badge** (`UI/Badge.vue`): status indicators (Active/Inactive/Pending/…)
  — always paired with the text label, never color alone.
- **Icon** (`UI/Icon.vue`): the single icon approach — a small set of
  inline Heroicons-outline SVG paths, not an npm dependency (kept the
  dependency count at zero for this). Decorative icons are `aria-hidden`;
  an icon used as the only content of a control (e.g. an icon-only button)
  needs an `aria-label` on that control.

### Layouts & application shell (`resources/js/Layouts/`)
- **`PublicLayout.vue`**: unauthenticated pages (currently just `Home`).
  Simple header/content/footer, no sidebar.
- **`AuthLayout.vue`**: login, forgot/reset password, verify email. It is
  the legacy IMIS split screen: on desktop, a CSC-navy brand panel over
  the regional office facade with the CSC, Bagong Pilipinas and Lingkod
  Bayani logos; the form card with the IMIS wordmark on a light panel.
  On mobile the logos move into the card. Brand images live in
  `public/images/brand/` and were copied from the legacy site.
- **`AppShell.vue`**: the reusable authenticated shell, following the
  legacy layout. A fixed top bar holds the hamburger, the CSC logo and
  "CSC RO VIII", search, notifications and `UserMenu`. **There is no
  sidebar until the hamburger is clicked.** At ≥ 1024px the sidebar docks
  and pushes the content, and the choice is remembered per browser
  (`localStorage` key `imis.sidebar-open`, wrapped in try/catch, default
  closed). Below that it overlays the page as a modal drawer, with a
  backdrop, Escape to close, focus moved in and back out, and it closes
  on navigation. When closed it is `inert`, so hidden links are never
  tabbable. Takes a `navItems` prop; it knows no business module.
- **`PortalLayout.vue`** / **`AdminLayout.vue`**: both are now thin
  wrappers around `AppShell` that just supply their own nav config
  (`Utils/navigation.js` → `portalNavItems` / `adminNavItems`). `Dashboard`
  (Phase 1) uses `PortalLayout`; `/roles`, `/permissions`,
  `/authorization-demo`, `/design-system` (Phase 2 + this phase) use
  `AdminLayout` — both are live demonstrations of the shell, not separate
  one-off page layouts.

### Authorization-aware navigation
`Utils/navigation.js` exports plain arrays: `{ label, href, icon,
permission? }`. `SidebarNav.vue` filters out any item whose `permission`
the current user lacks, using the Phase 2 `useAuthorization()` composable
(`auth.permissions` from Inertia shared props). Adding a nav entry for a
future module means adding one object to that array — no changes to
`AppShell`, `SidebarNav`, or any layout. As with every other frontend
permission check, **this only hides a link** — the route behind it is
still independently authorized server-side by Phase 2's
policies/middleware regardless of what the sidebar shows.

### Page header & breadcrumbs
`PageHeader.vue` (`title`, `description`, `breadcrumbs`, `#actions` slot,
default slot for extra context) renders a `Breadcrumbs.vue` when
`breadcrumbs` is given. `Breadcrumbs` takes `[{ label, href? }]`; the last
item renders as plain text with `aria-current="page"` instead of a link.

### Error pages (`resources/js/Pages/Errors/`)
`bootstrap/app.php` renders an Inertia page for every HTTP error status
instead of Laravel's default error view, for non-JSON requests:
- **403** → `Errors/403.vue` (already existed from Phase 2, now built on
  the shared `ErrorPage.vue` presentational component).
- **404, 419, 429, 500, 503** → `Errors/Show.vue` (new), which picks its
  title/message from a small `status -> {title, message}` map.
- **500 locally** (`config('app.debug') === true`) is left alone so
  Laravel's own debug page (stack trace) still renders in development.
- None of these pages ever receive a stack trace, SQL, file path, or env
  variable from the backend — only a status code and a fixed, plain-English
  message.

### Accessibility
- Every interactive control uses the shared `focus-visible:ring-2
  focus-visible:ring-focus-ring` treatment — no outline was removed without
  this replacement.
- Status is never color-only: badges/alerts always carry a text label.
- `Modal`/`ConfirmDialog` and the mobile nav drawer both use
  `aria-modal`/`role="dialog"`, trap the visible focus/scroll while open,
  and restore focus to the triggering control on close.
- Form errors use the existing `InputError` (`role="alert"`) pattern,
  consistently invoked from every form control via `FormGroup` or inline.

### Responsive approach
Built mobile-first with Tailwind's default breakpoints (`sm`/`lg`): the
sidebar is a mobile drawer below `lg` and a persistent column at `lg`+; the
header/page-header/table wrapper all use `overflow-x-auto`/flex-wrap
instead of fixed widths, so nothing introduces horizontal page scroll at
small viewport widths.

### How a future module uses this system
- Reuse `PortalLayout`/`AdminLayout` (or `AppShell` directly with a new
  `navItems` array) — never write a new top-level shell.
- Build forms from `Components/Forms/*` + `FormGroup`; build lists from
  `Components/UI/Table` + `EmptyState`; use `PageHeader` for every page's
  title/breadcrumbs.
- Add navigation entries to `Utils/navigation.js` (with a `permission` key
  if the route is gated) rather than editing `AppShell`/`SidebarNav`.
- Add new permission strings the way Phase 2 documents (see the
  Authorization section above) and reference them from a nav item's
  `permission` field and the page's own server-side authorization — the
  nav filtering and the route protection are two separate checks that must
  both be updated, since the former is UX only.

## IMIS Portal Foundation & Dashboard — IMPLEMENTED (Phase 4)

The authenticated home of the application — the "central platform" a CSC
RO VIII employee lands on to discover applications, services, and
organizational units. No business logic for any actual ITG system (OTRS,
PROCTAD, etc.) lives here; the portal only holds pointers to them.

### Portal entry point
`GET /dashboard` (route name `dashboard`, unchanged from Phase 1 — the
existing single authenticated entry point was extended rather than
duplicated) is now `PortalController@index` instead of a closure
rendering a placeholder. `GET /` branches: an authenticated user is
redirected to `/dashboard`; a guest still sees the public `Home` page.
There is exactly one authenticated home route.

### Data model
Three new tables, all owned by `App\Models\{Application,Service,
OrganizationalUnit}`:

| Table                  | Purpose                                            |
|-------------------------|-----------------------------------------------------|
| `organizational_units`  | Regional Support Units, field offices, etc.         |
| `applications`          | Directory entries for (typically external) ITG systems |
| `services`               | Directory entries for CSC RO VIII services          |

`applications`/`services` both have `organizational_unit_id` (nullable,
`nullOnDelete`) and share a `status` (`active`/`inactive`) and
`visibility` (`public`/`authenticated`/`administrative`) column —
enforced by `App\Models\Concerns\HasPortalVisibility` (`scopeActive()`,
`scopeVisibleTo(?User $user)`, `isVisibleTo(?User $user)`), so every
portal controller queries through the same rule instead of reimplementing
"is this record visible" per listing. `applications` additionally has
`icon`, `url` (nullable — see below), `category`, `external` (boolean),
`featured` (boolean), and `sort_order`.

No real CSC RO VIII unit/service names were invented — none are
established anywhere in this project's documentation, so the seeded
records are explicitly demo-labeled placeholders (`RolePermissionSeeder`-
style `firstOrCreate`, deterministic). (`organizational_units` gained a
parent/child hierarchy in Phase 5 — see below.) The six seeded
applications (OTRS, ORS/
HRMPSB Connect, PROCTAD, CSE/COMEXAMS, Examinee Number Lookup, C-ERIS) are
the real, already-documented CSC RO VIII ITG systems named in this
project's own Phase 4 spec, but their `url` is left `null` — this project
has no authority to assign them a production address, so the portal
renders "not yet configured" instead of a fabricated link.

### External vs. internal handling
An application/service card always links to its own **internal** Inertia
detail page (`/applications/{id}`, `/services/{id}`) — never straight to
the external `url`. Only the detail page exposes the actual external
link, as a plain `<a target="_blank" rel="noopener noreferrer">` with an
`ExternalLinkIndicator`. There is no server-side redirect route
(`/go?url=...` or similar) — the link comes directly from the stored,
admin-configured `url` column, which is the deliberate way this avoids
ever becoming an open-redirect vector. No iframe embedding, no SSO, no
shared sessions — a future phase's explicit concern.

### Authorization integration
- `AppServiceProvider::ADMIN_ACCESS_PERMISSIONS` (`roles.view`,
  `permissions.view`, `users.view`, `organizational_units.view` as of
  Phase 5, `services.view` as of Phase 6) backs a new `access-admin` Gate
  ability — a user with *any* of those Phase 2 permissions counts as
  "administrative" for portal purposes (showing the Administration nav
  entry, reaching `/admin`, seeing `administrative`-visibility directory
  entries). `/admin` is protected by both `->middleware('can:access-admin')`
  and `$this->authorize('access-admin')` in `AdminController` — the
  middleware is a route-boundary guard, the controller check is the real
  enforcement, same defense-in-depth pattern as Phase 2.
- `SidebarNav`'s `permission` item field now accepts an array (any-of),
  used for the Administration nav entry — see `Utils/navigation.js`.
- `AdminController` is a stub: it only links to Roles/Permissions (via
  the same `useAuthorization().can()` check every other nav item uses).
  It manages nothing itself; future phases add Applications, Services,
  Organizational Units, Users, Announcements, and Audit as their own
  sections here without this controller changing.

### Search
`GET /search?q=...` (`PortalSearchController`) — plain `LIKE '%q%'`
queries against `name`/`description` on all three models, each scoped
through `visibleTo()`/`active()` exactly like every listing page, limited
to 10 results per category. No search engine dependency. The
`PortalSearch.vue` component (in the app header, desktop and mobile) is a
single text input that Inertia-navigates to `/search?q=...` on submit;
`SearchResults.vue` renders grouped results or an empty/no-results state.
*(Superseded by Phase 14 — see "Global Search & UX Refinement" below.
`SearchResults.vue` no longer exists.)*

### Inertia props
`App\Http\Resources\{ApplicationResource,ServiceResource,
OrganizationalUnitResource}` shape every prop — no raw Eloquent model
(with its `status`/`visibility`/foreign keys/timestamps) is ever passed to
a page. `JsonResource::withoutWrapping()` is set globally in
`AppServiceProvider::boot()` since these Resources shape Inertia props,
not a versioned JSON API — the default `{"data": [...]}` envelope would
only complicate every prop for no benefit.

### Portal components (`resources/js/Components/Portal/`)
`PortalSection` (labeled section + optional "View all" link),
`PortalCard` (the one card used for Applications/Services/Organizational
Units listings — always links to the entry's internal detail page),
`QuickAccess` (the featured-applications grid on the dashboard, falls
back to any visible application when nothing is featured, or an empty
state when the directory is empty), `PortalSearch`, `SearchResults`,
`ExternalLinkIndicator`.

### Dashboard content
`Portal/Dashboard.vue`: a `PageHeader` with a client-computed (browser
local time, not server time) time-of-day greeting, then `PortalSection`s
for Quick Access, Services, and Organizational Units — each backed by a
real, possibly-empty query result, never fabricated statistics, charts,
or KPIs. A notification bell icon exists in the header as an inert
placeholder entry point (Step 19) — no notification system exists yet.

*Updated to follow the legacy dashboard:* the `PageHeader` was replaced by
`WelcomeBanner` (greeting, system title, the welcome message from
Settings) and `StatsBar`. The stats bar shows Active Systems, Accessible
to You (open systems plus restricted ones the viewer holds a role in),
Under Maintenance, and a local clock. All three numbers are real counts
over what the viewer can see (`PortalController` → `stats`), never
placeholders.

## Organizational Structure & Regional Support Units — IMPLEMENTED (Phase 5)

Extends the `organizational_units` table and directory from Phase 4 with a
real hierarchy and administrative CRUD — no new table was created,
matching the phase's own instruction to extend rather than duplicate.

### Hierarchy
Adjacency list: `organizational_units.parent_id` (nullable, self-
referencing FK, `restrictOnDelete()` — a unit with children can't be hard-
deleted, consistent with this app never offering hard delete anyway).
`App\Models\OrganizationalUnit` gained:

- `parent(): BelongsTo` / `children(): HasMany` — the two real Eloquent
  relations the adjacency list needs.
- `ancestors(): Collection` / `descendants(): Collection` — computed by
  walking `parent`/`children` in PHP (nearest-first for ancestors, depth-
  first for descendants). Not nested-set — a plain adjacency list is
  sufficient at this scale, and nested sets would complicate every write
  for a hierarchy that's expected to stay shallow (RSU → field/satellite
  offices, not deep org charts).
- `wouldCreateCycleIfParentedTo(?OrganizationalUnit $candidate): bool` —
  true if `$candidate` is the unit itself (self-parent) or one of its own
  descendants (which would otherwise let an ancestor become its own
  descendant's child). `UpdateOrganizationalUnitRequest::withValidator()`
  calls this in a `Validator::after()` hook, independent of the frontend,
  so a hand-crafted PUT request can't bypass it. The admin Edit/Create
  forms additionally never *offer* an invalid parent — `parentOptions()`
  in the controller excludes the unit itself and its descendants from the
  `<select>` — but that's UX only; the request-level check is what's
  authoritative.
- `scopeRoots(Builder $query)` — `whereNull('parent_id')`, used by the
  portal directory (see below) and by `parentOptions()`.

### Unit types and other new fields
`App\Enums\UnitType` (`regional_support_unit`, `field_office`,
`satellite_office`, `other`) — backed enum cast on `unit_type` (the
Phase 4 column was named `type`; a Phase 5 migration renames it and adds
`code` (unique, required at the validation layer — nullable at the DB
layer so the Phase 4 migration didn't need a backfill), `slug` (unique,
auto-generated from `name` via `OrganizationalUnit::generateUniqueSlug()`
— called explicitly by the seeder/controller, **not** a `creating` model
event, since `DatabaseSeeder` uses `WithoutModelEvents` and would silently
skip an event-based hook), `parent_id`, `email`, `telephone`, `address`,
`website_url`. Frontend `<select>`s for `unit_type`/`status` render
options the backend passes as props (`UnitType::options()` /
`Status::options()`) — never a hardcoded list in a Vue file.

### Administrative CRUD
`App\Http\Controllers\Admin\OrganizationalUnitController` (namespaced
under `Admin\` specifically to avoid a class-name collision with the
portal-facing `App\Http\Controllers\OrganizationalUnitController`, the
same short name by design) — `index` (search by name/code, filter by
status/type), `create`/`store`, `edit`/`update`, and `updateStatus`
(activate/deactivate — deliberately a separate action/permission from
`update`, matching Role's `managePermissions` precedent). Routes live
under `/admin/organizational-units`, distinct from the portal's read-only
`/organizational-units` — Portal and Administration stay architecturally
separate, per the Phase 3 principle. No hard-delete route exists;
deactivation is the only way to retire a unit.

**Permissions** (`organizational_units.view/create/update/archive`) —
added to `App\Enums\PermissionName`; `RolePermissionSeeder` already
derives its seeded permission rows from that enum, so no seeder change
was needed for them to exist. `App\Policies\OrganizationalUnitPolicy`
(auto-discovered) maps each to `$user->hasPermission(...)`, same pattern
as `RolePolicy`.

### Portal directory changes
The public-facing `/organizational-units` index now lists **root units
only** (`->roots()`) — sub-units are discovered by drilling into a
parent's detail page, not by a flat list or an expandable tree. This was
the deliberate choice for staying "understandable on mobile" (Step 8)
without an org-chart library: drill-down navigation, not a big
client-rendered tree. `/organizational-units/{unit}` now also loads and
shows the unit's `parent` (breadcrumb) and active `children` (a "Sub-units"
grid), plus its contact fields (email/telephone/address/website) when any
are set.

### Tests
`tests/Unit/Models/OrganizationalUnitHierarchyTest.php` (relations,
ancestors/descendants, cycle detection, slug uniqueness) and
`tests/Feature/Admin/OrganizationalUnitsTest.php` (authorization per
action, validation incl. duplicate code / self-parent / circular
hierarchy / invalid URL, mass-assignment protection, activate/deactivate,
404 on an invalid unit) — plus new cases in the existing
`tests/Feature/Portal/OrganizationalUnitsTest.php` for the roots-only
index and parent/children on the detail page.

## Services Directory — IMPLEMENTED (Phase 6)

Extends the `services` table and directory from Phase 4 with the fields
needed to actually describe a service (purpose, requirements, procedure,
fees, processing time, contact info) and a lightweight category taxonomy —
no new business-transaction/workflow modeling, per the phase's own
explicit boundary. `Service` still never models the actual transaction
(no payment, no submission tracking, no client accounts) — only what the
service is and how to reach it, the same separation Application draws
against the ITG systems it points to.

### Data model
A Phase 6 migration extends the existing `services` table (not a new
table): renames `url`→`external_url`; adds `short_name`, `slug` (unique,
auto-generated the same way as `OrganizationalUnit`'s — see below),
`purpose`, `requirements`, `procedure`, `fees`, `processing_time`,
`contact_information`, `featured` (boolean), and `category_id` (nullable
FK to the new `service_categories` table, `nullOnDelete`). The old
free-text `category` string column is dropped, superseded by the
relationship. `status`/`visibility`/`sort_order`/`organizational_unit_id`
are unchanged from Phase 4 and still enforced by the shared
`HasPortalVisibility` trait.

`service_categories` (new table): `name`, `slug` (unique), `description`,
`status`, `sort_order` — deliberately minimal, per the phase's own "avoid
over-engineering taxonomy" instruction. No admin CRUD screens exist for
it (categories are seeded and picked from a dropdown when managing a
Service); a full category management UI is future work if the taxonomy
ever needs to grow past a handful of seeded rows.

### Shared slug generation
`OrganizationalUnit::generateUniqueSlug()` (Phase 5) was extracted into
`App\Models\Concerns\HasUniqueSlug`, now used by both `OrganizationalUnit`
and `Service` (and `ServiceCategory`). Still called explicitly by
controllers/seeders, never a `creating` model event — `DatabaseSeeder`'s
`WithoutModelEvents` would silently skip an event-based hook, as
discovered in Phase 5.

### Admin CRUD
`App\Http\Controllers\Admin\ServiceController` (namespaced under `Admin\`
for the same reason as `Admin\OrganizationalUnitController` — avoiding a
class-name collision with the portal-facing `ServiceController`) —
`index` (search by name/short name, filter by status/category/
organizational unit), `create`/`store`, `edit`/`update`, and
`updateStatus` (activate/deactivate, its own permission, same pattern as
Organizational Units). Routes live under `/admin/services`, distinct from
the portal's read-only `/services`. No hard-delete route; deactivation
only.

**Permissions** (`services.view/create/update/archive`) — added to
`PermissionName` (auto-seeded via the existing `RolePermissionSeeder`
derivation, no seeder change needed); `.view` added to
`AppServiceProvider::ADMIN_ACCESS_PERMISSIONS`.
`App\Policies\ServicePolicy` (auto-discovered) maps each to
`$user->hasPermission(...)`.

### Portal directory
`/services` now supports `?search=`, `?category_id=`, and
`?organizational_unit_id=` query params (plain `LIKE`/`where` filtering,
same as the admin listing), and orders featured services first. The
detail page (`/services/{id}`) renders a "Details" card with whichever of
purpose/requirements/procedure/fees/processing time/contact information
are actually set — nothing invented, nothing shown when blank — plus the
owning organizational unit and an external link (only when `external_url`
is configured; a `null` value renders "not yet configured," the same
convention Applications use).

### Security
- **External URL validation**: `StoreServiceRequest`/`UpdateServiceRequest`
  require the `url` rule *and* a `regex:/^https?:\/\//i` check, so a
  stored value can never resolve to a `javascript:`/`data:` URI when later
  rendered as a link — validated, not just trusted from the admin form.
- **No rich text/HTML**: every long-text field (description, purpose,
  requirements, procedure, contact information) is a plain `Textarea`,
  rendered through Vue's auto-escaping `{{ }}` interpolation — never
  `v-html`. This sidesteps the "sanitize HTML if supported" requirement
  entirely by never accepting HTML input in the first place, rather than
  adding a rich-text editor and a sanitization library for a directory
  entry that doesn't need one.
- Inactive/`administrative`-visibility services 404 on direct URL access
  (via `isVisibleTo()`), not a 403 — consistent with every other portal
  directory entry.

### Tests
`tests/Feature/Admin/ServicesTest.php` (authorization per action, search/
category/unit filtering, validation incl. required fields / invalid URL /
non-http URL scheme, slug uniqueness on duplicate names, mass-assignment
protection, activate/deactivate, 404 on an invalid service) and
`tests/Unit/Models/ServiceModelTest.php` (category/organizational-unit
relationships, slug uniqueness, visibility enforcement) — plus new cases
in the existing `tests/Feature/Portal/ServicesTest.php` for search,
category/unit filtering, and featured ordering.

## Systems / Applications Directory — IMPLEMENTED (Phase 7)

Adds full admin management (create/edit/activate-deactivate) on top of the
`Application` model, migration, and portal-facing directory/detail pages
already built in Phase 4 — no new table, no new model, no duplicated
foundation. This phase is explicitly a **catalog and launcher**, never a
container for the business logic of the systems it lists: `Application`
holds a name, description, and URL, nothing else. IMIS launches these
systems (opens their `url` in a new tab); it does not implement SSO,
proxy their traffic, or connect to their databases. Systems like OTRS,
ORS/HRMPSB Connect, PROCTAD, CSE/COMEXAMS, the Examinee Number Lookup, and
C-ERIS remain independent applications outside this codebase.

### Data model
Unchanged from Phase 4 — no migration was needed. The existing
`applications` table/`Application` model already covered every field this
phase's admin screens need: `name`, `short_name`, `description`, `icon`,
`url`, `category` (plain string — no taxonomy table was introduced for
Applications, unlike `ServiceCategory` in Phase 6; the field is small
enough that a dropdown taxonomy would be premature), `external`,
`featured`, `status`, `visibility`, `sort_order`, and
`organizational_unit_id`.

### Admin CRUD
`App\Http\Controllers\Admin\ApplicationController` (namespaced under
`Admin\` for the same reason as `Admin\ServiceController` — avoiding a
class-name collision with the portal-facing `ApplicationController`) —
`index` (search by name/short name, filter by status/organizational
unit), `create`/`store`, `edit`/`update`, and `updateStatus`
(activate/deactivate, its own permission, same pattern as Organizational
Units and Services). Routes live under `/admin/applications`, distinct
from the portal's read-only `/applications`. No hard-delete route;
deactivation only.

**Permissions** (`applications.view/create/update/archive`) — added to
`PermissionName` (auto-seeded via the existing `RolePermissionSeeder`
derivation, no seeder change needed); `.view` added to
`AppServiceProvider::ADMIN_ACCESS_PERMISSIONS` and to the `Administration`
nav entry's permission list in `resources/js/Utils/navigation.js`.
`App\Policies\ApplicationPolicy` (auto-discovered) maps each to
`$user->hasPermission(...)`, identical in shape to `ServicePolicy`.

### Launch behavior
The admin "Launch URL" field is the same `url` column the Phase 4 portal
detail page already renders as a plain `<a target="_blank"
rel="noopener noreferrer">` — opening it is the entire "launch" behavior.
No redirect route, no proxy, no server-side fetch of the target system.

### Security
- **URL validation**: `StoreApplicationRequest`/`UpdateApplicationRequest`
  require the `url` rule *and* a `regex:/^https?:\/\//i` check, the same
  pattern `Service`'s `external_url` uses, so a stored value can never
  resolve to a `javascript:`/`data:` URI when rendered as a launch link.
- Inactive/`administrative`-visibility applications 404 on direct URL
  access (via `isVisibleTo()`), not a 403 — consistent with every other
  portal directory entry.
- No private infrastructure details (internal hostnames, credentials, API
  keys) are modeled on `Application` or exposed through either the admin
  or portal resource — the table only ever holds what's needed to list
  and link to a system's public entry point.

### Tests
`tests/Feature/Admin/ApplicationsTest.php` mirrors
`tests/Feature/Admin/ServicesTest.php`: authorization per action, search/
status/organizational-unit filtering, validation (required fields,
invalid URL, non-http URL scheme), mass-assignment protection,
activate/deactivate, and 404 on an invalid application. The existing
`tests/Feature/Portal/ApplicationsTest.php` (Phase 4) already covers
direct unauthorized requests and visibility/status filtering on the
read-only side and needed no changes.

## Administration Foundation — IMPLEMENTED (Phase 8)

Establishes the structure and conventions the admin area itself follows,
on top of the `AdminLayout`/`AppShell`/`SidebarNav` shell Phase 3 already
built. This phase adds no new business module — it organizes the ones
that already exist (Organizational Units, Services, Systems/Applications,
Roles, Permissions) and gives every future module a place to register
itself without redesigning navigation again.

### Navigation conventions
`resources/js/Utils/navigation.js` is the single source of truth for both
the admin sidebar and the admin dashboard — neither hardcodes its own
module list. `adminModuleGroups` groups each module under a label
(`Portal Management`, `Access Management`, …), and `adminNavItems` spreads
those groups alongside a few ungrouped utility links (Administration,
Authorization demo, Design system). A module belongs in a group once it
has a real admin index route — `Users` is deliberately absent from both,
since only a per-user show/assign-roles route exists today, not a listing
page; adding a `users.index` admin screen is what would earn it a slot.

Each module entry carries:
- `permission` — gates visibility in both the sidebar (`SidebarNav.vue`)
  and the dashboard's module grid.
- `description` — dashboard-only, one line of what the module manages.
- `create` (optional) — `{ permission, href, label }` for the dashboard's
  "Shortcuts" card; omitted for modules with no create action yet
  (e.g. Permissions, which is view-only).

**Adding a module**: append one entry to the right group in
`adminModuleGroups` (or a new group if it's the first of its kind). The
sidebar, the dashboard's module grid, and the dashboard's shortcuts list
all pick it up automatically — no other file needs to change.

### Authorization-aware navigation
`SidebarNav.vue` filters a mixed array of leaf links and group entries:
a leaf is hidden if the user lacks its `permission`; a group is hidden
entirely once none of its children are visible (never rendered as an
empty header). This mirrors the filtering `Admin/Index.vue` does for its
own module grid and shortcuts list via `useAuthorization()`. As with
every other phase, this is a UX convenience only — the routes behind
these links are independently authorized server-side
(`EnsureUserHasPermission` middleware + each controller's
`$this->authorize()` policy check), so a hidden nav item is never the
actual security boundary.

### Admin dashboard (`Admin/Index.vue` + `AdminController`)
- **Management modules** — `adminModuleGroups`, filtered to what the
  signed-in admin can view, rendered as grouped `PortalCard` grids.
- **Access context** — the admin's own role(s) and permission count,
  read directly from the `auth.roles`/`auth.permissions` props
  `HandleInertiaRequests` already shares globally; no new backend prop
  was needed for this.
- **Items requiring attention** — `AdminController::ATTENTION_SOURCES`
  counts real inactive records (`status = inactive`) per module the user
  can view, and only renders a module's row when that count is greater
  than zero. This is deliberately the only "statistic" on the dashboard,
  and it's a literal `COUNT(*) WHERE status = 'inactive'` — never a
  fabricated or placeholder number.
- **Shortcuts** — "New …" links for every module whose `create`
  permission the admin holds.

### UX
Breadcrumbs, page titles (`PageHeader`), the responsive sidebar/mobile
drawer, the user menu, and focus-visible states were all already
established by Phase 3's `AppShell`/`SidebarNav`/`UserMenu`/`Breadcrumbs`
and are unchanged structurally by this phase — `SidebarNav.vue` gained
group-rendering but kept the same responsive classes, keyboard handling
(`Escape` closes the mobile drawer, focus moves into/out of it), and
`aria-current`/`aria-expanded`/`role="dialog"` semantics.

### Tests
`tests/Feature/Admin/AdminDashboardTest.php` — guest redirect, 403
without an admin-qualifying permission, and the "needs attention" counts:
empty when nothing is inactive, correct real counts for permitted
modules, and excluded entirely for modules the user can't view. Direct
unauthorized route access for every individual admin module
(`/admin/organizational-units`, `/admin/services`, `/admin/applications`,
`/roles`, `/permissions`) was already covered per-module in Phase 5/6/7's
own test files and needed no duplication here.

## Users & Roles Management — IMPLEMENTED (Phase 9)

Builds full admin management for Users, Roles, and Permissions on top of
the Phase 2 authorization foundation — no new auth architecture, no new
tables. Scope is deliberately account/access administration only: no
employee HR records, payroll, leave, service records, or any other HRIS
concept belongs here or anywhere else in this codebase.

### User management
`App\Http\Controllers\UserController` gained `index`/`create`/`store`
alongside the existing `show`/`assignRoles`, plus `edit`/`update`/
`updateStatus`/`sendPasswordReset`. Routes stay **unprefixed**
(`/users`, `users.index`, …), matching the Phase 2 precedent set by
`/roles`/`/permissions` rather than the `admin.*`-prefixed convention
Phase 5–7 used for portal-content modules — changing it now would have
meant renaming the already-shipped `users.show`/`users.assign-roles`.

- **Index**: search by name/email, filter by status and role.
- **Create**: name + email only — **no password field exists anywhere in
  this flow**. The account is created with a random, immediately-discarded
  password hash and `Password::sendResetLink()` is called in the same
  request, reusing the exact Phase 1 "forgot password" mechanism. No
  admin ever chooses, sees, or transmits a new user's password in plain
  text.
- **Edit**: name/email only. Status is a separate action
  (`PATCH /users/{user}/status`), the same status-is-its-own-endpoint
  pattern Phase 5–7 established for directory modules. No hard delete —
  `users.delete` is this app's permission name for *deactivation*, not a
  destructive operation, consistent with every other module.
- **Account actions**: "Send password reset link" (`POST
  /users/{user}/send-password-reset`) is the only password-adjacent
  action in the entire admin surface, and it only ever triggers the
  existing reset-link email — it never reads, sets, or displays a
  password or a reset token.

### Role and permission management
`RoleController`/`PermissionController` already existed (Phase 2); this
phase adds the privilege-escalation guards below and a searchable,
resource-grouped permission picker (`resources/js/Utils/permissions.js`
→ `groupPermissionsByResource()`, shared by `Admin/Permissions/Index.vue`
and `Admin/Roles/Edit.vue`) — grouping is derived from the `resource.action`
name itself, so a new module's permissions slot into the UI the moment
they're seeded, with no group/category column to maintain.

### Privilege-escalation protection
All of the following are enforced in `withValidator()` on the relevant
FormRequest — never in the frontend, and never bypassable by a
hand-crafted request:

1. **Granting `super-admin`** (`GuardsSuperAdminRoleGrant` trait, used by
   both `AssignRolesRequest` and `StoreUserRequest`): only an actor who
   already holds the `super-admin` role may grant it to anyone, including
   themselves. (Pre-existing since Phase 2; unchanged here.)
2. **Removing the last active `super-admin`** (`GuardsLastSuperAdmin`
   trait, used by `AssignRolesRequest` and `UpdateUserStatusRequest`):
   the role can never be stripped from, and the account can never be
   deactivated for, the last user who holds it *and is active* — an
   inactive super-admin account doesn't count as a usable fallback, so
   this still blocks correctly even if one exists.
3. **Self-granting permissions** (`AssignPermissionsRequest`): a role's
   permission set can only be *expanded* with permissions the acting
   user already holds themselves. Removing permissions is unrestricted
   (narrowing access is never an escalation). Skipped entirely for an
   actual Super Administrator actor, since `Gate::before` already lets
   them do anything and the rule only exists to limit a non-super-admin's
   reach.
4. **Protected administrators**: `UserPolicy::update`/`assignRoles`/
   `updateStatus` all deny a non-super-admin actor acting on a target who
   holds the `super-admin` role. An actual Super Administrator actor
   never reaches this check — `Gate::before` short-circuits every
   ability for them — so the guard only ever runs for, and only ever
   restricts, a non-super-admin.
5. **Protecting the `super-admin` role itself**: `UpdateRoleRequest`
   unconditionally rejects renaming the role away from `super-admin` or
   deactivating it — *unconditionally* meaning not even a real Super
   Administrator actor can bypass this, because `Gate::before`'s bypass
   is keyed on that exact role name; breaking it would lock out every
   administrator permanently, including the one making the change.
   Display name, description, and the role's permission set remain
   freely editable. Separately, `AssignPermissionsRequest` blocks a
   non-super-admin from touching the `super-admin` role's permissions at
   all (an actual super-admin may, since it doesn't affect their own
   access).

All five guards key exclusively off `RoleName::SuperAdmin->value`
(`'super-admin'`) — never an email address, a hardcoded user ID, or
frontend logic — consistent with how `Gate::before` itself identifies
Super Administrators.

### UX
- `Admin/Roles/Edit.vue` shows a warning `Alert` and disables the name/
  status fields when editing the `super-admin` role, so the restriction
  is visible before a rejected submission, not just enforced after one.
- `Admin/Users/Show.vue` shows a warning `Alert` when the pending role
  change would newly grant `super-admin`.
- `Admin/Users/Edit.vue` and the Service/OrganizationalUnit/Application
  admin pages all share the same activate/deactivate `ConfirmDialog`
  pattern.
- The role/user forms that offer a role picker never list `super-admin`
  as an option to a non-super-admin actor (`UserController::assignableRoles()`)
  — defense in depth on top of guard #1, not a replacement for it.
- `TextInput`/`Select` gained a `disabled` prop (previously unsupported)
  to render the locked `super-admin` role fields.

### Security
- Passwords remain hashed (`'password' => 'hashed'` cast, unchanged) and
  are never serialized to the frontend — `User` keeps `password` and
  `remember_token` in `#[Hidden]`.
- Reset tokens are never displayed or returned by any admin endpoint;
  `Password::sendResetLink()` is a fire-and-forget call whose only
  visible effect is a flash-message confirmation.
- `User` gained `status` in its `#[Fillable]` list (previously missing —
  `UpdateUserStatusRequest`'s `$user->update(['status' => ...])` would
  otherwise have silently no-opped against mass-assignment protection).

### Tests
`tests/Feature/Admin/UsersTest.php` (user CRUD, search/filter, mass-
assignment protection, password-reset-link dispatch via
`Notification::fake()`/`assertSentTo`, status toggling, last-super-admin
deactivation guard, protected-administrator guard, direct unauthorized
route access), `tests/Feature/Admin/RolesTest.php` (ordinary role CRUD;
super-admin role name/status immutability, including for an actual
super-admin actor), and new cases added to
`tests/Feature/Authorization/RoleAssignmentSecurityTest.php` (last-
super-admin role removal, including when only an *inactive* super-admin
remains) and a new `tests/Feature/Authorization/PermissionAssignmentSecurityTest.php`
(self-escalation guard, protected-role guard, confirming an actual
super-admin bypasses both).

## Committee Management — IMPLEMENTED (Phase 10)

A reusable foundation for CSC RO VIII committee record-keeping: who's on
a committee, in what position, and for what span of time. Like
Application/Service before it, this is deliberately record-keeping only
— it does not model meetings, minutes, voting, scheduling, or document
management. It is also, like Users & Roles (Phase 9), an admin-only
module: there is no public/portal-facing directory page, since nothing
in this phase's scope called for one (unlike Services/Applications/
Organizational Units, whose phases explicitly specified a public
directory).

### Data model
Two new tables, following the established "status is its own lifecycle,
dates are plain nullable columns" pattern rather than anything bespoke:

- **`committees`**: `name`, `short_name`, `description`, `status`
  (shared `Status` enum — reused rather than minting a new one, since a
  committee's active/inactive lifecycle is the same concept Services/
  Applications/Organizational Units already use), `effective_from`/
  `effective_until` (both nullable — a committee's own date range is
  optional metadata).
- **`committee_members`**: `committee_id` (FK, cascade delete),
  `user_id` (FK, cascade delete — points at the existing `User` for
  identity; no name/email duplicated here), `position` (free-text
  string, e.g. "Chairperson" — no separate positions table, the same
  "don't build a taxonomy for a handful of values" call Phase 6 made for
  Application's `category`), `effective_from` (required), `effective_until`
  (nullable — open-ended means "currently serving"), `status`.

**No database-level uniqueness on `(committee_id, user_id)`**: a person
can legitimately have more than one membership row for the same
committee over time (a prior term that ended, then a new one). "Only one
*active* membership per user per committee at a time" is a business
rule, not a schema constraint — enforced in
`App\Http\Requests\Committee\Concerns\GuardsDuplicateActiveMembership`,
shared by the add-member, update-member, and update-member-status
requests (including the reactivation path, so flipping an ended
membership back to active is re-checked too).

### Models
`App\Models\Committee` (`members(): HasMany`, `activeMembers(): HasMany`
scoped to `status = active`, `scopeActive()`) and
`App\Models\CommitteeMember` (`committee(): BelongsTo`, `user(): BelongsTo`,
`scopeActive()`). Both cast their date columns as `date:Y-m-d` rather
than the bare `date` cast, so they serialize as plain date strings to
the frontend instead of full ISO datetimes — worth calling out since
it's the first date-column model in the app and the default cast's JSON
shape is easy to miss until you see it rendered.

### Admin CRUD and membership management
`App\Http\Controllers\Admin\CommitteeController` (index/create/store/
show/edit/update/updateStatus) follows the exact Service/Application
admin-CRUD shape — search/status filtering on the index, a separate
status-toggle endpoint, no hard delete. `App\Http\Controllers\Admin\
CommitteeMemberController` (store/update/updateStatus) is nested under
a committee (`/admin/committees/{committee}/members/...`) and explicitly
guards against an IDOR-style request: since `{committee}` and `{member}`
resolve independently via route-model binding, the controller verifies
`$member->committee_id === $committee->id` (404 otherwise) before acting
— without that check, a valid member ID paired with an unrelated
committee ID in the URL would still resolve both models.

**Permissions** (`committees.view/create/update/archive`) — `.view`
added to `AppServiceProvider::ADMIN_ACCESS_PERMISSIONS`.
`App\Policies\CommitteePolicy` adds a `manageMembers` ability gated by
`committees.update` (not a separate membership permission) — the same
choice Phase 9 made reusing `users.update` for role assignment rather
than minting a dedicated permission for every sub-action.

### UX
`Admin/Committees/Show.vue` renders members as stacked cards, not a
`<table>` — unlike every other list page in the app, which uses the
shared `Table` component with horizontal scroll on narrow viewports.
Membership rows carry too much per-row information (person, position,
status, date range, two actions) to read comfortably in a scrolled
table on a phone, so this page trades the reusable `Table` for a
`Card`-per-member layout that reflows naturally at any width. Editing a
membership's position/dates happens inline (the card's display content
swaps for a small form), avoiding a navigation round-trip for what's
usually a one-field correction.

### Tests
`tests/Feature/Admin/CommitteesTest.php` (CRUD, search/status filtering,
validation, mass-assignment protection, activate/deactivate, 404 on an
invalid committee), `tests/Feature/Admin/CommitteeMembersTest.php`
(add/update/end/reactivate a membership, required-field and date-order
validation, the duplicate-active-membership guard — including that a
new membership is allowed once a prior one ended, that the same person
can be active on two different committees at once, and that
reactivation re-triggers the guard — and the cross-committee IDOR
guard), and `tests/Unit/Models/CommitteeModelTest.php` (relationships,
`activeMembers`/`active` scopes, cascade delete, enum casts).

## Announcements — IMPLEMENTED (Phase 11)

A controlled publication system: admins draft, schedule, publish,
unpublish, archive, and feature announcements; authenticated portal users
see only what is live. Out of scope (and absent): email campaigns, push
notifications, social publishing, mass messaging, and any multi-step
editorial/approval workflow. **No announcements are seeded** — the portal
shows nothing until a real one is published.

### Data model
`announcements` (`2026_10_04_090000_create_announcements_table`): `title`,
`slug` (unique), `summary` (≤500, nullable), `body` (text, plain text),
`status` (`draft`/`published`/`archived`, `App\Enums\AnnouncementStatus`),
`publish_at`/`expires_at` (nullable timestamps), `featured`, `author_id`
(→ `users`, `nullOnDelete`), timestamps; composite index on
(`status`, `publish_at`, `expires_at`) for the visibility query.

`App\Models\Announcement` fills only content fields
(`title/summary/body/publish_at/expires_at/featured`); `status`, `slug`,
and `author_id` are set explicitly by the controller, so a crafted
request can't publish on create, pick its slug, or forge its author.

### Publication logic (backend-enforced)
An announcement is visible only when **all** hold:
`status = published` **and** `publish_at ≤ now` **and**
(`expires_at` is null **or** `expires_at > now`).

That rule lives in exactly two places on the model, kept in sync:
`scopePublished()` (queries) and `visibilityState()` (a single record —
returns `draft`/`archived`/`scheduled`/`expired`/`live`; `isPublished()`
is `=== 'live'`). Every portal surface — `/announcements`,
`/announcements/{slug}`, the dashboard section, and search — queries
through `published()` or checks `isPublished()`; anything else is a 404,
including for Super Administrators (they preview through the admin page
instead). Scheduling and expiry need no cron job: they are evaluated at
query time against `now()`.

Announcements deliberately don't use `HasPortalVisibility`: the portal is
authenticated-only and announcements have no audience tiers, so the
public/authenticated/administrative `Visibility` axis would be unused.

**Status transitions** (`AnnouncementStatus::canTransitionTo()`):
draft → published | archived; published → draft (unpublish) | archived;
archived → draft (restore) only — an archived announcement must be
restored and re-published, never published directly. Publishing with no
`publish_at` stamps `now()`; a future `publish_at` schedules it.
Publishing an announcement whose `expires_at` has already passed is
rejected. Once published, `publish_at` becomes required on edit (clearing
it would silently hide the announcement forever — unpublish instead).
`expires_at` must be after `publish_at` when both are set.

**Timezones**: the app stays on UTC. The admin form's
`datetime-local` inputs are converted in the browser
(`resources/js/Utils/datetime.js`) to ISO-8601 instants with an offset
before submit, and back to local time for display, so "8:00 AM" means
8:00 AM where the admin is.

### Admin
`App\Http\Controllers\Admin\AnnouncementController`, routes under
`/admin/announcements`:

| Method | URI | Name | Ability |
|--------|-----|------|---------|
| GET | `/admin/announcements` | `admin.announcements.index` | `announcements.view` |
| GET/POST | `/admin/announcements/create`, `/admin/announcements` | `.create`/`.store` | `announcements.create` |
| GET | `/admin/announcements/{announcement}` | `.show` (preview) | `announcements.view` |
| GET/PUT | `…/{announcement}/edit`, `…/{announcement}` | `.edit`/`.update` | `announcements.update` |
| PATCH | `…/{announcement}/featured` | `.update-featured` | `announcements.update` |
| PATCH | `…/{announcement}/status` | `.update-status` | `announcements.publish` (publish/unpublish) or `announcements.archive` (archive/restore) |

The index (paginated, 15/page) searches title/summary and filters by
status and featured; each row shows both the stored status and the
computed "On portal" state, so *published-but-scheduled* or
*published-but-expired* is never mistaken for live. New announcements are
always drafts; saving redirects to the preview page, which renders the
content exactly as the portal will (same `AnnouncementBody` component),
explains why it is or isn't visible, and hosts the
publish/unpublish/archive/restore/feature actions behind
`ConfirmDialog`. Slugs are generated once from the title
(`HasUniqueSlug`, `-2`, `-3`… on collision) and never change on edit, so
shared portal links stay valid.

**Permissions**: `announcements.view/create/update/publish/archive` in
`PermissionName` (auto-seeded). `publish` is split from `update` so an
office can let someone draft without letting them put content in front
of every portal user. `UpdateAnnouncementStatusRequest::authorize()`
picks the ability per transition (archive-related → `archive`, otherwise
`publish`); the route's `permission:announcements.publish,announcements.archive`
middleware only admits either. `.view` was added to
`ADMIN_ACCESS_PERMISSIONS` and the Administration nav entry's
permission list; the module is registered under *Portal Management* in
`Utils/navigation.js`, and *Announcements* was added to the portal nav.

### Portal
- `/announcements` — live announcements, featured first then newest,
  10/page, title/summary search. Listing payloads carry the summary only
  (`AnnouncementResource` includes `body` only via `->withBody()` on the
  detail page).
- `/announcements/{slug}` — detail page (title, date, author name,
  summary, body).
- Dashboard — up to 3 live announcements (featured first) above Quick
  Access; the section is omitted entirely when none are live.
- Search — live announcements matching title/summary appear as their own
  result group.

### Content security
Rich HTML is **not** supported. `body`, `summary`, and `title` are plain
text, stored verbatim, and rendered only through Vue `{{ }}`
interpolation (auto-escaped) — `AnnouncementBody.vue` splits on blank
lines into paragraphs with `whitespace-pre-line`. No `v-html`, no
Markdown renderer, no sanitizer dependency: with no HTML ever
interpreted, there's nothing to sanitize and no allowlist to keep
correct. The Inertia page JSON embedded in the initial HTML response is
escaped by Laravel's encoder, so a `<script>` in content can't break out
there either. Adding rich text later would require server-side
sanitization (e.g. an HTML purifier with a strict allowlist) at write
time **and** a single audited `v-html` site — not a change to make
casually.

### Tests
`tests/Feature/Admin/AnnouncementsTest.php` — per-action authorization
(including `update` alone can't publish, `archive` can't publish,
`publish` can't archive), search/status/featured filters, draft-on-create
with `status`/`slug`/`author_id` injection ignored, slug uniqueness and
stability on edit, validation (required fields, expiry after publish,
publish date required once published), offset-aware timestamp storage,
preview, publish-now vs. scheduled, refusing to publish an expired
announcement, unpublish, archive/restore, no direct archived → published,
invalid status, feature/unfeature. `tests/Feature/Portal/AnnouncementsTest.php`
— draft/scheduled/expired/archived are hidden from listing and 404 on
detail (even for Super Admin), time-travel tests for crossing the publish
date and the expiry, featured ordering, bodies omitted from listings,
dashboard and search show only live entries, an XSS payload is stored
verbatim but never appears as live markup in the response, and a guard
that no announcement page/component uses `v-html`.

## Audit / Activity Logs — IMPLEMENTED (Phase 12)

An append-only record of sign-ins and administrative changes, viewable
(never editable) by holders of `audit_logs.view`. Out of scope: SIEM
export, infrastructure/real-time monitoring, alerting, retention/pruning.

### Data model
`audit_logs` (`2026_10_05_090000_create_audit_logs_table`): `actor_id`,
`actor_label`, `event` (`subject.action`, `App\Enums\AuditEvent`),
`action` (the verb after the dot, stored for filtering), `target_type`,
`target_id`, `target_label`, `description`, `ip_address`, `user_agent`
(≤512), `metadata` (JSON), `created_at`. **No `updated_at`** and **no
foreign keys**: an entry must survive — and never be rewritten by —
changes to the user or record it points at, so `actor_label`
("Name <email>") and `target_label` snapshot who/what it was at the time.

`target_type` uses short morph aliases (`user`, `role`, `service`,
`announcement`, …) registered with a non-enforced
`Relation::morphMap()` in `AppServiceProvider::configureAuditing()`, so
renaming a model class never orphans its history. The viewer's target
filter only accepts those aliases.

### Writing entries — `App\Support\Audit\AuditLogger`
The only writer. `record(event, description, target?, metadata?)`;
`recordChanges(...)` additionally stores `metadata.changes`
(`{field: {from, to}}` from the model's last save, ignoring timestamps,
`password`, `remember_token`) and **writes nothing if nothing changed**.
Controllers reach it through `Controller::audit()`; many-to-many syncs go
through `Controller::recordPivotChange()`, which records role/permission
*names* added/removed and skips no-op syncs.

**Actor attribution** is always server-side: `record()` uses
`Auth::user()`; no request field is ever read for it. The single
exception, `recordFor($user, …)`, exists for the authentication
subscriber, where the guard itself supplies the user being logged in/out
(during `Login`, `Auth::user()` is not set yet), and for failed attempts
(actor `null`).

**Events recorded** (`AuditEvent`):

| Area | Events |
|------|--------|
| Authentication | `auth.login`, `auth.logout`, `auth.password_reset` (via `AuthenticationAuditSubscriber` on Laravel's `Login`/`Logout`/`PasswordReset` events), `auth.lockout` (`Lockout`), `auth.login_failed` and `auth.login_blocked` (inactive account) — the last two written directly in `LoginRequest`, since it checks credentials itself and never fires `Failed` |
| Users | `created`, `updated`, `status_changed` (activate/deactivate), `roles_changed`, `password_reset_sent` |
| Roles | `created`, `updated`, `permissions_changed` |
| Directory CRUD | `organizational_unit.*`, `service.*`, `application.*`: `created`, `updated`, `status_changed` |
| Committees | `created`, `updated`, `status_changed`, `member_added`, `member_updated`, `member_status_changed` (membership events target the committee; the member is in metadata) |
| Announcements | `created`, `updated`, `published`, `unpublished`, `archived`, `restored`, `featured`, `unfeatured` |

The subscriber is registered explicitly with `Event::subscribe()` and
lives outside `app/Listeners` so event auto-discovery can't register it
twice. Settings changes (Phase 13) are recorded as `setting.updated`, one
entry per changed value.
Requests rejected by authorization or validation never reach the logging
call, so they record nothing.

### Sensitive data
Never stored: passwords, reset/remember/CSRF tokens, session data,
request bodies. Defenses, in order: (1) metadata is assembled by callers
from explicit fields — never `$request->all()`; (2) `recordChanges()`
ignores `password`/`remember_token`; (3) `AuditLogger::sanitize()` drops
any metadata key matching
`password|passwd|secret|token|session|api_key|credential|authorization|cookie`
at any depth, and truncates string values to 255 characters (so an
announcement body edit is a short before/after excerpt, not a revision
store). Failed sign-ins keep only the attempted email and a reason code.

### Immutability and access
- `AuditLog` has `$guarded = ['*']` (nothing mass-assignable — a
  payload can't inject `actor_id`), and `updating`/`deleting` model
  events throw `LogicException`.
- Only GET routes exist (`/admin/audit-logs`, `/admin/audit-logs/{id}`);
  POST/PUT/PATCH/DELETE return 405 even for a Super Administrator, whose
  `Gate::before` bypass is why immutability is enforced in the model and
  routes rather than only in `AuditLogPolicy` (`viewAny`/`view` only).
- Database-level protection (a DB user without `UPDATE`/`DELETE` on
  `audit_logs`, or shipping logs off-box) is an infrastructure concern
  outside this phase.

### Admin view
`Admin\AuditLogController` — `index` paginates 25/page, newest first,
with free-text search (description, actor and target labels), event,
actor (only users who appear in the log), target type + id, and a date
range. Like Phase 11, the browser converts the chosen local dates to
ISO instants (start/end of day) so ranges match the admin's local day.
`show` lists every field plus metadata pretty-printed as escaped text in
a `<pre>`, with "more by this actor" / "history of this target" links.
Viewing the log does not itself create entries. Registered under
*Access Management* in `Utils/navigation.js`; `audit_logs.view` was added
to `ADMIN_ACCESS_PERMISSIONS` and the Administration nav entry.

### Performance
Each audited action adds one INSERT and no extra reads beyond already
loaded models (the pivot helper does one small name lookup, only when a
sync actually changed something). Indexes: `created_at`,
(`event`, `created_at`), (`actor_id`, `created_at`), (`target_type`,
`target_id`, `created_at`) — each filter plus the newest-first sort is an
index range scan. The free-text search is a `LIKE '%…%'` scan; combine it
with a date range on large tables. Writes are synchronous (not queued) so
an entry can't be lost between the action and a worker. They are not
wrapped in the same transaction as the action, so a failed audit insert
after a successful change raises an error rather than rolling back.

### Tests
`tests/Feature/Audit/AuditTrailTest.php` — login/logout/failed/blocked/
lockout/password-reset entries; actor, target and IP/user-agent
attribution; admin (not the new user) credited for user creation; actor
can't be forged via `actor_id`/`actor_label` in the payload; before/after
changes and no entry for no-op saves; deactivation, role and permission
changes as added/removed names; committee membership; announcement
publish/unpublish/archive/restore; rejected requests log nothing; secret
keys stripped at any depth, long values truncated, and no password,
reset token, or new password stored anywhere.
`tests/Feature/Admin/AuditLogsTest.php` — authorization, pagination
order, each filter (event, actor, target, date range incl. timezone
offsets, search), unknown target type ignored, 405 on every write verb
even for Super Admin, model refuses update/delete, no mass assignment.

## System Settings — IMPLEMENTED (Phase 13)

Application-level portal configuration editable by administrators. Out of
scope by design: `.env`, database credentials, API secrets, private keys,
server/mail configuration, and anything that executes code. Those stay in
the environment; this module never reads `env()`/`config()` values and
never sends them to the browser.

### Definitions in code, values in the database
`App\Support\Settings\SettingRegistry::definitions()` is the single list of
settings that exist — key, type, group, label, description, default,
`editable`, and validation bounds. `SettingRegistry::sync()` (run by
`SettingSeeder`, also called from `DatabaseSeeder`; safe to re-run on every
deploy) writes them into `settings`
(`2026_10_06_090000_create_settings_table`: `key` unique, `value` text,
`type`, `group`, `label`, `description`, `editable`, `constraints` JSON,
`sort_order`, timestamps):

- metadata always follows the code;
- an editable setting's saved value is kept;
- a protected setting's value is reset to its default (so changing one is
  a reviewed code change + re-seed);
- rows for settings no longer defined are deleted;
- a key that looks like a credential (`password`, `secret`, `token`,
  `api_key`, `db_*`, …) makes `sync()` throw.

There is no route or UI that creates or deletes a setting. `Setting` has
`$guarded = ['*']`; the controller assigns `value` explicitly, so a payload
can't flip `editable` or change a `type`.

### Types (`App\Enums\SettingType`)
`string` (≤255, or the setting's `max`), `text` (≤5000 or `max`),
`integer` (`min`/`max`), `boolean`, `url` (`http`/`https` only — no
`javascript:`), `email`. Each type owns its validation rules, its
serialization to the stored string (booleans `'1'`/`'0'`, empty → `null`),
and casting back on read. Non-boolean values are nullable unless the
definition sets `required`. There is intentionally no secret/password type.

### Groups (`App\Enums\SettingGroup`)
`general`, `portal`, `contact` — each rendered as its own card and saved as
its own form.

| Key | Type | Group | Used by |
|-----|------|-------|---------|
| `organization_name` | string, **protected** | general | Public layout footer |
| `portal_name` | string, required, ≤60 | general | Sidebar/header in `AppShell` |
| `portal_welcome_message` | text, ≤300 | portal | Portal home description |
| `portal_home_announcement_limit` | integer 1–10 | portal | `PortalController` announcement count |
| `portal_notice_enabled` / `portal_notice` | boolean / text ≤500 | portal | Banner on every signed-in page (`AppShell`) |
| `contact_email` / `contact_phone` / `contact_website_url` | email / string / url | contact | "Need help?" on portal home |

### Reading values — `App\Support\Settings\Settings`
`Settings::get($key)` returns the typed value. All settings load with one
query and are cached forever under `settings.values`. A missing row or a
null value falls back to the registry default; reading an undefined key
throws (catches typos). Before the `settings` table exists (fresh
install), every read returns defaults, and that result is not cached.

**Cache invalidation**: `Setting`'s `saved`/`deleted` model events call
`Settings::flush()` via `DB::afterCommit()`. Any write path invalidates,
and the flush happens only after the transaction commits, so a
concurrent request can't re-cache the old value in between. `sync()` also
flushes explicitly because seeders may run with model events disabled.

`HandleInertiaRequests` shares an explicit whitelist as `portal`:
`name`, `organization`, and `notice` (only for signed-in users, and only
while enabled). It never shares the whole table.

### Admin, routes, authorization
| Method | URI | Name | Permission |
|--------|-----|------|------------|
| GET | `/admin/settings` | `admin.settings.index` | `settings.view` |
| PUT | `/admin/settings/{group}` | `admin.settings.update` | `settings.update` |

`{group}` is bound to `SettingGroup`, so an unknown group returns 404.
`SettingPolicy` (`viewAny`, `update`) is checked in the controller and in
`UpdateSettingsRequest`, as well as by the route middleware. `settings.view` was
added to `ADMIN_ACCESS_PERMISSIONS` and the Administration nav entry; the
module appears under a new **System** group in `Utils/navigation.js`.

`UpdateSettingsRequest` validates each submitted key with its type's
rules (labels used as attribute names) and changes only submitted keys.
It rejects the **whole** request if any key is unknown, belongs to
another group, or is protected. Protected settings are enforced here
rather than in the policy because Super Administrators pass every policy
via `Gate::before`. Nobody can change them through the application.
A group is saved in one transaction.

The page (`Admin/Settings/Index.vue` + `Components/Settings/SettingsGroupForm.vue`)
renders the input that matches each type (checkbox, textarea, number with
min/max, url, email, text), shows protected settings as read-only values
with a "Protected" badge, shows save feedback per group ("Saved." in an
`aria-live` region, or "Not saved" with field errors), and disables Save
until something changes. Holders of `settings.view` without
`settings.update` see disabled inputs and an explanatory notice.

### Audit
Each changed value is recorded as `setting.updated` (target `setting`,
labelled by the setting's label, metadata `key`, `group`, and
`changes.value` from/to, truncated by `AuditLogger` like any other value).
Unchanged values and rejected requests record nothing. `AuditLogger::labelFor()`
now also considers a `label` attribute (only `Setting` has one).

### Tests
`tests/Feature/Admin/SettingsTest.php` covers:

- **Access:** guest redirect; 403 for no permission and for view-only users
  on direct PUT requests; `settings.update` alone can't view; 404 for an
  unknown group.
- **Persistence:** typed values and casting; only submitted keys change;
  booleans can be switched off; empty values are stored as null and read
  as the default.
- **Validation:** per-type checks (integer bounds and decimals,
  booleans, text/string length, required, URL schemes including
  `javascript:`, email); one invalid value saves nothing in the group;
  unknown and cross-group keys are rejected.
- **Protected settings:** refused for editors and Super Admin;
  `editable`/`type` can't be changed via the payload or mass assignment.
- **Cache:** values are cached on first read; the cache is invalidated by
  updates and by any direct model save; the next request's shared props
  show the new value; a rejected update leaves the cache alone.
- **Audit:** per-setting entries with from/to; no entry for a no-op save.
- **Secrets:** no app key or DB password in the page; the page shows
  exactly the registry keys; shared props are a whitelist.
- **Sync:** keeps edited values, resets protected ones, removes obsolete
  rows; defaults apply when nothing is seeded.
- **Behavior:** the notice banner, the portal home announcement limit,
  and the welcome message actually change.

## Global Search & UX Refinement — IMPLEMENTED (Phase 14)

One search over the four portal content types, plus shell-wide
keyboard/loading refinements. Out of scope: Elasticsearch/OpenSearch or
any search engine, external systems, and administrative records (users,
roles, audit logs, settings, committees are never searched).

### Request
`GET /search?q=…[&type=…][&page=…]` (`auth`; `PortalSearchController`,
thin — all logic is in `App\Support\Search\PortalSearch`).
- `q` is whitespace-squished and capped at 100 characters; a non-string
  `q` (e.g. `q[]=`) is treated as empty. Queries shorter than 2
  characters run no SQL at all.
- `type` must be an `App\Enums\SearchType` value (`services`,
  `applications`, `organizational_units`, `announcements`); anything else
  (e.g. `type=users`) is ignored and the "all" view is shown.
- **All view**: `counts` per type, and `groups` — for each type with
  matches, in the order above, its true `total` and the top 5 results.
- **Filtered view** (`type` given): `results`, a 15-per-page paginator
  (`withQueryString()`, so page links keep `q`/`type`), plus `counts` for
  every type so the filter tabs still show totals.

### Authorization — same scopes as the portal listings
Each type's query starts from the scope its own listing/detail page uses,
so search can't show anything browsing can't:

| Type | Scope |
|------|-------|
| Services, Systems/Applications | `visibleTo($user)` — active, and `administrative`-tier only for `access-admin` users |
| Organizational Units | `active()` |
| Announcements | `published()` — never draft, scheduled, expired, or archived, even for announcement admins |

The result `href`s point at the normal detail routes, which re-check
visibility (404 otherwise), so a guessed or stale link is refused too.

### Matching, ranking, payload
- Case-insensitive `LIKE '%q%'` over: services `name`/`short_name`/
  `description`; applications `name`/`short_name`/`description`/
  `category`; units `name`/`short_name`/`code`/`description`;
  announcements `title`/`summary` (never `body`).
- `%` and `_` typed by the user match literally: they are escaped with
  `ESCAPE '!'` (`!` rather than `\`, since it means the same in MySQL and
  SQLite literals). Verified against both.
- Ranking: exact title match, then title prefix, then other matches; ties
  by `sort_order`/`name` (announcements: featured, then newest).
- Every type is presented in one lean shape — `type`, `type_label`, `id`,
  `title`, `subtitle`, `excerpt` (≤180 chars), `date` (announcements),
  `href` — never the full resources (no service procedures/requirements,
  no announcement bodies).

### Performance
Per request: 4 `COUNT` queries + at most 4 preview queries (only for types
that matched) + 1 eager load of service categories — or, filtered, the
counts + 1 page + its eager load. Only the needed columns are selected,
every result set is bounded, and the query count does not grow with the
number of matches (tested). The visibility filters use the existing
composite indexes (`status`, `visibility`, `sort_order` on
services/applications; `status`, `sort_order` on units;
`status`, `publish_at`, `expires_at` on announcements). The `%q%` text match
itself can't use a B-tree index. It runs over the already-filtered rows of
small directory tables. If they grow large, the next step is a MySQL
`FULLTEXT` index on the title/summary columns. No separate search
infrastructure is needed.

### UI
`Pages/Portal/Search.vue` + `Components/Search/SearchResultItem.vue`
(replacing `Components/Portal/SearchResults.vue`):
- labelled search box with clear button (Esc also clears) and submit;
  a new query keeps the current type filter;
- type filter as real links (`All (n)`, `Services (n)`, …) with
  `aria-current="page"`, horizontally scrollable on phones;
- states: empty query, "keep typing" (below 2 characters), no results
  (with links to browse each directory), per-type empty page;
- loading: the Search button spinner, dimmed `aria-busy` results, and a
  `role="status"` live line ("Searching…" → "12 results for “x”");
- results in `<section>`s with `<h2>` group headings and `<h3>` titles,
  each with a text type label (Service, System, Organizational unit,
  Announcement), subtitle, excerpt, date; "View all n" links into the
  filtered view.

### Shell-wide refinements
- **Header search** (`PortalSearch.vue`): each instance now has its own
  `id` (the desktop and mobile boxes, plus the old page copy, used to
  share `id="portal-search"`, breaking label association); clear button;
  Esc clears, then blurs; mirrors the current query on `/search`; shows a
  `/` hint on desktop.
- **Shortcut**: `/` (when not typing in a field) or Ctrl/⌘+K focuses the
  last visible `[data-portal-search]` box — the page's own box on
  `/search`, otherwise the header's (`AppShell.vue`, `aria-keyshortcuts`
  declared on the inputs).
- **Skip link**: "Skip to main content" is the first focusable element in
  `AppShell`, targeting `<main id="main-content" tabindex="-1">`.
- **Navigation progress bar**: Inertia's progress bar now uses the
  `--color-accent` token and appears only for visits slower than 250 ms.

### Tests
`tests/Feature/Portal/SearchTest.php` covers:

- **Access:** guest redirect; administrative-tier records hidden from
  regular users and visible to admin users; direct URLs to restricted
  records return 404.
- **Query handling:** empty, too-short, non-string and over-long queries.
- **Matching:** grouping and order, type labels, hrefs, secondary fields,
  case-insensitivity, ranking, literal `%`/`_`.
- **Results:** no results; the lean payload (no service procedure text);
  the bounded preview with the true total.
- **Filtering and pagination:** the type filter, 15 per page with query
  params kept, and an unknown/admin `type` falling back to all.
- **Restricted records:** inactive services, systems and units, and
  draft, scheduled, expired and archived announcements never appear,
  even for administrators.
- **Performance:** the query count doesn't grow with result count.

`Portal/AnnouncementsTest` was updated to the new response shape.

## Per-System Roles — IMPLEMENTED (Phase 15)

Each person can hold one role inside each external system (OTRS
"Admin", PROCTAD "User", …). This carries over the legacy IMIS's
`imis_access_roles` / `imis_system_access` model. These roles are
**separate from IMIS platform roles** (Phase 2). They grant nothing
inside IMIS. They record a person's role in a system, decide whether
they get that system's launch link, and set where it lands them. IMIS
still doesn't do SSO or pass the role to the system. The legacy
`api/system-redirect.php` handoff remains out of scope.

### Data model
| Table | Purpose | Key constraints |
|---|---|---|
| `application_roles` | Roles defined per system: `name`, `description`, optional `launch_url`, `status`, `sort_order` | unique (`application_id`, `name`) |
| `application_access` | A user's grant: `application_id`, `user_id`, `application_role_id`, `granted_by` | unique (`application_id`, `user_id`) — one role per system; composite FK (`application_id`, `application_role_id`) → `application_roles` (`application_id`, `id`), so the database rejects a grant that points at another system's role |
| `applications.requires_access` | Restricted system: only role holders get its launch link | boolean, default false |

Models: `ApplicationRole` and `ApplicationAccess`. `Application` gains
`roles()`, `accessGrants()`, `scopeWithAccessFor($user)`,
`accessFor($user)` and `launchUrlFor($user)`; `User` gains
`applicationAccess()`.
Revoking access deletes the grant row, the same as the `role_user`
pivot; the audit log keeps the history. Roles are never hard-deleted.
They can be deactivated only once nobody holds them, so no grant ever
points at an unassignable role.

### Launch resolution (backend-only)
`ApplicationResource.url` is resolved for the viewer and never taken
straight from the column. A role holder gets their role's `launch_url`,
falling back to the system URL. For anyone else, an open system gives its
own URL and a restricted one gives `null`. The link is withheld from the
props, not just hidden in the UI. Every portal query that feeds the
resource (applications index, organizational-unit page, dashboard)
eager-loads only the viewer's grant via `withAccessFor()`. That is one
query per listing, not one per card. The resource also exposes
`requires_access` and `role`. The dashboard adds a **My Systems** section:
visible systems the viewer holds a role in.

### Administration
- **Per system**: `/admin/applications/{application}/access`
  (`Admin\ApplicationAccessController`, `Admin\ApplicationRoleController`,
  page `Admin/Applications/Access.vue`). Lists the system's roles (with
  holder counts) and the people with access; grant, change role, revoke.
- **Per user**: a "System access" card on `Admin/Users/Show.vue` saves one
  person's role in every system at once (`PUT /users/{user}/system-access`,
  `UserSystemAccessController`). It only touches the systems submitted, and
  the whole save is rejected if any entry is invalid.
- Both screens write through `App\Support\SystemAccess\SystemAccessManager`,
  the single writer that sets `granted_by` from the authenticated actor.
  `granted_by` is never mass-assignable.

### Authorization
- Defining a system's roles is part of editing the system:
  `ApplicationPolicy::manageRoles` → `applications.update`.
- Granting/changing/revoking: new permission `applications.manage_access`
  (`ApplicationPolicy::manageAccess`) **and**
  `UserPolicy::manageSystemAccess($target)`, which denies a
  non-super-admin acting on **themselves**, so they can't make
  themselves an administrator of another system, or on a Super
  Administrator (the same protected-target rule as Phase 9). A real
  Super Administrator bypasses both through `Gate::before`.
- Validation: the role must be an active role of the same system, the target
  account must be active to receive a new grant, and a person can be
  granted a system only once (after that, change their role).

### Audit
Events `application.role_created/role_updated/role_status_changed` and
`application.access_granted/access_changed/access_revoked`. Every event
targets the application, so a system's history reads as one timeline, with
the affected user and role names in metadata.

### Tests
`tests/Feature/Admin/ApplicationAccessTest.php` covers role CRUD and
uniqueness per system, the held-role deactivation guard, cross-system 404s,
permission checks, the self-grant and protected-target guards, the
super-admin bypass, `granted_by` mass-assignment protection, the database's
composite-FK rejection, and the per-user save (partial, atomic).
`tests/Feature/Portal/SystemAccessTest.php` covers URL withholding for
restricted systems (including in listings and on the dashboard), role
launch-URL override, My Systems scoping and visibility, and the
one-query-per-listing eager load.

## System Maintenance Mode — IMPLEMENTED (Phase 16)

A system can be marked **under maintenance**, carrying over the legacy
IMIS's `imis_dash_division_systems.is_maintenance`. While it's on, the
portal shows a notice and withholds the system's launch link. As in the
legacy system, the people responsible can still open it.

### Data model
`applications` gains `under_maintenance` (boolean), `maintenance_message`
(nullable, at most 500 characters, plain text shown escaped) and
`maintenance_started_at`. These fields are deliberately **not**
`#[Fillable]`: they change only through `Application::startMaintenance()`
and `endMaintenance()`, never as a side effect of the general edit form.
Updating the notice while maintenance is running keeps the original start
time. The flag is per system, not per dashboard placement as it was in
the legacy schema.

### Launch behavior
`Application::launchUrlFor($user)` returns `null` while the system is under
maintenance, unless `maintenanceBypassedBy($user)` holds. Bypass is the
new `applications.maintenance` permission, which Super Administrators
always pass. The legacy system allowed superadmins only; here whoever puts
a system into maintenance can also verify it. Bypass only lifts the
maintenance block: a restricted system still needs a role (Phase 15). As
with restricted access, the URL is left out of the props entirely.
`ApplicationResource` adds `under_maintenance`, `maintenance_message` and
`maintenance_started_at`. Single-record pages load the viewer's grant
with `loadAccessFor()`, the counterpart of `withAccessFor()`.

### UI
- Portal: `PortalCard` gains a `maintenance` prop ("Under maintenance"
  badge, which replaces "Featured"), used by every application grid. The
  detail page shows the notice, plus a line telling bypassing users why
  they can still open it. The dashboard lists every *visible* system
  under maintenance (`maintenanceNotices` prop), like the legacy notice
  list.
- Admin: a Maintenance card on `Admin/Applications/Edit.vue` (start, update
  notice, end with confirmation) for holders of `applications.maintenance`;
  a "maintenance" badge on the Systems index and on the Roles & access
  page.

### Authorization and audit
`PATCH /admin/applications/{application}/maintenance`
(`admin.applications.update-maintenance`) is guarded by
`permission:applications.maintenance` and `ApplicationPolicy::maintenance`
through `UpdateApplicationMaintenanceRequest`. Events
`application.maintenance_started` (with the notice),
`application.maintenance_updated` (notice changes) and
`application.maintenance_ended`. Ending maintenance that isn't running
records nothing.

### Tests
`tests/Feature/Admin/ApplicationMaintenanceTest.php` covers permission
checks, start/update/end transitions and their audit events, start-time
preservation, protection against mass assignment through the edit form,
validation, link withholding (including from role holders and in
listings), bypass for permission holders and Super Administrators, bypass
not overriding restricted access, and dashboard notices respecting
visibility.

## Legacy Sign-in Experience & Account Features — IMPLEMENTED (Phase 17)

Carries over the legacy IMIS sign-in flow and profile menu, rebuilt for
this stack.

### Username sign-in
- `users.username` is unique, lowercase, 3–64 characters, matching
  `^[a-z0-9][a-z0-9._-]*$`. `App\Support\Usernames` follows the legacy
  convention: initials of the given/middle names plus the surname
  ("Juan Dela Cruz" → `jdcruz`), with a numeric suffix when taken. The
  migration backfills every existing account. Administrators can set a
  username on create (blank = generated) and edit it later.
- `LoginRequest` authenticates by username; input is trimmed and
  lowercased, and the error is the same for an unknown user or a wrong
  password. Throttling and the failed/blocked/lockout audit entries are
  keyed on the username. Password resets still go by email.
- `users.last_login_at` / `previous_login_at` (`User::recordLogin()`)
  drive "Last login" in the account menu.

### Login screen extras
- **Administrator toggle** (legacy "Superadmin Login"): `mode=admin` only
  changes the landing page. Users with `access-admin` land on `/admin`;
  anyone else lands on the dashboard with an explanatory flash. It never
  grants access.
- **Turnstile** (legacy "Security Check"): enabled only when
  `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are set
  (`config/services.php`). The widget renders inside the login form, and
  `App\Rules\Turnstile` verifies the token server-side via siteverify,
  failing closed if Cloudflare is unreachable. The token is reset after
  every failed attempt.
- **Caps Lock warning** and a **live lockout countdown**: `LoginRequest`
  flashes `lockout_seconds`, which the page counts down; the Login button
  stays disabled until it reaches zero.

### Preloader and splashes
- **Boot preloader** (`resources/views/app.blade.php`): the legacy
  loading screen, styled with the design tokens and removed by `app.js`
  once the first page renders. It never adds a delay.
- **Transition splash** (`Composables/useSplash.js` +
  `Components/Feedback/TransitionSplash.vue`, mounted beside the page in
  `app.js` so it survives page swaps):
  - *Signing out*: a confirmation dialog, then "Signing Out — See you
    soon, {name}", held until the login page has rendered underneath.
    Logout now returns to `/login` with "You have been signed out."
  - *Signing in*: the server flashes `signed_in`, and `AppShell` raises
    "Login Successful — Welcome back, {name}" during setup, so it paints
    in the same frame as the new page and the page never shows through.
  Both respect `prefers-reduced-motion`.

### About dialog
`Components/Portal/AboutDialog.vue`, opened from the top bar's About
button or the account menu. It revamps the legacy About content (what
IMIS is, mission, what you can do, a "Rebuilt on a modern stack" note)
and keeps the ITG / agency / location / version footer. The version comes
from `config('app.version')` (`IMIS_VERSION`, shared as `app.version`).

### Account menu
Name, @username, email and last login; then Administration (when
permitted), Change password, What's New, About IMIS, and Sign out.
**Change password** (`/account/password`, throttled) re-checks the
current password, applies `Password::defaults()`, and is audited as
`user.password_changed` without the password.

### What's New (release notes)
Carries over `imis_changelog` / `imis_changelog_items` as `changelogs` /
`changelog_items`.
- **Admin** (`/admin/changelogs`): write a release with a version, title,
  date and ordered entries (New / Improved / Fixed), with a live preview.
  Publishing is a separate action. Permissions: `changelogs.view`,
  `changelogs.manage`, `changelogs.publish`. Audited as
  `changelog.created/updated/published/unpublished`.
- **Portal**: the dashboard pops up the latest published release once per
  user, tracked in `users.last_seen_changelog_id` (the legacy tracked it
  per session). `/whats-new` lists every published release. Closing the
  pop-up or opening the page marks the release as seen.

### Tests
`tests/Feature/Auth/UsernameLoginTest.php` covers username normalization,
the email login no longer working, the generic error, last-login
tracking, the lockout countdown prop, the admin toggle (including that it
never grants access), Turnstile off / required / verified / fail-closed,
the logout redirect, and username generation and validation.
`tests/Feature/Auth/ChangePasswordTest.php` covers the password change,
the shared props and the one-time sign-in flag.
`tests/Feature/Admin/ChangelogsTest.php` covers permissions, drafts,
mass-assignment protection, validation, entry replacement, publishing,
the once-per-user pop-up, and the history page.

## Dashboard Systems Directory (Division Drill-down) — IMPLEMENTED (Phase 18)

The legacy dashboard's main view, Category → Division → System, rebuilt
from data this platform already holds instead of the legacy
`imis_dash_categories` / `imis_dash_divisions` tables.

### Grouping (`App\Support\Portal\SystemDirectory`)
- **Categories**: one per unit type (Regional Support Units, Field
  Offices, Satellite Offices, Other Offices — `UnitType::pluralLabel()`),
  then **Committees**, then **Other** for systems with no office.
- **Divisions**: active organizational units and committees that have at
  least one system the viewer can see.
- **Placement**: a system appears under the unit that manages it
  (`organizational_unit_id`), plus anywhere it is *also listed*. That is
  the new `application_listings` polymorphic pivot (unit or committee,
  via the morph map), carrying over the legacy rule that one system can
  sit under several divisions. A system with neither falls into
  "Other Systems", so no visible system is ever unreachable.
- Only `visibleTo()` systems are included, each shaped by
  `ApplicationResource`, so launch URLs, roles, restricted access and
  maintenance follow the same rules as every other page. Systems are
  sent once, keyed by id; divisions reference them by `system_ids`.

### Navigation
The open division is the `division` query parameter
(`/dashboard?division=unit-3`), changed with an Inertia partial reload
(`only: ['division']`). The directory prop is a closure, so it isn't
rebuilt when switching divisions. A division can be bookmarked, and the
browser's Back button returns to the directory, which the legacy
client-side view couldn't do. Focus moves to the division heading when
opening one and back to its card when returning. Each view has its own
client-side search (divisions and their system names; systems within a
division).

### Launch states (`SystemLaunchCard.vue`)
These are the legacy button states, decided entirely by server-resolved
fields: **Access System** with the viewer's role badge ("Guest" for open
systems), **Access (maintenance)** for maintenance bypass, **Under
Maintenance**, **No Access** (restricted, no role) and **Not configured
yet**. Each card also states the reason in plain text, not only through
color or a tooltip.

### Dashboard layout
The dashboard now follows the legacy order: banner → stats → maintenance
notice → announcements → My Systems → **Systems Directory** → Services →
Need help. The Phase 4 Quick Access and Organizational Units sections were
removed (the directory supersedes both; `QuickAccess.vue` was deleted).
The Organizational Units and Applications pages remain in the sidebar.

### Real offices
`RegionalOfficeSeeder` seeds the 15 actual CSC RO VIII offices from the
legacy division map (7 Regional Support Units, 7 field offices, 1
satellite office), matched by code, so it is safe to re-run and keeps admin
edits. It replaces the Phase 4/5 demo units in `PortalDemoSeeder`.

### Admin
The system form gains "Also list under these offices / committees"
(`CheckboxGroup.vue`). Lists are synced only when the field is submitted
and audited as `application.listings_changed` (names added/removed).

### Tests
`tests/Feature/Portal/SystemDirectoryTest.php` covers category order and
grouping, multi-placement without duplication, visibility and inactive
divisions, per-viewer launch state with no restricted-URL leak, the
division query parameter, and admin listing sync, audit and validation.

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
    -> Authorization (IMPLEMENTED — Phase 2)
    -> UI/UX design system & application shell (IMPLEMENTED — Phase 3)
    -> IMIS portal foundation & dashboard (IMPLEMENTED — Phase 4)
    -> Organizational structure & Regional Support Units (IMPLEMENTED — Phase 5)
    -> Services directory (IMPLEMENTED — Phase 6)
    -> Systems / Applications directory, full management (IMPLEMENTED — Phase 7)
    -> Administration foundation: navigation, dashboard, grouping (IMPLEMENTED — Phase 8)
    -> Users & Roles Management, full CRUD + privilege-escalation protection (IMPLEMENTED — Phase 9)
    -> Committee Management, members/positions/effective dates (IMPLEMENTED — Phase 10)
    -> Announcements, draft/schedule/publish/expire/archive (IMPLEMENTED — Phase 11)
    -> Audit / activity logs, append-only with read-only viewer (IMPLEMENTED — Phase 12)
    -> System settings, typed/grouped/protected, cached (IMPLEMENTED — Phase 13)
    -> Global search & UX refinement (IMPLEMENTED — Phase 14)
    -> Future integrations (SSO, cross-application auth, health monitoring)
```

None of the modules below Phase 14 exist yet. Phase 0 only ensures the
project structure (`app/Actions`, `app/Services`,
`resources/js/Composables`, etc.) can accommodate new modules without
restructuring — those directories are created when the first module that
needs them is built, not preemptively.

### How a future module adds permissions and policies
A new module defines its own permission rows (`resource.action`, e.g.
`applications.view`) — via a seeder or an eventual permissions-management UI —
and calls `App\Support\PermissionCache::flush()` after creating them so the
next request's `Gate::define` loop picks them up. It adds its own
`App\Policies\{Model}Policy` (auto-discovered by Laravel's naming
convention) with methods that call `$user->hasPermission('resource.action')`,
exactly like `RolePolicy`/`PermissionPolicy`/`UserPolicy` do today. No
change to `AppServiceProvider`, the `Role`/`Permission` models, or the
existing policies is required — the authorization architecture does not
need to be redesigned as modules are added.

### Future Portal Architecture — PLANNED
New portal content types follow the pattern Phase 4 established for
Applications/Services/Organizational Units: a thin read-only controller,
Inertia Resources for props, and pages built from the Phase 3 component
library, plus admin CRUD following the Phase 5/6/7 admin pattern
(`Admin\{Model}Controller`, `Policies\{Model}Policy`, `{model}.{action}`
permission cases). Announcements (Phase 11) followed it but replaced
`HasPortalVisibility` with its own time-window `scopePublished()`, since
its visibility is about publication dates rather than audience tiers.
Committees (Phase 10) did
*not* follow this directory pattern — it has no `HasPortalVisibility` and
no public page, since its own phase scope only called for admin
management, the same admin-only shape Users & Roles (Phase 9) took.

A future *public* (unauthenticated) portal variant of these directory
pages would use `PublicLayout` instead of `PortalLayout` (the authenticated
application shell as of Phase 3), the same way `Home` does today — the
`Visibility::PublicVisibility` tier already established in Phase 4 exists
for exactly this, even though no unauthenticated portal route uses it yet.

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
