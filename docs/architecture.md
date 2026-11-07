# Architecture

This document describes the technical foundation of the CSC RO VIII
Next-Generation IMIS and distinguishes what is **IMPLEMENTED** (Phase 0 —
foundation, Phase 1 — authentication, Phase 2 — authorization, Phase 3 —
UI/UX design system & application shell, Phase 4 — IMIS portal foundation &
dashboard, Phase 5 — organizational structure & Regional Support Units,
Phase 6 — services directory, through Phase 19 — legacy admin panel adaptation,
Human Resources HR-1 to HR-7, and hardening; see each section below) from what is **PLANNED** (future phases).
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
  already-loaded `activeRoles`/`activeRoles.permissions` relation on the
  model instance (`loadMissing`), so repeated checks on the same request's
  `$request->user()` do not re-query.
- **Only active roles grant anything** (`activeRoles()`, since
  2026-10-08): deactivating a role takes its permissions from everyone
  holding it without unassigning it, and reactivating gives them back.
  `roles()` still lists every assigned role for the assignment screens,
  which mark inactive ones. Queries for "people with permission X" go
  through `activeRoles.permissions`. The Super Administrator role can't be
  deactivated, so this can't lock anyone out.

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
| GET    | `/authorization-demo`       | `authorization.demo`      | any authenticated user; registered only under `APP_ENV=local` |
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
  `--color-warning`, `--color-danger` (aliases `--color-csc-red-dark`
  since the UI review — see below),
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

*UI review (accessibility and flat design):* a later review found the
gradients, blur, orbiting circles, card shadows and hover lifts that had
crept back in while matching legacy screens, and removed them (banners
are solid `bg-primary`, the header is solid, the sign-in splash is a
plain card). One exception was later restored on request: the legacy
orbiting circles on the navy banners — the portal welcome
(`WelcomeBanner.vue`) and the admin / HR dashboards
(`DashboardBanner.vue`), all from one component, `UI/OrbitCircles.vue` —
faint white on the flat navy, decorative (`aria-hidden`) and still under
`prefers-reduced-motion`. The review also brought the colors up to WCAG AA:
- `--color-danger` is the darker CSC red (5.4:1 on `danger-bg`, 6.2:1
  with white text); the brand red stays as `accent`, for decoration only.
- `--color-bypass` was darkened to `#b04f00` (5.3:1 with white text).
- `--color-border-strong` (3.3:1) outlines form controls, per WCAG
  1.4.11; `--color-border` stays for cards and dividers.
- Navy-sidebar headings and footer use `text-white/70`, not `/50`.
- A base `:focus-visible` outline covers any link or button without its
  own ring; the portal sidebar uses a white ring; the account menu is a
  disclosure (no `role="menu"`) whose items show a ring, and Escape
  returns focus to its button.
- `Button` gained `size` (`sm`/`md`), an `outline` variant, and
  `href` (an Inertia link, or with `external` a new-tab `<a>`), so
  pages no longer hand-style buttons.
- `AdminCard` was merged into `Card` (one bordered, shadowless card with
  `title`, `icon`, `description`, and `actions`/`header`/`footer` slots).
  Card titles are sentence case in body color, so the navy `PageHeader`
  title is the only navy heading on a page.
- List tables share one header style, the `table-head` utility in
  `app.css`; `Badge` and `StatusBadge` share one tone map
  (`Components/UI/tones.js`).
- Dashboards: `MetricCard` is a flat bordered card (no left accent bar),
  and the `warning` tone is kept for figures that need attention
  (vacancies, retirements). The "Data as of · Refresh" row moved into
  `DashboardBanner` (`generatedAt`, `refreshing`, `@refresh`), and normal
  counts such as inactive accounts are no longer shown in red.

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
permission? }`. An admin group may also hold sections — `{ section,
icon, items }` — rendered as collapsible categories one level down
(Human Resources → Personnel, Plantilla & Staffing, Leave, Learning &
Development), each opened when it holds the current page; empty ones
are dropped, and the portal sidebar lists their links flat. Only the most
specific matching link is marked active (`/admin/hr/leave/types` →
Leave Types, not Credits at `/admin/hr/leave`).
`SidebarNav.vue` filters out any item whose `permission`
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
rendering a placeholder. `GET /` (`home`) redirects: an authenticated
user to `/dashboard`, a guest to `/login`. There is no public landing
page; the login screen is the front door, as in the legacy IMIS.
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

No real CSC RO VIII unit/service names were invented. The demo-labeled
placeholder units and services first seeded here were later removed (see
"Demo data removal" below). (`organizational_units` gained a
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
Settings). A stats bar (`StatsBar`) and a "Recently Accessed" strip were
added and later removed. The live legacy dashboard hides both
(`.stats-bar, #recentlyAccessedSection { display: none }`, marked
"Hidden per request"), so the banner leads straight into the notices and
the Systems Directory.

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
over-engineering taxonomy" instruction. Categories are picked from a
dropdown when managing a Service and filter the portal directory; both
pickers list active categories only, by `sort_order` then name
(`ServiceCategory::scopeOrdered()`).

### Category admin (`/admin/service-categories`)
`Admin\ServiceCategoryController` + `Pages/Admin/ServiceCategories/Index.vue`,
the same one-table, Add / Edit-in-a-modal layout as Services: `index`
(with each category's service count), `store`, `update` and
`updateStatus`. Name is required and unique; the slug is generated on
create and never accepted from the form. No delete: a deactivated
category drops out of the pickers, but services keep their link to it.
- **Permissions:** the `services.*` permissions (`view`, `create`,
  `update`, `archive`), via `ServiceCategoryPolicy` — categories are part
  of managing the directory, so there is no separate set.
- **Audit:** `service_category.created`, `.updated`, `.status_changed`.
- **Tests:** `tests/Feature/Admin/ServiceCategoriesTest.php`.

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
  /users/{user}/send-password-reset`) only ever triggers the existing
  reset-link email — it never reads, sets, or displays a password or a
  reset token. The one exception is the Super Administrator override
  below ("Set password").

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

**Timezones**: the app runs on Asia/Manila, and MySQL's session is
pinned to +08:00 (before 2027_01_16 it ran on UTC; that migration moved
stored timestamps by the server's old session offset). The admin form's
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
`javascript:`), `email`, `select` (one of the definition's `options`,
listed in code; a dropdown in the form). Each type owns its validation rules, its
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
| `contact_email` / `contact_phone` / `contact_website_url` | email / string / url | contact | Portal home footer (each hidden while empty) |

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
| Services, Systems/Applications | `visibleTo($user)` — active, and `administrative`-tier only for `access-admin` or `access-hr` users |
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
`requires_access` and `role`. (The dashboard's **My Systems** section was
later removed; the Systems Directory covers it.)

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
launch-URL override, and the
one-query-per-listing eager load.

### Division Chief / Field Director (office designation)
`users.office_designation` (`App\Enums\OfficeDesignation`): **Division
Chief** for a Regional Support Unit, **Field Director** for a field or
satellite office; one active holder per office. Set in the Add/Edit User
form ("Designation", shown once the office is chosen). Because it hands
out admin access, setting or changing it needs `applications.manage_access`
and `UserPolicy::manageSystemAccess` (never on yourself); other profile
edits leave it alone.

The holder is overall admin of the office's systems — the ones it
manages (`applications.organizational_unit_id`) and the ones listed under
it (`application_listings`). `App\Support\SystemAccess\OfficeAdminAccess`
gives each the system's strongest admin role (`superadmin` / `super
admin` before `admin` / `administrator`, case-insensitive; a system with
neither is skipped) through `SystemAccessManager`, so it's audited (with
`via` in metadata) and mirrored to legacy like any grant. The grant
remembers what the designation did (`application_access.via_designation`,
`designation_previous_role_id`): removing the designation, deactivating
the user or moving their office revokes the grants it created and puts
raised roles back; an admin grant someone gave by hand is never touched.
Changing a designation grant's role by hand makes it a regular grant.

Re-synced when the user is created, edited or (de)activated; when a
system is created or edited, its roles change, or an office's listings
change; after `imis:apply-reassignments` (a move clears the designation,
`User::booted`); and on demand with `imis:sync-office-admins`. The Users
list shows the designation under Position; a user's System access card
marks grants "by designation". Tests: `tests/Feature/Admin/OfficeDesignationTest.php`.

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
- **Two looks, as in the legacy app**. Both are styled by classes in
  `app.css` built on the brand tokens.
  - The page-load preloader ports `assets/css/preloader.css` (the
    `.imis-preloader*` classes). It has a dotted navy field, a red top
    strip, the logo on a white disc in a breathing red halo, a red
    divider, bouncing dots, and a red progress bar along the bottom.
  - The sign-in/sign-out splash ports `js/imis_transition.js` (the
    `.imis-tr*` classes), flattened: a plain white card on the muted
    page background (the legacy frosted glass, gradient and floating
    blobs were dropped in the UI review). The CSC seal sits inside two
    counter-rotating rings (navy outer, red inner), and three dots take
    turns lighting up red every 400ms.
- **Boot preloader** (`resources/views/app.blade.php`): shows the CSC-IMIS
  logo with "Civil Service Commission" over "Regional Office VIII", then
  "Loading". As in the legacy `preloader.js`, `app.js` holds it for 300ms
  after the first page renders, then fades it out over 0.6s.
- **Transition splash** (`Composables/useSplash.js` +
  `Components/Feedback/TransitionSplash.vue`, mounted beside the page in
  `app.js` so it survives page swaps):
  - *Signing out*: a confirmation dialog, then "Signing Out — See you
    soon, {name}", held until the login page has rendered underneath.
    Logout now returns to `/login` with "You have been signed out."
  - *Signing in*: the server flashes `signed_in`, and `AppShell` raises
    "Login Successful — Welcome back, {name}" during setup, so it paints
    in the same frame as the new page. It skips the fade-in (`instant`),
    so the page never shows through.
  Both respect `prefers-reduced-motion`.

### About dialog
`Components/Portal/AboutDialog.vue`, opened from the top bar's About
button or the account menu. It revamps the legacy About content (what
IMIS is, mission, what you can do, a "Rebuilt on a modern stack" note)
and keeps the ITG / agency / location / version footer. The version
(shared as `app.version`) is `Changelog::currentVersion()`: the newest
published What's New release, so it always matches the release notes,
falling back to `config('app.version')` (`IMIS_VERSION`, default 2.7.0
like the legacy `includes/config.php`) when nothing is published.

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
- **v3.0.0** (the VILT rebuild, HR, and the later additions) ships as a
  *draft* through migration `2026_11_06_090000_add_v3_release_note`
  (skipped if 3.0.0 exists; rollback removes it only while still a
  draft). Publishing it in Admin → Changelogs shows it to everyone and
  makes 3.0.0 the shown version.
- **v3.1.0** (feedback, deduction notices, single sign-out, TIMS in the
  directory, and the legacy Leave and L&D directory entries removed from
  the Human Resource Division) ships the same way, through migration
  `2027_01_27_090000_add_v3_1_release_note`. Publish it after 3.0.0.
- **v3.2.0** (a fresher look, the redesigned loading screen, optional
  committee start dates, the ICT SRTS employee list) ships the same way,
  through migration `2027_01_28_090000_add_v3_2_release_note`.
- **v3.3.0** (a refreshed sign-in page, saved-account cards, the redesigned
  privacy notice) is inserted already **published** by migration
  `2027_01_29_090000_add_v3_3_release_note`, so it is the version shown as
  soon as it is migrated; 3.2.0 stays a draft until an admin publishes it.
- **v3.4.0** (richer account, leave and record pages, the User Guide, the
  What's New timeline, the dashboard "At a glance" row, division-only leave
  calendars) is inserted the same way, already **published**, by migration
  `2027_01_30_090000_add_v3_4_release_note`.
- **v3.5.0** (security hardening from the audit, focus-trapped dialogs and
  reduced motion, the Recruitment users feed and the ETTS employees feed) is
  inserted the same way, already **published**, by migration
  `2027_01_31_090000_add_v3_5_release_note`.

### User Guide (`/guide`)
- Any signed-in user. The articles are plain data in `config/user-guide.php`
  (categories → articles with steps, tips and a link to the real page);
  `Support/Guide/UserGuide` trims them to what the person may use (an
  article's optional `requires` lists permissions, any one is enough).
  `Portal/Guide.vue` adds search, a contents list that follows the scroll,
  and a print view. Opened from the header Help menu (`HelpMenu.vue`) and
  the footer.

### Leave calendars: whose leave is shown
Everyone sees their own division's leave, on the portal calendar
(`/my/leave/calendar`) and on HR's (`/admin/hr/leave/calendar`, forced to
the viewer's own division). `Support/HR/Leave/CalendarAudience` names the
few who see every office: the head of the Human Resource Division (or
whoever is designated head, `PayslipApprovers`), a Director IV or III by
position, and a Super Administrator.

### Loading states
Placeholders and in-place updates share three pieces: `UI/Skeleton.vue` (a shimmering block built on the `skeleton` utility in `app.css`, still under reduced motion), `UI/BusyRegion.vue` (dims a list and shows a spinner after 150 ms while a search, filter or month change loads) with the `useBusy` composable that feeds it, and the Inertia progress bar, shown for visits slower than 100 ms (`app.js`). The dashboard "At a glance" row and the notification bell use skeletons.

### Dashboard "At a glance"
`Support/Portal/AtAGlance` builds the row (leave balances, leave in
progress, approvals waiting, unread notifications) as a deferred prop of
`PortalController`.

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

### Login lockout and session limits
Carried over from the legacy `auth/login.php` and `includes/session.php`:
- **Lockout**: after `LoginRequest::MAX_ATTEMPTS` (5) failures the
  username+IP pair is locked for `LOCKOUT_SECONDS` (15 minutes), which the
  login page counts down.
- **Idle timeout**: Laravel's `session.lifetime` (120 minutes). The browser
  (`Composables/useSessionTimeout.js`, shown by
  `Components/Feedback/SessionTimeoutDialog.vue` in `AppShell`) tracks real
  input. While there is some, it calls `POST /session/heartbeat`
  (`SessionHeartbeatController`, at most once a minute), and it warns
  `session.idle_warning` minutes before the timeout with "Stay signed in".
  An untouched tab sends nothing, so it does time out, and then it goes to
  `/login?reason=idle`. Activity is shared between tabs through
  localStorage.
- **Absolute limit**: `session.absolute_lifetime` (480 minutes) per
  sign-in. `Support/Security/SessionLimits` stamps the start at login
  (or on the first request after a remember-cookie sign-in), and the
  `EnforceSessionLimits` web middleware signs the user out once it passes:
  a redirect to the login page with a message, or a JSON 401
  (`reason: limit`) for the heartbeat. The dialog gives the same warning,
  with no way to extend.
- **"Remember me"** sign-ins are exempt from both limits (the remember
  cookie would sign them straight back in), and get no warning.
- The limits reach the browser as the `sessionLimits` shared prop, as
  durations, not timestamps, so the client's clock never needs to match.
- **Login history**: `login_histories.ended_by` gains `timeout`, written
  by `EnforceSessionLimits` and by `imis:expire-sessions`
  (`ExpireStaleSessions`, every five minutes via the scheduler, database
  session driver only). That command closes open sign-ins whose session
  row has idled out or gone, with the time it went idle (legacy
  `inc/session_cleanup.php`). Such rows, and legacy rows with a sign-out
  time but no `ended_by`, read as *expired*.
- Not carried over: rotating the session id every 15 minutes. Login
  history and Active Sessions are keyed by session id, and with the
  database driver a rotation can race the heartbeat or a parallel Inertia
  request and sign the user out. The id is still regenerated at sign-in.

Covered by `tests/Feature/Auth/SessionLimitsTest.php` and the lockout
assertion in `UsernameLoginTest`.

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
- **Order**: categories keep the fixed order above. Divisions within a
  category, and systems within a division, are **alphabetical**
  (case-insensitive and natural, so "Field Office 2" comes before "Field
  Office 10"). The admin "Display order" field on units and systems does
  not affect the directory; it still orders other lists.
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
systems), **Access [Maintenance]** for maintenance bypass, **Under
Maintenance**, **No Access** (restricted, no role) and **Not configured
yet**. As in the legacy cards, the button label is the only state cue;
the Details link and explanatory note were removed. A system's
maintenance message still shows in the dashboard's maintenance notice
and on its details page.

### Card styling
Division and system cards keep the legacy layout (icon beside the
details, a 2-line description clamp, an "online" dot on launchable
systems) and their full-width buttons ("View Systems (n)", "Access
System", with their hover styles), but the cards themselves are flat: a
bordered card with no shadow, lift or accent bar. A division card is
itself the control and highlights its border on hover; a system card is
not clickable as a whole, so it has no hover effect of its own. Button colors
are green for launch, orange (`bypass` token) for maintenance bypass,
red for Under Maintenance, and muted grey for No Access / Not configured.
Category headers use the legacy small-caps label with a trailing rule;
committees get the amber (`warning`) tint. Divisions show 4 per row on
wide screens and systems 3. The bypass/hover colors are tokens in
`app.css`.

### Dashboard layout
The dashboard now follows the legacy order: banner → maintenance
notice → announcements → **Systems Directory** → Services → footer
(the help desk contacts from System Settings → Contact, each hidden while
empty, then the legacy copyright and ITG credits; `PortalFooter.vue`).
The Phase 4 Quick Access and Organizational Units sections were
removed (the directory supersedes both; `QuickAccess.vue` was deleted).
The Organizational Units and Applications pages remain in the sidebar.

### Real offices
`RegionalOfficeSeeder` seeds the 15 actual CSC RO VIII offices from the
legacy division map (7 Regional Support Units, 7 field offices, 1
satellite office), matched by code, so it is safe to re-run and keeps admin
edits. It replaces the Phase 4/5 demo units.

### Admin
The system form gains "Also list under these offices / committees"
(`CheckboxGroup.vue`). Lists are synced only when the field is submitted
and audited as `application.listings_changed` (names added/removed).

### Tests
`tests/Feature/Portal/SystemDirectoryTest.php` covers category order, alphabetical divisions and systems, and
grouping, multi-placement without duplication, visibility and inactive
divisions, per-viewer launch state with no restricted-URL leak, the
division query parameter, and admin listing sync, audit and validation.

## Legacy Admin Panel Adaptation — IMPLEMENTED (Phase 19)

The admin area is rebuilt to match the legacy IMIS admin panel (NiceAdmin
look, tables, modals, SweetAlert dialogs, the same menu and pages), on
this stack. It supersedes the separate Create/Edit pages and module-grid
dashboard described in earlier phases.

### Shell, menu, page chrome
- `AppShell` takes `variant="admin"`: a white sidebar, open by default
  (its own remembered preference), with collapsible groups (`SidebarNav`
  admin variant).
- Menu (`Utils/navigation.js`), in legacy order:
  - **Dashboard**
  - **User Management**: Manage Dashboard, Manage Users, Manage Systems,
    System Access, User Access
  - **Portal Content** (new-only modules, no legacy page): Organizational
    Units, Services, Committees
  - **System Administration**, in the legacy order: Announcements,
    Changelog, Active Sessions, Login History, System Access Logs,
    Security Logs, Admin Audit Log. Then the new-only modules: System
    Settings, Backup Database, IMIS Roles, Permissions.
  - **Developer** (Authorization demo, Design system): `localOnly`. It is
    shown only when the shared `app.local` prop is true, and its routes
    are registered only under `APP_ENV=local`, so they 404 everywhere
    else.
- The portal sidebar (new-only; the legacy dashboard had none) is grouped
  under headings, each a labelled `role="group"`:
  - Home on its own.
  - **Updates**: Announcements, What's New.
  - **Directory**: **Systems** (`/applications`, renamed from
    "Applications" to match the dashboard), Services, Organizational
    Units.
  - **Management**: Administration. The group disappears for users
    without admin access.

  The admin-access permission list is exported once as
  `adminAccessPermissions`. The sidebar entry and the account menu both
  use it, mirroring `AppServiceProvider::ADMIN_ACCESS_PERMISSIONS`.
- Both sidebars end with a small footer showing "IMIS v{version}" and
  "CSC Regional Office VIII".
- `PageHeader` shows a bold navy title with the breadcrumb underneath
  (legacy `.pagetitle`).

### Shared components
- `UI/DataTable` — legacy DataTables: show N entries, search, Copy / CSV
  (with UTF-8 BOM) / Print, sortable headers, # column, "Showing x to y
  of z", pagination. *Client* mode for ordinary lists. *Server* mode
  (Laravel paginator + `only` partial reloads) for large logs, backed by
  `Support/Tables/ServerTable` (whitelisted sorting, LIKE-escaped search)
  and `Support/Tables/CsvExport` (streams every matching row and
  neutralizes formula cells against CSV injection).
- `Feedback/Modal` — colored header (`tone`), `summary` strip, scrolling
  body, pinned footer, `xl` size.
- `Composables/useDialog` + `Feedback/DialogHost` — promise-based
  `confirm()`, `alert()`, and toasts (the SweetAlert2 replacement), mounted
  once in `app.js`. Server `flash.status` / `flash.error` become toasts
  automatically.
- `UI/Tabs` (WAI-ARIA tabs), `UI/AdminCard` (since merged into `UI/Card`), `UI/ActionButton`,
  `UI/StatusBadge`, `UI/UserAvatar`, `Forms/PhotoPicker`,
  `Forms/CheckboxGroup`, `Admin/SchemaForm` + `Admin/RecordModal`
  (schema-driven add/edit modal for simple records), `Admin/MetricCard`,
  `Charts/SimpleChart`.
- **Statuses are Title Case** (2026-10-04): "Returned for Correction",
  "Head of Office", "In Service". Minor words stay lowercase unless they
  come first or last, and acronyms stay as written.
  - Status labels are Title Case at the source: the status enums'
    `label()`, fallbacks such as "Not Set", and the JS status maps.
  - `UI/StatusBadge` also passes any label through `Utils/text.js`
    `titleCase()`, replacing CSS `capitalize`, which capitalized every
    word ("Returned For Correction").
  - Field placeholders such as "Not set" in dropdowns aren't statuses
    and stay sentence case.
- Admin actions now redirect `back()` with a flash message, so modals stay
  on their page.

### Users (legacy profile)
- `users` gains `first_name`, `middle_name`, `last_name`, `sex`, `birthday`,
  `position`, `organizational_unit_id` (office) and `profile_photo_path`.
- `name` stays the stored display name, kept in sync as "First M. Last"
  (`User::composeName`).
- Usernames follow the exact legacy rule from the name parts
  (`Usernames::generateFromParts`).
- Photos are re-encoded by GD (`Support/ProfilePhotos`): a centered
  400×400 JPEG, no EXIF, old file removed.
- Manage Users: one table with the legacy Add modal (green) and the Edit
  modal (blue) with Profile / Account / Security tabs. Per-row abilities
  come from the server. Admins never see passwords, and the legacy
  birthday password is not carried over.
- **Set password (Super Administrator override, 2026-10-05):** Edit →
  Security, for when a reset link can't be used. `PUT
  /users/{user}/password` (`UserController::setPassword`,
  `SetUserPasswordRequest`, `UserPolicy::setPassword`, which only
  Gate::before lets through; never on one's own account). The admin
  confirms with their own password; the new one follows the password
  policy. "Must change at next sign-in" (on by default) sets
  `users.must_change_password`, which `EnsurePasswordIsCurrent` treats
  like an expired password (`SecurityPolicy::mustChangePassword`); any
  password change by the owner clears it (User `saving` hook). The
  user's database sessions and remember token are cleared. Audited as
  `user.password_set` (never the password); the legacy side gets the
  hash through the usual LegacyBridge sync.

### Systems and access
- **Manage Systems**: one extra-large modal with Details, Dashboard
  listing, Roles (the legacy inline role editor) and Maintenance tabs.
- **System Access** (legacy "Roles and Permissions"): every user × one
  system. **User Access**: one user × every system. Both use the shared
  `AccessGridModal` (search, filter, apply-to-visible, pending count,
  undo). The grid loads on demand (`Inertia::optional`, `?system=` /
  `?user=`), is built by `Support/SystemAccess/AccessMatrix` (rows marked
  `locked`: self / protected / inactive), and is saved in bulk:
  `SyncApplicationAccessRequest` validates every row (role belongs to the
  system and is active, self and protected-target guards, inactive
  accounts can only lose access), all or nothing.
- The old per-system Access page is removed; its single-grant endpoints
  remain.
- **Manage Dashboard**: places systems under each office or committee
  card (`application_listings` from the division side). Managed systems
  are shown locked.

### Logs and sessions
- `login_histories`: written after the session id regenerates, closed on
  sign-out (with a fallback if the id rotated), and marked `ended_by=admin`
  on a forced sign-out.
- `system_access_logs`: written by the new launch route
  `GET /applications/{id}/launch`. That route resolves the URL
  server-side through `launchUrlFor()`, logs the role held at that
  moment, and redirects. It is not an open redirect. Portal launch
  buttons use it.
- Pages:
  - **Login History**, **System Access Logs**, and **Security Logs**
    (failed, blocked and locked-out sign-ins plus forced sign-outs, with
    a top-failing-IPs panel): server tables with date filters and CSV.
  - **Active Sessions** (database driver): force logout deletes the
    session and rotates the remember token. Session ids never leave the
    server (only a SHA-256 is sent). You can't end your own session, and
    only a Super Administrator can end another Super Administrator's.
  - **Admin Audit Log**: restyled into the same table.
- Permissions: `audit_logs.view` (logs), `sessions.view`, `sessions.revoke`.

### Admin dashboard
The legacy admin home (`Support/Admin/AdminDashboard`, permission-scoped,
real counts only):
- welcome banner with role badge, date, clock and quick actions
- metric cards (users, systems, access grants, logins today and active
  sessions)
- a 14-day login trend
- people per IMIS role, users by office, access by system
- top systems, active users without any system access
- recent admin activity, plus the existing inactive-record counts

It shows system administration only (`Components/Admin/SystemDashboard.vue`).

### Two management areas: Administration and Human Resources
Since 2026-10-03, system administration and Human Resources are separate
areas, each with its own dashboard and sidebar. HR staff aren't system
administrators, and system administrators don't need HR's personal data.
- **Gates:** `access-admin` (`ADMIN_ACCESS_PERMISSIONS`, the system
  permissions) opens `/admin`. `access-hr` (`HR_ACCESS_PERMISSIONS`: the
  employee, PDS, 201, L&D, plantilla, service record and leave
  permissions) opens `/admin/hr`. The JS mirrors are
  `adminAccessPermissions` and `hrAccessPermissions`. Either gate shows
  `administrative`-visibility directory entries
  (`HasPortalVisibility::seesAdministrative`), as before the split.
- **Entry points:** the portal's Management group and the account menu
  list **HR Management** and **Administration**, each only with its gate.
- **Sidebar:** `AdminLayout` picks `hrNavItems` for any `/admin/hr` URL and
  `adminNavItems` otherwise. HR URLs stayed under `/admin/hr`. People with
  both areas (e.g. Super Administrator) get an Admin | HR switcher at the
  top of the sidebar (AppShell's `sidebar-header` slot). HR breadcrumbs
  start at **Human Resources** (`/admin/hr`), not `/admin`.
- **Banner:** both dashboards share `Components/Admin/DashboardBanner.vue`.

### HR dashboard
`/admin/hr` (`Admin\HrDashboardController`, `Pages/Admin/HR/Dashboard.vue`)
is open to anyone with `access-hr`. The analytics
(`App\Support\HR\HrDashboard`, the lazy `hr` prop,
`Components/Admin/HrAnalytics.vue`) need `employees.view` (HR Officer, Top
Management). Without it, `hr` is null and the page points to the sidebar.
The analytics count staff **in service** (not separated, active account):
- headcount; by sex (`Components/Charts/SplitBar.vue`), age band and
  employment status; length of service (original appointment date, else
  the earliest complete plantilla period)
- birthdays in the next 30 days (29 Feb → 28 Feb in common years)
- retirement within 12 months: optional at 60, compulsory at 65 (RA 8291),
  and anyone past 65 still in service; how many are eligible now. Those
  already 60–64 are listed too ("Eligible (60+)", last, dated at their
  65th birthday), since they may retire at any time (user, 2026-10-04)
- CSC loyalty-award milestones this year (10 years, then every 5)
- what's waiting on HR (PDS to verify, leave in review, L&D to review,
  incomplete plantilla links), each only with its permission
- records to complete (no employment status, appointment date, birthday
  or sex)
- with `plantilla.view`: fill rate; with `leave.view`: on leave today, the
  next 14 days, recent applications; with `ld.view`: share trained this
  year, trainings and hours

Viewing it is audited (`employee.hr_dashboard_viewed`) once a day per
viewer. Chart colors are tokens in `app.css` (`--color-chart-1`, `-2`,
`-neutral`; the pair validated for CVD and contrast), applied through
class names (`bg-chart-1` …) so Tailwind emits them.
`tests/Feature/HR/HrDashboardTest.php`.

Charts use **Chart.js 4** (tree-shaken: bar and line only) through
`Charts/SimpleChart`, following the dataviz method:
- a single series in the validated `--color-csc-blue-light` (brand navy
  fails the lightness band for marks)
- thin marks with rounded ends and hairline grids
- no legend for a single series, hover tooltips, and a "View as table"
  for every chart
- the legacy roles donut became a bar, because roles overlap and don't
  form one whole

### Other pages
- Offices, services, committees: tables with `RecordModal` add/edit.
  Committee members are still managed on the committee page.
- IMIS Roles: `RoleModal` with Details and Permissions tabs (grouped
  picker with select-all per group). The super-admin role's name and
  status are locked.
- Permissions: a read-only table.
- Announcements and Changelog: the legacy layout, with a sticky side form
  (create, or edit the chosen row; `?edit=ID` deep-link for
  announcements) beside the table. Publishing stays on the announcement
  preview page; changelog rows publish and unpublish in place.

### Backup Database
`Support/Backup/DatabaseDumper` writes a restorable SQL dump in pure PHP
(no `mysqldump`). It covers only the application's own database (MySQL
schema-scoped), streams in chunks, exports session, cache, queue and
reset-token tables as structure only, and blanks remember tokens.
`BackupController`:
- **Super Administrators only**, even with `backups.create`
- requires the current password again
- throttled to 5 per 10 minutes
- audited as `system.backup_downloaded`

A restore round-trip into a scratch MySQL database matched all 29 tables
exactly.

### Tests
Covered by `UserProfileTest`, `ApplicationAccessTest` (System/User Access
grids and bulk save), `DashboardLayoutTest`, `ActivityLogsTest` (history,
launches, CSV escaping, sessions, force logout), `BackupTest`, and the
additions to `AdminDashboardTest`. Earlier tests were updated where pages
became modals (generic redirect plus flash assertions).

## Demo data removal — IMPLEMENTED

The database now seeds only real reference data: `RolePermissionSeeder`,
`SettingSeeder` and `RegionalOfficeSeeder`. `PortalDemoSeeder` (demo
services and categories, plus six placeholder systems) and the `testuser`
Super Administrator account are gone. Systems and accounts come from the
legacy import below.

Migration `2026_10_13_090000_remove_demo_data` purges what earlier seeds
left in existing databases: the "(Demo)" organizational units (and their
directory listings), the two "(Demo)" services and categories, and the
"Demonstration directory entry" description on the systems. It matches
only those exact labels, so admin-created or renamed records are kept;
`down()` does not restore them.

## Legacy Data Import — IMPLEMENTED

`php artisan imis:import-legacy ../old-imis/db_imis.sql` carries the
legacy production database into this platform
(`App\Console\Commands\ImportLegacyImis` → `App\Support\Legacy\LegacyImporter`).
The command loads the phpMyAdmin dump into a temporary
`<database>_legacy_import` schema on the same server and drops it
afterwards. With `--database=<schema>` it reads legacy tables that are
already loaded instead (e.g. on a host without CREATE DATABASE). It runs
in one transaction, apart from photo files.

| Legacy | Here |
|---|---|
| `imis_dash_divisions` (RSU category) | existing offices, matched by the code in parentheses, then by name; an empty description is filled |
| `imis_dash_divisions` (committee category) | `committees` |
| `imis_projects` | `applications`, matched by `short_name` = `code_name`. `requires_access` = not `is_public`; `url` = `guest_url`; Bootstrap icon → nearest `Icon.vue` name. Systems built into IMIS (`LegacyImporter::RETIRED_SYSTEMS`: PRISM) are skipped, with their roles and grants |
| `imis_dash_division_systems` | first active office = managing unit; other active placements → `application_listings`; `is_maintenance` → maintenance mode. Inactive placements are skipped |
| `imis_access_roles` | `application_roles` (`launch_url` = `project_url`). Roles the legacy system lacks are deactivated if nobody holds them |
| `users_cscro8` | `users`, matched by username. New accounts keep their bcrypt hash, so legacy passwords work; `fo_rsu` → office by name; `superadmin` → `super-admin` (legacy `admin` had no admin-panel rights) |
| `imis_system_access` | `application_access` (`granted_by`, granted time) |
| `imis_announcements` | `announcements`: active → published, otherwise archived; `critical` → featured |
| `imis_changelog(_items)` | `changelogs` / `changelog_items` |
| `imis_history_login_logs` | `login_histories`. Legacy session ids are dropped; a timed-out session reads as expired |
| `imis_history_access_logs` | `system_access_logs`, with IP and user agent from the sign-in row |
| `admin/uploads/*` | profile photos, re-encoded through `ProfilePhotos` |

Rules:
- **Times**: legacy DATETIME columns are Asia/Manila wall-clock time,
  kept as Manila time (the app's time zone). TIMESTAMP columns are read
  as UTC and converted.
- **Relative launch paths** are made absolute against `--legacy-url`
  (default `https://imis.cscro8.com`), because launch URLs must be
  absolute.
- **Shared emails**: the account with the latest sign-in keeps the
  address. The other is imported with `email` NULL (now nullable, still
  unique) and can sign in by username until an admin adds one. An email
  already held by an account here is never taken over.
- **Existing accounts** matched by username keep their password, status
  and roles; only empty profile fields are filled.
- **Re-running** is safe: users, systems, roles, grants, releases,
  announcements and history rows are matched, so nothing is duplicated.
  Legacy values are re-applied to the systems the legacy data defines.
- **Not carried over**: admin and security audit logs (their action names
  don't map to `AuditEvent`), rate limits, SSO tokens, recent systems
  (derived from launches here), and the legacy columns `first_day`,
  `exam_fo_id`, `itg_id`, `type` and `must_change_password`.

Covered by `tests/Feature/LegacyImportTest.php` (a fictional legacy DB on
a second SQLite connection).

The import also records each account's legacy id (`users.legacy_id`), and
runs with the legacy bridge paused, so nothing it reads is written back.

## Legacy Bridge & Single Sign-On — IMPLEMENTED

The old PHP subsystems keep running until they are rebuilt. They read
people and roles from the legacy database, keyed by `users_cscro8.id`.
They also accept a sign-in from a Laravel hub through `?sso_token=`
(legacy `includes/session.php`). This platform is now that hub, and the
source of truth for accounts and system access. Configuration lives in
`config/legacy.php`; server steps are in
[`deploy/legacy-imis/README.md`](../deploy/legacy-imis/README.md).

### Write-through to the legacy database (`Support/Legacy/LegacyBridge`)
- Switched on by `LEGACY_BRIDGE=true`, over the `legacy` connection
  (`LEGACY_DB_*`; not strict, `+08:00`). All legacy database access goes
  through this class, so where that database lives is one setting.
- `sync(User)` mirrors one whole account, so it is idempotent:
  - **`users_cscro8`:** name parts, initial, sex, username, password
    hash (bcrypt, which `password_verify()` accepts), email, position,
    office (`fo_rsu`), birthday, status, and role. Super Administrator
    becomes `superadmin`; a legacy `admin` is kept but never granted.
  - **Matching:** by `legacy_id`, else once by username; otherwise a new
    row is inserted with the legacy defaults and `type` = office code.
  - **Fields kept from the legacy row:** whatever IMIS leaves empty, such
    as a shared email that the import left NULL here.
  - **Access:** `imis_system_access` mirrors the account's grants for
    every system both sides know (`short_name` = `code_name`). Roles
    added here are created in `imis_access_roles`. Legacy grants to
    systems this platform doesn't have are never touched.
- **When it runs:**
  - after the response (`defer`, once per account per request), on any
    `ApplicationAccess` save or delete
  - on a `User` save that changes a mirrored field
  - on an IMIS role change (`UserController::assignRoles`, since pivots
    fire no events)

  A failure is reported and never fails the admin action.
- `imis:sync-legacy [--user=]` reconciles everything. It runs hourly on
  the scheduler, and once by hand when the bridge is first switched on.
- `LegacyBridge::withoutSyncing()` pauses it (used by the import).

### Single sign-on (`Support/Legacy/LegacySso`)
- **Issuing:** `ApplicationLaunchController` passes the resolved launch
  URL through `launchUrl()`. A URL whose host is listed in
  `LEGACY_SSO_HOSTS` gets `sso_token=<64 random chars>`; no other host
  ever gets a token. With the bridge on, an account with no legacy id is
  synced first; with it off, such an account just launches without one.
- **Storage:** `sso_tokens` keeps only a SHA-256 hash, the account, the
  system, and the *audience* (the launch URL's first path segment, e.g.
  `otrs`). Tokens are valid for `LEGACY_SSO_TTL` seconds (60) and are
  claimed atomically, once. They are pruned daily, a day after expiry.
- **Redeeming:** `POST /api/v1/sso/validate` (`routes/api.php`, the
  stateless `api` group with no session, cookies or CSRF, at 300/min).
  It takes `token` and an optional `path`.
  - The token must exist, be unused and unexpired.
  - When `path` is given, or `LEGACY_SSO_REQUIRE_PATH=true`, its first
    segment must match the audience.
  - The account must still be active.
  - Success returns 200 with the legacy payload: `id` (the legacy id),
    `username`, `fname`, `lname`, `mname`, `minitial`, `global_role`,
    `email` and `position`. Anything else returns 401.
- **Patching the legacy side:** `deploy/legacy-imis/session-sso.patch`
  changes `includes/session.php` on the server:
  - the validate URL comes from config, not the `Host` header
  - TLS verification is on, with timeouts
  - the page path is sent along
  - the session starts clean, and does the CTS / RFCS / ICTSRTS / OTRS
    setup that `api/system-redirect.php` used to do
  - the token is always stripped from the URL

  Set `LEGACY_SSO_REQUIRE_PATH=true` once the patch is live.

#### Laravel systems under IMIS (e.g. PROCTAD)

IMIS is the hub: new systems are separate Laravel apps with their own
database and deployment, listed here as an `Application`. The same token
mechanism signs people in to them (the earlier "no SSO" notes in Phases
4 and 18 predate this).

- **Registering:** list the system's `host[:port]` in `SSO_HUB_HOSTS`
  (`legacy.sso.hub_hosts`). A launch to such a host gets `sso_token=`,
  with no legacy id needed; tokens are never added to other hosts.
- **Audience:** the token is bound to `host:<host[:port]>`. The system
  redeems it at `POST /api/v1/sso/validate` with `token` and its own
  `audience`; a wrong audience, a legacy-style call (no audience), or a
  legacy token redeemed with an audience all return 401.
- **Response:** `id`, `username`, `fname`, `lname`, `mname`, `email`,
  `position`, `role` (the person's `ApplicationRole` name in that system,
  null if none), `role_code` (that role's stable `code`) and
  `office_name`, `itg_member` (active member today of the committee whose short name is `ictsrts.itg_committee`), `office_code` / `office_type` (their organizational unit, or the
  nearest ancestor that is a regional support unit, field office or
  satellite office), `headed_offices` (the field and satellite offices they
  head today by designation, apart from their own, each `{code, name, type}`;
  empty for most people — for a system that scopes by office and lets them
  work as each in turn) and `photo_url` (the public URL of their header
  photo, or null). The system stores the URL as the account's avatar, and
  must allow the IMIS origin in its `img-src` (PROCTAD and the Recruitment
  Portal: `IMIS_ASSET_ORIGIN`).
- **Role codes:** `application_roles.code` is an optional, per-system
  unique machine key (`[a-z0-9_]`), edited next to the role in the
  system's modal. A system maps IMIS roles onto its own by code, so
  renaming a role's display name breaks nothing.
- **The system's side:** it keeps no staff credentials. PROCTAD's
  `GET /sso` (`Auth\ImisSsoController`, `IMIS_SSO_URL`) links the person
  by `users.imis_user_id` (else once by username/email to an unlinked staff
  account, never to a member), creates the account on first sign-in, and
  rewrites its name, role (`role_code` = a PROCTAD `UserRole` value) and
  field office on every sign-in. Offices are mapped through
  `field_offices.imis_code` (IMIS unit code) and, for regional units, the
  regional office. Staff cannot use a PROCTAD password or Google sign-in
  (a Super Administrator keeps the password as break-glass); members
  and external users stay in PROCTAD with Google.
- **Recruitment Portal** (`../recruitment-system`, the "Online Recruitment
  System" entry): the same mechanism, for HR staff and HRMPSB board
  members only. Its `ImisSsoController` (`POST /api/sso`, after the
  `/sso` splash page) takes `role_code` `admin` or `hrmpsb`; applicants
  are external and keep their own sign-in and Google. It links by
  `users.imis_user_id` (else once by email, never to an applicant) and
  issues its own Sanctum token. Board designations (chairperson,
  secretariat, …) stay in its own HRMPSB composition.
- **Training Information Management System** (`../csc-tims`, the "Online
  Training Registration System" entry): the same mechanism, for staff
  only. Its `Auth\ImisSsoController` takes a staff `role_code`
  (`admin`, `management`, `field-office`, `collecting-officer`,
  `superadmin`); participants are external and keep their own sign-in
  and Google. It links by `users.imis_user_id` (else once by email,
  never to a participant), maps field offices through
  `field_offices.imis_code`, and copies the IMIS photo once into its own
  private storage (only from `IMIS_ASSET_ORIGIN`), as it does a Google
  photo.
- **JPortal** (`../jportal`, the "CSC RO VIII Job Portal" entry under
  PALD): the Bulletin of Vacant Positions, replacing the legacy
  `jportal/pald` panel. Staff only, and only through IMIS: there is no
  other sign-in. Its `Auth\ImisSsoController` (`/sso`) takes `role_code`
  `admin` or `encoder` (PALD staff only), links by `users.imis_user_id`,
  and keeps the IMIS photo URL. The Admin role must have no launch URL of
  its own (it used to point at the legacy panel, and a role's launch URL
  wins over the system's). It also serves the public listing on its own
  host and `/api/public/*` for the cscro8.com website. Cutover steps are
  in its `CUTOVER_RUNBOOK.md`.
- **GAD Corner** (`../gad-corner-v2`, the "Gender and Development Corner"
  entry, `GAD-CORNER`): the admin for the public GAD Corner's articles,
  hero carousel and knowledgebase, replacing the legacy `imis/gad-corner`
  panel. Staff only, and only through IMIS. Its `Auth\ImisSsoController`
  (`/sso`) takes `role_code` `admin` or `encoder`, links by
  `users.imis_user_id`, and keeps the IMIS photo URL. Its roles must have no
  launch URL of their own (the legacy rows pointed at the old panel).
  It also serves the public GAD Corner site on its own host (laid out per
  PCW MC No. 2025-05 Annex B, flat purple) and `/api/public/*` for cscro8.com;
  `/api/imis/summary` is its Executive Dashboard feed (set up like
  JPortal's). Content comes over with `php artisan gad:import-legacy`.
  Locally it runs on port 8050 (8040 is ERIS).
- **PSED** (`../new-psed`, the "Primary System of Electronic Based Documents"
  entry): the Policies and Systems Evaluation Division's archives, replacing
  the legacy `imis/psed` subsystem (`../psed`, reference only). Staff only,
  and only through IMIS: its `Auth\ImisSsoController` (`/sso`) takes
  `role_code` `admin` or `user` (the PSED application roles' `code`; their
  legacy launch URLs must be cleared), links by `users.imis_user_id`. It
  started from the ERIS shell; Phase 1 (sign-in, sidebar and dashboard laid out
  like the legacy sidebar, placeholders for every page) is done and no module
  is ported yet — see its `docs/migration-plan.md`. Locally it runs on port
  8090 (8080 is ETTS).
- **ROOMS** (`../new-rooms`, the "Regional Office Orders and Memoranda Management System"
  entry): the regional office's archive of issuances (Regional Office Orders
  and Memoranda, and policies on recruitment and promotion, AWA, attendance
  and leave, training, and welfare fund and employees' benefits), replacing
  the legacy `imis/rooms` subsystem (`../rooms`, reference only). Staff only,
  and only through IMIS: its `Auth\ImisSsoController` (`/sso`) takes
  `role_code` `admin` or `user` (the ROOMS application roles' `code`, from
  the legacy `system_access.rooms` column; their legacy launch URLs must be
  cleared), links by `users.imis_user_id`. Everyone reads and searches;
  only `admin` adds, edits and deletes (PDFs on a private disk, served
  through the app; deletes are soft and purged after 30 days by
  `rooms:purge-deleted`). Started from the PSED shell. Data comes over with
  `php artisan rooms:import-legacy` (reads `rood_orders`; the legacy
  `uploads/` folder is read through `ROOMS_LEGACY_ROOT` until copied).
  Locally it runs on port 8100 (8090 is PSED).
- **ICT SRTS** (ICT Service Request Ticketing System): reads the current
  employees server to server at `GET /api/v1/ictsrts/employees`, guarded by
  a bearer token (`ICTSRTS_API_TOKEN`, compared in constant time; the
  endpoint is off while it is unset; throttled to 60 a minute). Active
  accounts only, each with `office_name` / `office_code` (as in the SSO
  payload) and `in_itg`: whether they are an active member today of the
  committee whose short name is `ICTSRTS_ITG_COMMITTEE` (default `ITG`),
  which the super-admin manages as an ordinary committee. Committee members'
  `effective_from` is optional.
- **ETTS** (Examination and Transaction Tracking System): reads the people
  directory server to server at `GET /api/v1/etts/employees`, guarded by a
  bearer token (`ETTS_API_TOKEN`, compared in constant time; the endpoint is
  off while it is unset; throttled to 60 a minute). Active accounts only:
  `name`, `position`, `office_name` and `office_code` (as in the SSO payload),
  nothing else (no e-mail or username). ETTS uses it to suggest an addressee
  or signatory on a routing slip; typing a name by hand always still works.
- **Launch screen:** `ApplicationLaunchController` renders
  `Portal/Applications/Launching` ("Opening <system>" over the IMIS logo)
  before sending the browser on; `app.blade.php` draws the same screen
  at first paint instead of the navy boot preloader, so there is no
  second flash.
- **Not done yet:** ending a session in a system immediately when only
  that system's access is revoked in IMIS (the next sign-in fails). Signing
  out of IMIS, and deactivating the account, do end it (below).

#### Single sign-out (`Support/Legacy/SsoLogout`)

When an IMIS session ends, each Laravel system opened from it ends its own
session. Signing out of a system does not sign the person out of IMIS or
of other systems. The contract for system owners is
[`sso-logout.md`](sso-logout.md).

- **Opening:** a hub token remembers the IMIS session that issued it
  (`sso_tokens.imis_session_id`; legacy tokens don't). Redeeming it opens
  an `sso_sessions` row (account, system, IMIS session id, `sid`
  encrypted with `APP_KEY`, the system's logout address) and the redeem
  response carries `sid`: 64 random characters the system keeps with its
  own session. It is `null` when the token wasn't issued from a session.
- **Logout address:** the launch URL's origin plus `legacy.sso.logout_path`
  (`SSO_LOGOUT_PATH`, default `sso/logout`). No per-system setting.
- **Telling the system:** `SsoLogout::signOut($imisSessionId)` POSTs JSON
  `{sid}` to every open `sso_sessions` row for that IMIS session, in
  parallel, without following redirects (2 s to connect, 3 s in all), then
  marks them ended whatever the answer. Best effort: a system that is down
  or answers an error never blocks the person signing out of IMIS, and
  its session ends by its own idle limit. The `sid` is the only
  credential.
- **When it runs:** from `LoginHistoryRecorder`, so every way an IMIS
  session ends is covered: sign-out and the absolute 8-hour cap
  (`close()`), and an administrator's force-end and account deactivation
  (`forceEnd()`, called by `AccountLifecycle::ended()`). The idle timeout
  is caught by `imis:expire-sessions`, so within about 5 minutes.
- **Pruning:** the daily `prune-sso-tokens` task (`LegacySso::prune()`)
  also deletes `sso_sessions` ended over a day ago or opened over 30 days
  ago (IMIS stops tracking a session after 30 days).
- **In the systems** (each commits its own migration; run `migrate`):
  - **JPortal, PROCTAD and TIMS:** `imis_sessions` maps `sha256(sid)` to the
    Laravel session id, written after `Auth::login`. `POST /sso/logout`
    (CSRF-exempt; PROCTAD and TIMS also exempt it from maintenance mode)
    destroys that session through the session handler. A daily task
    deletes mappings over 30 days old.
  - **Recruitment Portal:** it has Sanctum tokens, not sessions, so
    `personal_access_tokens.imis_sid_hash` holds `sha256(sid)` and
    `POST /sso/logout` deletes that token; the SPA's next call gets a 401.
    Its 2-hour token expiry needs no prune.
- **Not done yet:** the legacy PHP systems (`LEGACY_SSO_HOSTS`), which
  get no `sid`. Until they have a logout endpoint they rely on their own
  idle limit; a legacy system's `includes/session.php` should keep it
  short.

The legacy `api/*` endpoints other than `system-redirect.php`
(user-access, maintenance-status, track-access, announcements and
changelog) served only the old dashboard, and are already covered here.

Covered by `tests/Feature/Legacy/LegacyBridgeTest.php`,
`tests/Feature/Legacy/LegacySsoTest.php` and
`tests/Feature/Legacy/SsoLogoutTest.php` (single sign-out).

## Backend / Frontend Boundary — IMPLEMENTED

- Controllers return `Inertia::render('PageName', [...])`; they never
  return raw JSON for page navigation. A future JSON API (if needed for
  external integrations) would be a distinct, explicitly versioned layer.
- Validation, authorization, and business logic belong in the backend
  (`app/Http/Requests`, policies, and services as they're introduced) —
  Vue components render data and dispatch Inertia requests, nothing more.
- No server secrets are passed to the frontend; only explicit, whitelisted
  props defined per page.

## Human Resources: Employee Core — IMPLEMENTED (HR-1)

The first of the HR phases. They replace the legacy PRISM (blank), ldms
(Learning & Development) and LMS (Leave) subsystems with one built-in
module:

| Phase | Name |
|---|---|
| HR-1 | Employee core |
| HR-1b | Reassignment history |
| HR-2 | 201 File / PDS |
| HR-3 | Learning & Development |
| HR-4 | Plantilla |
| HR-5 | Service Record |
| HR-6 | Leave |
| HR-7 | Data migration and cutover |

HR-1 lays the record the rest hang off.

### Years in the CSC (loyalty awards) — IMPLEMENTED
Loyalty awards (10 years, then every 5) count service in the CSC only.
- **Dates:** `employees.original_appointment_date` is when government
  service began. `employees.csc_entry_date` is set only for those who came
  from another agency; when it's empty, CSC service runs from the
  original appointment.
- **Calculation:** `App\Support\HR\LoyaltyAwards` gives years and months
  in the CSC (up to separation for those who left), the last award
  earned, the next one and its date, and any milestone completed this
  calendar year.
- **Where it shows:** the employee record ("Years in the CSC"), the
  Employees list (a "Years in CSC" column with a badge in an award year),
  and the HR dashboard's loyalty list.
- **Not handled:** leave without pay and breaks in service aren't
  deducted.

### HR-1b: Reassignment history — IMPLEMENTED
Every employee has a history of movements between Regional Support Units
and Field Offices (`employee_assignments`, `App\Models\EmployeeAssignment`,
soft deletes).
- **One entry per movement:**
  - kind (`App\Enums\AssignmentKind`): reassignment, detail, designation,
    or return to mother unit
  - from office (defaults to their current office) and to office
  - effective date; an end date for details and designations only
  - Office/Special Order number and date, and remarks
  - an optional scanned order
- **The office rule (agreed with HR, 2026-10-03): only a reassignment
  moves the account's office.** A detail or a designation leaves the
  person in their mother unit; a return to the mother unit ends any
  detail still running on its date.
  - **Timing:** a reassignment dated today or earlier moves the office
    at once. A future-dated one moves it on its date, via
    `imis:apply-reassignments`, scheduled daily at 00:10.
  - **Once only:** each reassignment moves the office once
    (`applied_at`), so an office edited by hand afterwards isn't undone.
  - **Corrections:** correcting or removing a reassignment re-settles the
    office from the latest reassignment in effect. Removing the one that
    moved them puts them back where it moved them from.
  - **Other kinds** never touch the office.
  - **Writer:** `App\Support\HR\EmployeeAssignments` is the only writer.
    A move goes through the user model, so the legacy bridge mirrors it.
- **Scanned orders** are filed in the 201 file as an Office Order
  (`EmployeeDocuments`) and linked from the entry (`document_id`), so they
  follow the 201 file's access and audit rules. A link to the scan is
  only sent to viewers who may open that document (not supervisors).
- **Views:**
  - **Employee record page:** an "Assignment history" card
    (`Components/HR/AssignmentTimeline.vue`), newest first, with today's
    office (mother unit), current details and designations, and
    current/upcoming badges. A reassignment's end is derived from the
    next reassignment.
  - **Recording:** HR records and corrects entries with
    `AssignmentModal.vue` (POST + `_method=PUT`, so a scan can be added
    to a correction), and removes them after a confirmation.
  - **My Employee Record:** shows the same timeline, read-only.
- **Reports** (Admin → Human Resources → **Reassignments**,
  `/admin/hr/reassignments`, `ReassignmentReportController`):
  - **Stations as of a date:** each in-service person's office per the
    history, and any detail running that day
    (`EmployeeAssignments::stationsOn()`).
  - **Movements in a period:** filtered by kind and office (from or to).
  - Both are DataTables with CSV and print, and limited to the records
    the viewer may see (`Employee::visibleTo`): everyone for HR, their
    own office for supervisors.
- **Access:**
  - `employees.reassign` (`EmployeePolicy::reassign`) records, corrects
    and removes entries, never on your own record. Scoped bindings
    resolve an entry only under its own employee.
  - Reading follows `EmployeePolicy::view`.
  - Audit events: `employee_assignment.recorded`, `.updated` (changed
    fields) and `.deleted`. Each records any office move in its metadata;
    a scan also records `employee_document.uploaded`. The morph map gains
    `employee_assignment`.
- **Feeds HR-5:** `stationsOn()` is the Service Record's station/place
  of assignment.
- **Tests:** `tests/Feature/HR/ReassignmentTest.php`.

#### Head-of-office designations (concurrent capacity)
Added 2026-10-04 for a Field Director who heads a second field office
because there's no Director II item for it. They keep their own item and
office. The second office is a **designation** marked `as_head`, usually
open-ended (no end date = until further notice). It is never a second
appointment or plantilla item: that would count a filled item that
doesn't exist, add a period to the Service Record, and hide the missing
item from the staffing plan.
- **Data** (`2026_11_01_090000_add_head_of_office_designations`):
  `employee_assignments.as_head`, `hand_over_supervision` /
  `supervision_handed_at`, `restore_supervision` /
  `supervision_restored_at`; `supervisor_handovers` (assignment,
  employee, previous supervisor, `restored_at`). `as_head` and the two
  supervision flags are forced off for anything but a designation.
- **Capacity** (`2026_11_03_090000_add_head_capacity_to_employee_assignments`,
  `App\Enums\HeadCapacity`): a head designation is **Officer-in-Charge**
  (`oic`) or **Caretaker** (`caretaker`), as the order says. It defaults to
  OIC and is null when not heading. Existing head designations were set
  to OIC.
  - Both head the office the same way: access, handover, the Staffing
    page, and payroll's designated head of HRD (HR-8, which checks
    `as_head`).
  - The capacity shows in the dialog's "Capacity" choice, the timeline
    badge, "OIC of ESFO" / "Caretaker of ESFO" beside the person's office,
    the Staffing page ("As" column; "Name (Caretaker)" on the office row),
    and the audit label ("Designation as Caretaker of …").
- **Access:** `Employee::officesSupervisedBy()` is the viewer's own
  office plus any office they head by designation today
  (`EmployeeAssignment::officesHeadedBy()`, scope `headingOn()`). It
  needs `employees.view_unit` (the Supervisor role). Both
  `Employee::scopeVisibleTo()` and `EmployeePolicy::view()` use it, so the
  access ends by itself on the day after the end date. PDS, 201 files and
  service records stay out of reach, as for any supervisor.
- **Supervisor handover** (optional, on by default in the dialog): once
  the designation is in effect, the office's staff in service with an
  active account (not the designee) get the designee as immediate
  supervisor. Each previous supervisor is kept in
  `supervisor_handovers`.
- **Give-back** happens when the designation ends (if
  `restore_supervision`), is removed, stops being a head designation, or
  is corrected to another office (which then hands over the new office).
  Only staff whose supervisor is still the designee are given back, so a
  change HR made since is kept.
- **Timing:** recording or editing settles it at once if due. Otherwise
  `imis:apply-reassignments` (daily) does it via `settleSupervision()`
  on the start date, or the day after the end date. Each happens once.
- **Not changed:** leave already filed keeps the approver it was routed
  to. New staff joining the office later aren't handed over
  automatically; HR sets their supervisor.
- **Staffing page:** a `designations` list ("Offices headed by
  designation": office, designee, their own office and item, since/until,
  order). Each office row shows `headed_by` / `also_heads`, and
  `totals.headed_by_designation` counts the offices. The designee isn't
  counted as serving in the second office.
- **Where the office shows:** the concurrent office appears next to the
  person's own office, as "Also heads ESFO", on:
  - the employee record (`EmployeeDetails`, admin and My Employee
    Record);
  - the Employees list;
  - the Users list.

  This comes from `Employee::headDesignations()` / `concurrentOffices()`,
  sent as `EmployeeResource` `user.concurrent_units` and as the Users
  list's `concurrent_offices`. CSV exports include it as well.
- **UI:** `AssignmentModal` has a "Head of office" fieldset for
  designations. `AssignmentTimeline` shows a "Head of office" badge and
  whether staff were handed over or given back. Flash messages and audit
  metadata (`supervision`) say how many staff were handed over or given
  back.
- **Tests:** `tests/Feature/HR/HeadOfOfficeDesignationTest.php`.

### Data
- `employees`: one row per user (`user_id` unique, `restrictOnDelete`, so
  a user with an HR record can't be hard-deleted).
  - Columns: `employee_number` (unique, optional), `employment_status`
    (`App\Enums\EmploymentStatus`), `original_appointment_date` (legacy
    `first_day`), `separation_date` and `separation_reason`, and
    `remarks`.
  - Identity (name, sex, birthday, position, office) is **not**
    duplicated. It is read from `users`.
  - `user_id` isn't fillable. It is set once, explicitly, on create, so a
    record can never be moved to another person.
- `EmploymentStatus`: Permanent, Temporary, Coterminous, Casual,
  Contractual, Job Order, Contract of Service.
- A separation reason is required with a separation date, and dropped
  without one (`UpdateEmployeeRequest::employmentData()`).

### Authorization
Permissions in `PermissionName`:

| Permission | Allows |
|---|---|
| `employees.view` | Everyone's records |
| `employees.view_unit` | The viewer's own office's records (supervisors) |
| `employees.create` | Setting up a record |
| `employees.update` | Editing a record |

- Both view permissions open the Human Resources area (`access-hr`), and
  are listed in `HR_ACCESS_PERMISSIONS` and the JS `hrAccessPermissions`.
- `Employee::scopeVisibleTo()` and `EmployeePolicy::view()` apply the
  same rule: your own record always, your office's with `view_unit`, and
  anyone's with `view`.
- **Separation of duties:** HR can't set up or edit their own record
  (`StoreEmployeeRequest` and `EmployeePolicy::update`). A Super
  Administrator can, through `Gate::before`.

### Data Privacy Act (RA 10173)
- Opening someone else's record records `employee.viewed` in the audit
  log. Reading your own record does not.
- Creating a record records `employee.created`. Editing one records
  `employee.updated`, with the changed fields.
- Audit entries name the record "Employee record of {name}" (the
  `Employee::label` accessor).

### Pages
- **Admin → Human Resources → Employees**
  (`Admin\EmployeeController`, `Admin/HR/Employees/Index`, `Show`)
  - The list (DataTable) and "Add Employee Record".
  - The add form only offers active users without a record, never the
    viewer themself.
  - The record page has an "Edit employment" button.

### How records get created
`App\Support\HR\EmployeeRecords::setUp()` is the single path, and each
record is audited as `employee.created`.
- **Existing staff:** "Set up records for staff" (`BulkSetupModal`,
  `POST /admin/hr/employees/bulk`) lists every candidate, all ticked.
  - A candidate is an active account with no record, never HR
    themselves.
  - HR unticks non-staff, such as duplicate, test or service accounts,
    and creates the rest at once.
  - Only current candidates are accepted (`BulkStoreEmployeesRequest`).
- **New accounts:** `UserController::store` creates the record in the
  same transaction as the account, so a new person can fill in their PDS
  immediately.
- **Employment status is optional.** It shows as "Not set" until HR fills
  it in. The legacy IMIS never recorded it: `users_cscro8.type` is the
  office code, and `first_day` was filled for only 2 of 61 staff. So
  there was nothing to import.
- **Portal → Human Resources → My Employee Record** (`/my/employee-record`,
  `MyEmployeeRecordController`)
  - Self-service and read-only, with no permission needed.
  - Shows an empty state until HR sets the record up.
- Both pages share `Components/HR/EmployeeDetails.vue`. Plain dates use
  the new `formatCalendarDate()`, so a birthday never shifts a day in
  timezones behind UTC.

### Deploying
1. Run `php artisan migrate`.
2. Run `php artisan db:seed --class=RolePermissionSeeder`. It is
   idempotent and adds the four permissions.
3. Grant `employees.*` to an HR role.

After deploying, HR runs "Set up records for staff" once for the
existing accounts. The 2 legacy `first_day` dates can be typed in by
hand.

### Tests
`tests/Feature/HR/EmployeeRecordTest.php` covers:
- route and permission guards
- HR versus supervisor (office-scoped) visibility, including a
  supervisor with no office
- the access trail, and no trail for reading your own record
- one record per person and unique employee numbers
- the self-edit guard and the super-admin exception
- that `user_id` can't be moved
- separation validation
- the delete restriction
- the self-service page

## Human Resources: Personal Data Sheet — IMPLEMENTED (HR-2a)

The PDS is **CS Form No. 212 (Revised 2026)**. The blank form is
`../old-imis/CS Form No. 212 Revised 2026 Personal Data Sheet PDS.xlsx`.
PRISM was blank, so this was built from that form.

### What changed in Revised 2026
- Item 5 is "Sex at birth".
- Item 10 is UMID ID no., which replaces GSIS.
- Item 13 is the PhilSys Card Number (PCN). SSS was removed.
- Section VI is Learning & Development and VII is Voluntary Work. They
  were the other way round in 2017.
- Item 35b adds date filed and status of case.
- Item 40c cites RA 11861.
- References add email.
- Every page has an e-signature line.
- There are continuation sheets C5–C11.

### One definition, two uses
`App\Support\HR\Pds\PdsSchema` defines every section and field once, and
both of these are generated from it:
- the server-side validation rules
- the schema the editor renders (`forEditor()` strips server-only parts,
  such as regex patterns)

Section kinds:

| Kind | Holds | Stored in |
|---|---|---|
| `profile` | Personal information, family names, government ID | One `pds_profiles` row. The name extension (`target: user`) goes on the account instead. |
| `questions` | Items 34–40 | One encrypted JSON document |
| `list` | Repeating sections: children, education, eligibility, work experience, L&D, voluntary work, items 31–33 (one table, told apart by `scope.type`), references (`max_rows` 3) | One table per section |

- **`when`:** a field with `when` (e.g. the dual-citizenship country, the
  "If YES" details, the permanent address unless "same as residential")
  is shown, required and kept only while its condition holds.
  `PdsSchema::dropInapplicable()` blanks it otherwise.
- **Country list:** the dual-citizenship country list is the form's own
  list, copied verbatim into `config/pds.php`.

### Identity
Name, sex at birth and date of birth stay on `users`, and the agency
employee number on `employees`. The editor shows them read-only.
`users.name_extension` (new) is editable in the PDS and in the admin user
form, and is part of the display name ("Juan D. Cruz Jr.").

### Writing (`PdsWriter`)
- One section is saved at a time, in a transaction.
- **List sections sync by id:** rows with an id are updated, rows
  without one are added, and this employee's rows missing from the
  submission are removed. A row id is validated against the employee's
  own rows, so another person's row can't be targeted.
- **It returns what changed for the audit log:** field names, or added,
  updated and removed counts. It **never returns values**.
- `PdsReader` returns the decrypted PDS, and is built only for someone
  the policy allows.

### Encryption at rest
These use Laravel `encrypted` casts:
- UMID, Pag-IBIG, PhilHealth, PhilSys and TIN
- the item 34–40 answers
- the government ID number and issuance

A database dump alone doesn't reveal them.

**`APP_KEY` must be kept safe and never changed on its own.** Losing it
makes that data unreadable. To rotate it, move the old key to
`APP_PREVIOUS_KEYS`.

### Access
| Who | Can |
|---|---|
| Every employee | Read and edit their own PDS (`/my/pds`), with no permission. It needs their HR-1 record. |
| `pds.view` (HR) | Read anyone's PDS (`/admin/hr/employees/{id}/pds`) |
| `pds.update` (HR) | Edit someone's PDS on their behalf |

- Supervisors (`employees.view_unit`) never reach a PDS. This is data
  minimization under RA 10173.
- **Audit:** `pds.viewed` is recorded when someone opens another
  person's PDS. `pds.updated` is recorded for every save, including your
  own, with the section and what changed.

### Editor (`Components/HR/Pds`)
- **`PdsEditor`:** a read-only identity card, then one tab per part of
  the form. Tabs with unsaved changes get a dot. It warns before you
  leave the page, or navigate away, with unsaved changes.
- **`PdsSectionForm` and `PdsRowsForm`:** each section saves on its own.
  List sections can add, remove and reorder rows, and focus moves to a
  new row's first field.
- **`PdsField`:** decimal steps, numeric keyboards for ID numbers,
  suggestion lists, Yes/No answers, and errors tied to their inputs
  (`aria-describedby`).

### Deploying
1. Run `php artisan migrate`.
2. Run `php artisan db:seed --class=RolePermissionSeeder` to add
   `pds.view` and `pds.update`.

### Tests
`tests/Feature/HR/PdsTest.php`.

## Human Resources: PDS signing, verification and printing — IMPLEMENTED (HR-2b)

### Signing (the e-signature)
The 2026 form accepts an "(e-signature/digital certificate)".
1. **On My PDS, "Sign and submit"** asks for two things:
   - acceptance of item 42's declaration, shown in full
   - the account password, checked with the `current_password` rule
2. **The route** (`POST /my/pds/submit`) is throttled at 6 attempts per
   minute, like a password change.
3. **IMIS then records a submission:**
   - who signed, when, and from which IP
   - the frozen PDS
4. Signing is refused when nothing has changed since the last signed or
   verified copy.

### Submissions (`pds_submissions`, `PdsSubmission`)
- Each submission is an **immutable, encrypted copy** of the whole PDS
  as signed (`PdsReader` output plus the form edition).
- It keeps its own copy of the photo, so later changes never alter what
  was signed.
- **Status:** `submitted` (awaiting HR), then `verified` or `returned`.
  An unreviewed submission is `superseded` when the employee signs again,
  so HR only ever reviews the latest.
- Verified submissions are the PDS history in the 201 file.
- `PdsSubmissions` is the only writer.

### State (`PdsSubmissions::status`)
The states are:
- `draft`
- `submitted`
- `changed_since_submitted`
- `returned`
- `verified`
- `changed_since_verified`

"Changed" compares fingerprints. A fingerprint is an HMAC (keyed by
`APP_KEY`) over the PDS content and photo, with row ids excluded, so
saving without changes doesn't count.

### Verification (HR)
- **Queue:** Admin → Human Resources → PDS Verification
  (`Admin\PdsVerificationController`). It lists pending submissions,
  oldest first, marked "First submission" or "Update".
- **Review page:**
  - shows the signed copy read-only
  - shows who signed it, when and from where
  - flags the sections (and the name/identity block) that changed since
    the employee's last verified PDS (`changedSections`), with a
    "changed" badge on those tabs
- **Decisions:**
  - **Verify and file:** remarks are optional.
  - **Return to employee:** remarks are required. The employee sees them
    on My PDS.

### Permissions and policy
- `pds.verify` (new) lets HR verify. Both `pds.view` and `pds.verify`
  open the Human Resources area (`access-hr`).
- `PdsSubmissionPolicy` is registered explicitly in AppServiceProvider,
  because models under `App\Models\Pds` sit outside auto-discovery.
  - **view:** the owner, `pds.view` or `pds.verify`.
  - **review:** `pds.verify`, never your own, and only while pending.
- The morph map gains `employee` and `pds_submission`, so audit targets
  are stable.

### Photo
- Passport size (3.5 × 4.5 cm), handled by `PdsPhotos`.
- Re-encoded with GD as a centered 7:9 crop at 413×531, so no EXIF is
  kept.
- Stored on the **private** `local` disk.
- Served only through authorized routes with `Cache-Control: private,
  no-store`:
  - the employee's own
  - HR's with `pds.view`
  - a submission's frozen copy, to whoever may view that submission
- Uploads by the employee, or by HR with `pds.update`, are audited as
  `pds.updated` (section `photo`).

### Print (`Pages/HR/PdsPrint.vue`)
- A standalone page laid out like the four official pages, using the
  form's own row counts:
  - children: 13
  - education: 1 per level
  - eligibility: 7
  - work experience: 24
  - L&D: 17
  - voluntary work: 9
  - other information: 7
  - references: 3
- Overflow goes onto continuation sheets C5–C11, as the form instructs.
- Dates print as dd/mm/yyyy.
- **Signature line:**
  - On a signed copy: "Electronically signed through IMIS by {name}",
    with the signing date as "Date Accomplished".
  - On the current PDS: "Not signed — current PDS (draft)".
- The right thumbmark and the oath block print blank, for wet completion.
- **Paper:** printed on 8.5 × 13 in (long bond), as common in government
  offices. The official .xlsx mixes A4 (pages 1–2) and legal (3–4).
- **Routes:**
  - the current PDS: `/my/pds/print`, `/admin/hr/employees/{id}/pds/print`
  - a signed copy: `/pds/submissions/{id}/print`
- Printing someone else's PDS is audited (`pds.printed`).

### Audit
- `pds.submitted`, `pds.verified`, `pds.returned` and `pds.printed`.
- `pds.viewed` also covers opening a submission.

### Deploying
1. Run `php artisan migrate`.
2. Run `php artisan db:seed --class=RolePermissionSeeder` to add
   `pds.verify`.

### Tests
`tests/Feature/HR/PdsSubmissionTest.php`.

## Human Resources: 201 File documents — IMPLEMENTED (HR-2c)

### Data
- `employee_documents` / `EmployeeDocument` holds:
  - the type (`EmployeeDocumentType`: the usual CSC checklist, such as
    appointment, oath, assumption, diplomas, eligibility, PSA
    certificates, NBI, medical, SALN, NOSA/NOSI, IPCR, office orders,
    clearance, plus "Other", which needs a title)
  - title, document date and remarks
  - the file: path, original name, MIME type, size and SHA-256
  - who uploaded it
  - archive details: when, by whom and why
- Written only by `App\Support\HR\EmployeeDocuments`.

### Files
- PDF, JPG or PNG, up to 10 MB. The type is checked from the file's
  content.
- Stored on the **private** `local` disk under `hr/201/{employee}/`,
  with a random name (never the uploaded file name).
- PDFs are kept as uploaded, since they can't be safely re-encoded the
  way photos are. A SHA-256 hash records exactly what was uploaded.
- **Serving:** only through `EmployeeDocumentFileController`, either in
  the browser (`/hr/documents/{id}`) or as a download (`/download`).
  - Headers: `Cache-Control: private, no-store` and
    `X-Content-Type-Options: nosniff`.
  - The download name is safe: "Oath of Office - 2021-03-15.pdf".

### Never deleted
A wrong or outdated document is **archived with a required reason**,
and can be restored. Archived documents:
- are hidden from the employee
- stay listed for HR (behind "Show archived")
- keep their file

### Access
| Who | Can |
|---|---|
| The employee | Open their own **active** documents, with no permission. The **My 201 File** page also lists their verified PDS history. |
| `employee_documents.view` | List and open anyone's documents, archived ones included |
| `employee_documents.manage` | Upload, archive and restore. Never in your own 201 file (`EmployeePolicy::manageDocuments`). |

- Supervisors never see 201 documents.
- `EmployeeDocumentPolicy::view` is the per-file check.
- Admin routes use **scoped bindings**, so a document id only resolves
  under its own employee.

### Audit
- `employee_document.uploaded`, `archived` and `restored`.
- `employee_document.viewed` is recorded every time someone other than
  the employee opens or downloads a document.
- The morph map gains `employee_document`.

### Pages
- **HR:** the employee page's **201 File** button (`/admin/hr/employees/{id}/documents`)
  shows:
  - the "Add a document" form (`DocumentUploadForm`)
  - the documents (`DocumentList`), with archive and restore
  - the verified PDS history
- **Portal:** Human Resources → **My 201 File** (`/my/201-file`).

### Deploying
1. Run `php artisan migrate`.
2. Run `php artisan db:seed --class=RolePermissionSeeder` to add
   `employee_documents.view` and `employee_documents.manage`.

Files go to `storage/app/private`, so include that directory in server
backups.

### Tests
`tests/Feature/HR/EmployeeDocumentTest.php`.

## Human Resources: Learning & Development — IMPLEMENTED (HR-3a)

HR-3 replaces ldms (`../old-imis/ldms`). The live `ld_cscro8` holds 394
records for 59 people (2025–2026), all approved, with no certificates.

### One record, three places
The PDS Section VI table (`pds_learning_developments`,
`App\Models\Pds\PdsLearningDevelopment`) **is** the L&D record. A
training entered once shows on:
- the employee's L&D log
- their PDS
- HRD's reports (HR-3b/c)

### New columns
- ldms's fields: `modality`, `venue`, `resource_speaker`
- `ld_type_other`
- `approval_status`, with `reviewed_by`, `reviewed_at` and
  `rejection_reason`
- `created_by`
- `certificate_*`
- `legacy_id` (unique, for a re-runnable ldms import) and
  `otrs_training_id` (kept from ldms, unused yet)
- soft deletes

### Lists
| Enum | Values |
|---|---|
| `LdType` | The PDS list: Managerial, Supervisory, Technical, Foundation, Other (specify). The migration turned HR-2a's free-text types into these codes; ldms's "Leadership and Management" maps to Managerial. |
| `LdModality` | face-to-face, online, blended, self-paced, other. Null means not recorded. |
| `LdApprovalStatus` | pending, approved, rejected |

### Rules (`App\Support\HR\LearningDevelopment\LdRecords`, the only writer)
These are carried over from ldms:
- hours above 0
- end date not before the start date
- no duplicate: the same title (ignoring case) and start date for the
  same person

Required fields: title, dates, hours, type, modality and sponsor. Venue
and speaker are optional.

### Approval and the PDS
- **Employee entries start `pending`.** HRD's entries are approved at
  once (HR-3b).
- **Editing:** the owner can edit or remove an entry until it's approved
  (`PdsLearningDevelopmentPolicy`, registered explicitly). Editing a
  rejected entry sends it back to pending and clears the reason.
- **Removing soft-deletes.**
- **PDS:** Section VI shows approved and pending entries, never rejected
  or removed ones (`scopeOnPds`, read by `PdsReader`), most recent first,
  with the type as its label. It is `managed_by` L&D: read-only in the
  PDS editor (`PdsManagedList`, with a "Manage in Learning &
  Development" link on your own PDS) and excluded from the PDS save
  routes (`PdsSchema::editableSectionKeys()`).
- L&D changes count as PDS changes, through the fingerprint.

### Certificates
- Optional per training: PDF, JPG or PNG, up to 10 MB.
- Stored on the private disk under `hr/ld/{employee}/` with a random
  name. Replacing a certificate deletes the old file.
- Served at `/hr/trainings/{id}/certificate` to the owner or `ld.view`,
  with no-store and nosniff headers.
- HRD opening a certificate is audited (`ld_record.certificate_viewed`).

### Page
Portal → Human Resources → **My Learning & Development**
(`/my/learning-development`), ldms's "My L&D Interventions":
- name, position and office
- a year picker
- summary cards: trainings, hours, and per type. Rejected trainings
  don't count, and pending ones are noted.
- month tabs and type/modality filters
- the DataTable, with search, CSV and print
- add and edit (`LdRecordModal`, POST + `_method=PUT` so a certificate
  can be uploaded with the change)
- removing pending or rejected entries

### Permissions and audit
- `ld.view` lets HRD see anyone's records and certificates. HR-3b adds
  managing and approving.
- Audit events: `ld_record.created`, `updated`, `deleted` and
  `certificate_viewed`. The morph map gains `ld_record`.

### Tests
`tests/Feature/HR/LearningDevelopmentTest.php`.

## Human Resources: L&D review and management — IMPLEMENTED (HR-3b)

HRD's side of ldms: `hrd/approve_training.php`,
`hrd/add_training_multiple.php` and `hrd/ld_manage.php`.

### Permissions
| Permission | Grants |
|---|---|
| `ld.view` | The overview and anyone's log and certificates (read only) |
| `ld.manage` | Adding, editing and removing on someone's behalf (approved records included) and bulk add; also reads the overview and logs |
| `ld.approve` | The review queue: approve, or reject with a reason; also opens certificates |

All three open the Human Resources area (`HR_ACCESS_PERMISSIONS` and the JS
`hrAccessPermissions`). **Separation of duties:** HRD never reviews or
manages their own entries (a Super Administrator may, via
`Gate::before`); on their own log they use "My Learning & Development"
like anyone else.

### Policies
- `PdsLearningDevelopmentPolicy`: `viewAny` (overview), `viewQueue`,
  `createMany` (bulk), `review` (`ld.approve`, not own, not yet
  approved — a rejected entry can be approved after all), `manage`
  (`ld.manage`, not own). `view` (certificates) now also takes
  `ld.manage` and `ld.approve`.
- `EmployeePolicy`: `viewLd` (own, or `ld.view`/`ld.manage`) and
  `manageLd` (`ld.manage`, not own) for one person's log.

### Service (`LdRecords`)
- `review()` sets the status, `reviewed_by`/`reviewed_at`, and the
  rejection reason (cleared on approval).
- `createMany()` adds one training, approved, to many people. Anyone who
  already has it (the same duplicate rule: title ignoring case + start
  date) is **skipped and named** in the result, as ldms did. No
  certificate in bulk; add one per person afterwards.
- `yearLog()` builds the year view (rows, years, cards) shared by the
  employee's page and HRD's.
- HRD's edits never resubmit: the status stays as it is.

### Pages (Admin → Human Resources)
- **Learning & Development** (`/admin/hr/learning-development`): a year
  picker, cards (employees, with training, trainings, hours), a link to
  the queue when entries are pending, and every employee with their
  approved trainings, hours and pending count (separated employees
  hidden behind a toggle). DataTable CSV and print. **Add for several
  employees** opens `LdBulkAddModal` (training fields + the
  `BulkSetupModal`-style checklist of in-service employees, never
  yourself).
- **L&D Review** (`/admin/hr/learning-development/reviews`): pending
  entries, oldest first, with certificate links. Approve, or reject
  with a reason (`LdRejectModal`). Your own entries show as "Your
  entry".
- **One employee's log** (`/admin/hr/employees/{id}/learning-development`,
  linked as "L&D" from the employee page): the same `LdLog` component as
  "My Learning & Development", with add / edit / remove
  (`LdRecordModal` with `on-behalf`) and approve / reject. Training ids
  only resolve under their own employee (scoped bindings).

Shared components: `LdLog` (cards, month tabs, filters, table) and
`LdTrainingFields` (the training form fields).

### Audit
- `ld_record.log_viewed` — HRD opening someone's log (target: the
  employee record).
- `ld_record.approved`, `ld_record.rejected` (metadata: the reason).
- `ld_record.created` / `updated` / `deleted` with `on_behalf: true`
  (and `bulk: true` for bulk add, one entry per person).

### Tests
`tests/Feature/HR/LearningDevelopmentManagementTest.php`.

## Human Resources: L&D dashboard, report and ldms import — IMPLEMENTED (HR-3c)

HRD's reporting side of ldms (`hrd/fetch_hrd_dashboard.php` and
`hrd/training_report.php`), plus carrying ldms's records over. **Both
pages count approved trainings only**; ldms counted every row, but all of
its rows were approved, so the figures match.

### Figures (`App\Support\HR\LearningDevelopment\LdReports`)
- `years()`: years with any training, plus this year and the selected
  one. It is shared by every HRD L&D year picker (the overview now uses
  it too).
- `dashboard(year)` returns:
  - **stats:** employees in service (separated excluded), people trained,
    trainings this year and last, hours, and offices with employees
  - **by type:** count and percent per PDS type
  - **top 5** employees by hours
  - **monthly:** 12 counts, by start month
  - **recent:** the 8 most recently recorded trainings
- `report(year, month?, office?)` returns one row per approved training,
  sorted by surname and then most recent first. Columns are ldms's: name
  ("Cruz, Juan D."), sex, position, office, training, provider, type,
  modality, hours, venue, speaker(s), start and end.
  `officeOptions()` lists the offices that have employees.
- Year and month use `whereYear`/`whereMonth`, so they work on SQLite in
  tests.

### Pages (Admin → Human Resources), `LdReportController`
- **L&D Dashboard** (`/admin/hr/learning-development/dashboard`):
  - a year picker
  - metric cards: employees with the share trained, trainings with the
    change on last year, hours with hours per training, offices
  - a monthly activity line and a by-type bar (`SimpleChart`, each with
    "View as table")
  - top employees and recent trainings, linked to each person's log
- **Training Report** (`/admin/hr/learning-development/report`):
  - year, month and office filters (validated)
  - totals: trainings, employees and hours
  - the DataTable, whose Print and CSV are the report's print and
    export. Dates sort and export as ISO.

Both pages take `ld.view` or `ld.manage` (`PdsLearningDevelopmentPolicy::viewAny`),
like the overview. They appear in the HR sidebar menu.

### Audit
- `ld_record.report_viewed`: the report lists everyone's names, sex and
  positions, so opening it is recorded, with the year, month, office and
  row count. The dashboard shows aggregates and names only, like the
  overview, and isn't recorded.
- `ld_record.imported`: written once per import, with the counts.

### ldms import (`php artisan imis:import-legacy-ld [--dry-run] [--connection=]`)
- **Source:** `ld_cscro8` on the legacy connection (`config/legacy.php`).
- **People** are matched by `users.legacy_id`, which `imis:import-legacy`
  sets. The command refuses to run if no account has one yet.
  - Only people with an employee record can receive trainings.
  - Accounts without one are listed by username, and legacy user ids
    with no account by id. Neither is guessed. Run HR's "Set up records
    for staff", then import again.
- **Mapping:**
  - `training_name` → title, `training_provider` → sponsor,
    `training_venue` → venue, `resource_speaker` → resource_speaker,
    `training_hrs` → hours
  - type via `LdType::fromText()`: "Leadership and Management" →
    Managerial; unknown text → Other, with the text kept as its
    specification
  - **modality → null** ("not recorded"), because ldms's column default
    made it unreliable
  - `approval_status` is kept. `approved_by` → `reviewed_by`, through
    the legacy id, when it matches.
  - `created_by` → null
  - `otrs_training_id` is kept
  - timestamps: Manila time, as the app is
  - `deleted_at` is kept, so a record removed in ldms stays removed
- **Data fixes:** an end date before the start date becomes the start
  date. A row with no title or start date is skipped and counted.
- **Re-running is safe:** records already imported (by `legacy_id`) are
  skipped, never overwritten, so HR's later corrections survive. A
  training the person already logged here (ldms's duplicate rule: same
  title ignoring case, same start date) is skipped too.
- **Writing:** it goes through `LdRecords::import()`, which keeps
  `LdRecords` the only writer. Everything runs in one transaction;
  `--dry-run` rolls back and only reports.

### Tests
`tests/Feature/HR/LdReportsTest.php` and `tests/Feature/HR/LegacyLdImportTest.php`
(a fictional `ld_cscro8` on a second SQLite connection).

## Human Resources: Plantilla — IMPLEMENTED (HR-4)

The plantilla of positions, who holds each item, and the salary schedules
that price them. Built from scratch (PRISM was blank), and modelled on
the DBM plantilla (PSIPOP) columns while the office's own format is
pending. Fields can be adjusted once it arrives.

### Data
- `plantilla_items` (`PlantillaItem`):
  - item number (unique), position title, salary grade (1–33)
  - level (`PositionLevel`: first, second, third), office, the authority
    creating it, remarks
  - **Status:** *filled* if someone holds it now, *vacant* otherwise.
    *Abolished* items (`abolished_at`/`abolished_on`/`abolition_authority`)
    are kept for the record, never deleted. Only a vacant item can be
    abolished, and it can be restored.
- `plantilla_appointments` (`PlantillaAppointment`, soft deletes): **one
  period of service** by an employee on an item, which is also one
  Service Record line for HR-5.
  - step (1–8), status (only Permanent, Temporary or Coterminous:
    `EmploymentStatus::onPlantilla()`), and nature (`AppointmentNature`:
    the CSC natures, plus *step increment*)
  - `date_from`, `date_to` (null while held), end reason and remarks
  - `PlantillaItem::incumbency` is the open period.
- `salary_schedules` and `salary_rates` (`SalarySchedule`, `SalaryRate`):
  a grade × step table of monthly salaries as issued, with a name,
  reference and effective date (unique). The schedule in force on a date
  is the latest one effective by then.
  - **The amounts are entered by HR from the issuance.** The schedule in
    force is the 3rd tranche, 2026, under NBC No. 601. Nothing is seeded
    or assumed.

### Rules (`App\Support\HR\Plantilla\Plantilla`, the only writer)
- **Appointing** needs an active, vacant item that nobody held on the
  start date. If the person holds another item now (a promotion or a
  transfer), that period ends the day before ("Promotion to {item}").
- **A step increment** ends the current period the day before and opens
  the next at a higher step, on the same item and status.
- **Vacating** ends the period with a date and a reason (retirement,
  resignation, …).
- **Correcting** a period fixes its fields. **Removing** one (soft
  delete) reopens the holder's previous period if this one ended it, as
  long as that item is still free.
- **The holder follows the item:** their employment status (HR-1) and
  their account's position title are set from the open period.
  Retitling an item retitles its holder's account. The **office does not
  follow**; that's the reassignment history's job (HR-1b).
- **Pricing:** `SalarySchedules::monthlySalary(grade, step, date)`. A
  current period uses today's schedule; a past period uses the schedule
  in force when it began. A vacant item is priced at step 1.

### Pages (Admin → Human Resources)
- **Plantilla** (`/admin/hr/plantilla`):
  - metric cards: items, filled with the fill rate, vacant, abolished
  - the schedule in force, or a warning to enter one
  - All / Filled / Vacant / Abolished views, and the DataTable (Print,
    CSV): item, position and level, SG, office, incumbent, step, monthly
    salary and status
  - "Add item" (`RecordModal`, `Components/HR/plantillaFields.js`). The
    position title suggests (a datalist, not a fixed list) the titles
    already on staff accounts and other items (`Plantilla::positionTitles`),
    so the title an appointment copies onto the account stays consistent.
- **One item** (`/admin/hr/plantilla/{item}`):
  - its details and every holder, newest first
  - actions: Appoint, Edit item, Abolish/Restore, and per period Step
    increment, Vacate, Correct, Remove
  - Appoint's employee list (`Plantilla::appointableEmployees`) puts staff
    whose account position matches the item's title first, under
    "Position matches"; everyone else follows with their current title.
    Nothing is appointed automatically.
- **Positions** (`/admin/hr/positions`, `PositionController`,
  `App\Support\HR\Plantilla\Positions`, table `positions`):
  - one row per position title: salary grade (blank until HR enters it),
    monthly salary (step 1 of the grade under the schedule in force), level,
    remarks; its plantilla items with item number, place of assignment
    (the item's office) and holder; and how many staff accounts carry the
    title, with their offices
  - "Add item" on a position creates a plantilla item of that title (it
    takes the position's grade) with an optional item number and place of
    assignment, posting to the plantilla with `stay` to return here
  - **Item number and grade are optional**
    (`2026_10_28_090000_allow_incomplete_plantilla_links`), as are an
    appointment's step, status, nature and start date. A period missing any
    of them, or on an item without a grade, is incomplete
    (`PlantillaAppointment::missingDetails()`, `complete()` scope): it isn't
    priced and stays off the Service Record until HR completes it
  - "Add titles from staff" adds every title on staff accounts and items
    not yet on the list, grade blank
  - **automatic adjustment:** a plantilla item links (`position_id`) to the
    position of its title, matched however it's capitalized or spaced, and
    takes its grade and level on every save (`PlantillaItem::saving`).
    Saving a position adjusts all its items (renaming one renames them).
    The grade is the item's only grade, so Service Record lines on those
    items show the adjusted grade, as with editing an item directly.
  - a position used by an item can't be deleted; same permissions as the
    plantilla; audited (`position.*`)
- **Staffing** (`/admin/hr/staffing`, `StaffingController`,
  `App\Support\HR\Plantilla\Staffing`), for the recruitment and staffing
  plans and for tracking personnel movement. It keeps two places apart: an
  item's **place of assignment** (its office) and its holder's **station**
  (where they serve today, from the reassignment history: the last
  reassignment, or a running detail). A holder whose station differs is
  "serving elsewhere".
  - per office: plantilla items, filled, vacant, serving elsewhere (out),
    from other offices (in), actually serving here (filled − out + in),
    and in-service staff without an item, counted where they serve
  - the holders serving elsewhere, with the order behind the move
    (`EmployeeAssignments::stationsOn` now also returns `detailed_to_id`,
    `moved_on`, `order_number`, `move_kind`)
  - vacant items with grade and step-1 salary (the recruitment list)
  - the Plantilla page (new views "Serving elsewhere", "Incomplete links"),
    item page and Positions page show the same flags
    (`Staffing::servingOf`); same permissions as the plantilla
- **`php artisan imis:link-plantilla [--apply]`** links every in-service
  staff member with an active account, a position title and no item to a
  new item of that title in their account's office, as an incomplete link
  (`Plantilla::linkFromAccount`): status only if their employee record
  has a plantilla status; item number, step, nature and start date blank.
  Previews unless `--apply`; re-running skips anyone holding an item;
  audited (`plantilla_appointment.linked_from_accounts`). HR completes a
  link with Correct on the item page; until then it can't be promoted or
  given a step increment. `PlantillaItem::incumbency` picks the open
  period by id, since an incomplete link has no start date.
- **Complete from spreadsheet** (`/admin/hr/plantilla-sheet`,
  `PlantillaSheetController`, `App\Support\HR\Plantilla\PlantillaSheet`;
  `plantilla.manage`): download every active item as CSV with what's on
  record (`CsvExport`), fill in item number, grade, place of assignment
  (office name or short name) and the holder's step, status, nature and
  start date (YYYY-MM-DD or m/d/yyyy), upload, review each row's changes
  and errors, then apply. The upload waits in the session and is checked
  again when applied. A blank cell never clears a value; a grade set on
  the Positions list can't be overridden; vacant items take no holder
  details; your own appointment is refused; item numbers stay unique.
  Holder details go through `Plantilla::correct`. Audited
  (`plantilla_item.sheet_applied`, item ids in metadata). The route has
  its own path so it can't clash with `admin/hr/plantilla/{item}`.
- **`php artisan imis:import-plantilla {file.xlsx} [--apply]`**
  (`App\Support\HR\Plantilla\PlantillaOfPersonnel`, read with the
  dependency-free `App\Support\Tables\XlsxReader`): updates IMIS from the
  HR Division's certified *Plantilla of Personnel* workbook (item rows
  under office headings: item no., position, SG, salary, step, name,
  sex, birthday, TIN, original and last-promotion dates, status,
  eligibility). Previews every row unless `--apply`; re-running is safe.
  Audited (`plantilla_item.imported`, ids touched in metadata).
  - **Matching:** the account with the same surname (a hyphenated double
    surname counts by either part) and a first name in common, ignoring
    honorifics ("Atty.") and taking "Ma." for Maria. With several, the
    one with an employee record in service; the others are reported as
    likely duplicate accounts.
  - **Takes:** item number; place of assignment from the office heading
    (`PlantillaOfPersonnel::OFFICES`; "FO No. 6 Leyte" is deliberately
    unmapped until HR decides between the Leyte offices); step, status
    and, inferred, nature and start date (a last-promotion date means a
    Promotion from then, or from the assumption date in "6/22/2026
    (A: 7/15/2026)"; "-" means the Original appointment); the original
    appointment date; and the holder's sex, birthday and middle name (the
    sheet is certified, so it wins). Vacant rows become vacant items under
    the matching position.
  - **Leaves alone:** IMIS position titles (the sheet's "SENIOR PS (SENIOR
    HRS)" only identifies the position); grades (a different SG is
    reported for the Positions list); TIN and eligibility (they're the
    PDS's); and anything unusable: a step past 8, a future or unreadable
    date, a "NEW" item number. A row whose position isn't the one held in
    IMIS (an unrecorded promotion) gets staff details only.
  - Typed dates are read month first (Excel's US format), day first only
    when the first number can't be a month; each is shown in the preview.
  - Staff in service in IMIS but not on the sheet are listed at the end.
  - Tests: `tests/Feature/HR/ImportPlantillaTest.php`.
- **Salary Schedules** (`/admin/hr/salary-schedules`):
  - the list, with the one in force marked
  - the selected table (33 grades × 8 steps)
  - Edit: type the amounts in, or **paste the table** copied from the
    issuance or a spreadsheet (one line per grade; a leading grade
    number and thousands separators are fine)
  - New schedule: empty, or a copy of another. Delete.
- **On the employee record and My Employee Record:** a "Plantilla" card
  (`Components/HR/PlantillaHistory.vue`) lists the items held, with the
  SG/step, status, period and salary. It is shown to `plantilla.view` and
  to the person themselves, **not to supervisors**: salaries aren't
  theirs to see.

### Permissions and audit
- `plantilla.view` reads; `plantilla.manage` also adds and edits items,
  appoints, and maintains the schedules. Both open the admin area.
  `PlantillaItemPolicy::appoint` keeps HR off their own appointment.
- Audit events:
  - `plantilla_item.created`, `updated`, `abolished`, `restored`
  - `plantilla_appointment.appointed`, `step_increment`, `vacated`,
    `corrected`, `removed`
  - `salary_schedule.saved`, `deleted`
- The morph map gains `plantilla_item`, `plantilla_appointment` and
  `salary_schedule`.

### Tests
`tests/Feature/HR/PlantillaTest.php` (with made-up amounts).

## Human Resources: Non-Plantilla Personnel — IMPLEMENTED

Casual and contractual appointees, Job Order and Contract of Service
workers hold no plantilla item (`EmploymentStatus::nonPlantilla()`), so
they're kept on their own page: Human Resources → **Non-Plantilla**
(`/admin/hr/non-plantilla`, `Admin\NonPlantillaController`, logic in
`App\Support\HR\NonPlantilla\NonPlantilla`). Same access as the
plantilla (`plantilla.view` / `plantilla.manage`); nobody records their
own contracts (`PlantillaItemPolicy::appoint`).

- **Who is listed:** everyone in service whose employee record has a
  non-plantilla status, with status tabs and counts.
- **Contracts** (`non_plantilla_contracts`, `NonPlantillaContract`, soft
  deletes): status, designation, office, period (an open end is allowed),
  rate per day or month, funding source, contract / order number,
  remarks. One row per period; renewals are new rows (Renew pre-fills
  the next period from the last). Periods of one person can't overlap,
  and someone holding a plantilla item can't get one. Recording,
  editing or removing sets the employee record's status to the running
  (else latest) contract's. Audited as
  `non_plantilla_contract.recorded/updated/removed`.
- **Contract state:** running, ending soon (within 30 days, with days
  left), ended, starts later, or none.
- **Kept off the plantilla:** `imis:link-plantilla` skips non-plantilla
  staff, and "Add positions from staff" ignores their titles. Migration
  `2026_11_08_090001_remove_non_plantilla_staff_from_plantilla` removed
  the items it had made for them earlier (no item number, every holder
  non-plantilla — e.g. the Contract of Service Computer Technician), their
  holder rows, and Positions-list titles left unused; audited as
  `plantilla_item.non_plantilla_removed`.

Tests: `tests/Feature/HR/NonPlantillaTest.php`.

## Human Resources: Service Record — IMPLEMENTED (HR-5)

The standard CSC Service Record, used while the office's own template is
pending. **Nothing is stored**: `App\Support\HR\ServiceRecords::build()`
assembles it from the plantilla periods (HR-4) and the reassignment
history (HR-1b) each time.

### Lines
- **Columns:**
  - service: from and to
  - record of appointment: designation (the item's position title), status
    and annual salary (12 × monthly)
  - office entity: station/place of assignment and branch
  - leave without pay
  - separation: date and cause
- **One or more lines per plantilla period.** A period is split where a
  **reassignment** takes effect (the station changes) or a **new salary
  schedule** does (the salary changes), as a service record shows them.
  Step increments and promotions are their own periods already.
- **Station:** `EmployeeAssignments::officeOn()` gives the office the
  reassignment history puts them in on the line's first day, by full
  name.
- **Salary:** the schedule in force on the line's first day, × 12. It
  shows blank where no amount was entered.
- **Separation date and cause** are filled on a period's last line when
  it ended: the end reason, e.g. "Promotion to …", "Retirement".
- **Branch:** System Settings → Human Resources (default "National").
- **Leave without pay:** left blank until HR-6 (Leave).

### Service in other agencies
- **Lines entered by hand:** service in another government agency, which
  no plantilla item here holds, is stored as `service_record_entries`
  (`App\Models\ServiceRecordEntry`, soft deletes). It's the one stored
  part of the record, and the lines show with the plantilla lines in date
  order.
- **Fields:** the same CSC columns, entered directly:
  - dates (both required, ending by today)
  - designation, status, salary grade and step, annual salary
  - agency and station, shown together in the station column
  - branch, leave without pay
  - separation date and cause
  - remarks
- **Rules:** the dates may not overlap a plantilla period or another line.
- **Who:** HR with `service_records.manage`, never on their own record
  (`EmployeePolicy::manageServiceRecord`). Adding, correcting and
  removing are audited.
- **Page:** "Add service from another agency" on HR's Service Record
  page, with Edit/Remove on those lines. Routes are under
  `…/service-record/entries/{serviceRecordEntry}`, scoped to the
  employee.

### Pages
- **HR:** the employee record → **Service Record**
  (`/admin/hr/employees/{employee}/service-record`), with
  `service_records.view`. "Print certified copy" opens
  `…/service-record/print` (`HR/ServiceRecordPrint.vue`).
- **The printable form:**
  - 8.5 × 13 in landscape
  - header with the organization name, then name and birth (from the
    account and the PDS place of birth)
  - the certification statement, the lines (padded to a full sheet),
    "Nothing follows", and the date
  - "Certified correct" over the certifying officer's name and position
    (System Settings → Human Resources: `hr_certifying_officer_name`,
    `hr_certifying_officer_position`). The position is a dropdown: Chief
    Human Resource Specialist, Human Resource Division; Director IV; or
    Director III (`App\Support\HR\CertifyingOfficer::POSITIONS`). The name
    defaults to whoever holds that position's plantilla item now (the Chief
    HRS item in the office coded HRD); a typed name overrides it, and a
    vacant or ambiguous position prints a blank line
- **Employees:** Portal → **My Service Record** (`/my/service-record`) is
  read-only. Its print is marked "Copy for reference, not certified",
  with no signatory.

### Access and audit
- `service_records.view` opens the admin area. `EmployeePolicy::viewServiceRecord`
  allows your own, or anyone's with the permission. **Supervisors never
  see it**, because it shows salaries.
- HR opening or printing someone else's is recorded as
  `service_record.viewed` / `service_record.printed`.

### Not yet
- The office's own template (layout and wording). The legal citation
  some forms carry in the footer was left out until it is confirmed.
- Service before this office, from other agencies, is not modelled. It
  comes with the office's template, if needed.

### Tests
`tests/Feature/HR/ServiceRecordTest.php`.

## Human Resources: Leave foundations — IMPLEMENTED (HR-6a)

HR-6 ports the legacy LMS (`../old-imis/leave`) in three parts:
- **6a:** leave types, holidays, working days, the credit ledger, the
  leave card, accrual and opening balances
- **6b:** filing, the CS Form No. 6 approval chain, notifications, the
  printable form, and leave without pay in the Service Record
- **6c:** monetization and terminal leave, flexible work arrangements
  (FWA) and field activity reports (FAR), reports, and the calendar

Legacy's current tables (`lms_*`) exist only on the live server; their
structure was read from the services. They matter only for HR-7's data
import.

### Decisions (2026-10-03)
- **SPL and Solo Parent Leave are two separate leaves**, as CSC defines
  them. Special Privilege Leave is 3 days a year. Solo Parent Leave needs
  a valid Solo Parent ID. The legacy LMS treated "SPL" as the solo-parent
  leave.
- **Approvals (HR-6b):** HR certifies credits (`leave.certify`), the Head
  of Agency approves (`leave.approve`), and HR can record an
  officer-in-charge designation for a date range.
- **The immediate supervisor is set per employee by HR**, as in legacy
  ("no supervisor = no workflow"). Offices don't record a head.

### Leave types (`leave_types`, `LeaveType`): policy as data
- **Fields:**
  - code and name
  - route: `standard` (supervisor → HR → head) or `special` (HR → head)
  - credits: `requires_balance`; `charged_to_id` (Mandatory/Forced
    Leave uses Vacation Leave's credits); `monthly_accrual`;
    `annual_grant`; `lapses_yearly`
  - limits: `max_days_per_year`, `max_days_per_filing`
  - eligibility: `sex`, `eligible_statuses` (HR-1 employment statuses,
    none = everyone), `min_service_months`, `requires_solo_parent_id`
  - filing rules: `advance_filing_days`, `allows_half_day`,
    `counts_calendar_days` (maternity), `medical_certificate_after_days`,
    `supporting_documents`
  - also `monetizable`, `policy_reference`, `sort_order`, `is_active`
- **`LeaveTypeSeeder`** adds the 14 CS Form No. 6 leaves:
  - VL, FL, SL, ML, PL, SPL, SOLO, STUDY, VAWC, RL, SLBW, SEL, AL, LWOP
  - VL and SL earn 1.25 days a month (plantilla statuses plus casual).
    SPL grants 3 days each January and SOLO grants 7, both lapsing at
    year end.
  - Entitlements come from the statute in each type's policy reference.
    **HR should confirm them against current CSC issuances**; all are
    editable.
  - The seeder is idempotent and never overwrites HR's edits. It runs
    from `DatabaseSeeder`.

### Holidays and working days
- `holidays` (`Holiday`, `HolidayType`) are entered by HR from each
  year's proclamations; none are seeded. Regular holidays and special
  non-working days are days off; special working days are not.
- `Support/HR/Leave/WorkingDays` counts weekdays between two dates,
  skipping days off, as the legacy WorkingDayService did:
  - a half day on one working day counts 0.5
  - calendar-day leave counts every day
  - HR-6c adds compressed-work-week rest days

### The credit ledger (`leave_ledger_entries`, `LeaveLedgerEntry`)
- **Append-only:** a balance is the sum of the entries up to a date, as
  legacy `lms_credit_ledger` worked.
  - kinds (`LedgerEntryKind`): opening, accrual, grant, deduction,
    adjustment, lapse, monetization
  - signed amounts, kept to 3 decimals
  - `period_year`/`period_month` stop double posting
  - a mistake is cancelled by a **reversing entry** (`reverses_id`); the
    original stays on the card
- **`Support/HR/Leave/LeaveCredits` is the only writer:**
  - `accrueMonth()` posts the monthly accrual on the month's last day for
    everyone in service and eligible. It is idempotent: a month already
    posted is skipped, **even if it was reversed**, as in legacy.
  - `startYear()` lapses last year's unused non-cumulative credits
    (dated Dec 31), then posts this year's grants (dated Jan 1).
  - `postManual()` posts opening balances and adjustments; opening
    balances can't be negative, and the type must hold credits.
  - `reverse()` cancels an entry; a reversal can't be reversed.
  - `ineligibilityReasons()` checks sex, employment status, service and
    the Solo Parent ID. HR-6b's filing reuses it.
  - `card()` gives a year's lines with running balances per type, from
    the balances brought forward.
- **Schedule:** `imis:leave-accrual [--year --month]` runs on the 1st of
  each month for the month before, and on January's run also starts the
  year.
- **Leave without pay** pro-rates the month's accrual, added in HR-6b
  (`LeaveCredits::proRated()`; `WorkingDays::weekdaysInMonth()` is the
  legacy denominator). See HR-6b's approval rules.

### Leave settings on the employee record
`employees` gains `immediate_supervisor_id` (needed before filing opens
in HR-6b), `is_solo_parent`, `solo_parent_id_number` (**encrypted**) and
`solo_parent_id_expiry`. The ID number only reaches those who can edit
it. The audit records field names only.

### Pages
- **Admin → Human Resources:**
  - **Leave Credits** (`/admin/hr/leave`): everyone's balances per type
    (DataTable with CSV and print), a warning for people with no
    supervisor, and the **monthly accrual** and **start of the year**
    runs.
  - **Leave Types** (`/admin/hr/leave/types`): the policy table and its
    editor.
  - **Holidays** (`/admin/hr/leave/holidays`), by year.
- **One person's leave** (`/admin/hr/employees/{employee}/leave`, linked
  as "Leave" from the employee record):
  - balances, and the year's card (`Components/HR/LeaveCard.vue`, with a
    type filter)
  - opening balances and adjustments, reversals, and leave settings
- **Portal → My Leave** (`/my/leave`): your balances and card,
  read-only.

### Access and audit
- `leave.view` reads everyone's leave; `leave.manage` also maintains it.
  Both open the admin area. `EmployeePolicy::viewLeave` (own, or HR) and
  `manageLeave` (HR, never your own card) apply per person.
- Audit events:
  - `leave_type.saved`, `holiday.saved`/`deleted`,
    `leave_settings.updated`
  - `leave_credit.posted`/`reversed`/`accrual_run`/`year_start_run`
  - `leave_card.viewed` (HR opening someone's card)

### Tests
`tests/Feature/HR/LeaveFoundationTest.php`.

## Human Resources: Leave filing and approval — IMPLEMENTED (HR-6b)

Filing leave on CS Form No. 6 and its Section 7 approval chain. This
ports the legacy LeaveApplicationServiceV2, LeavePolicyService and
LeaveWorkflowService. `Support/HR/Leave/LeaveApplications` is the only
writer.

### Data
- **`leave_applications`** (`LeaveApplication`):
  - the reference number, `LV-YYYY-#####` (from the id)
  - type, dates, half day (AM/PM), and days counted
  - the 6.B detail (`LeaveDetail`: within the Philippines / abroad, in
    hospital / out patient, specify illness, Master's / BAR review),
    commutation and remarks
  - status (`LeaveApplicationStatus`): for review, returned, approved,
    disapproved, cancelled
  - days with and without pay, set on approval
  - an optional attachment on the private disk
- **`leave_application_steps`** (`LeaveApplicationStep`): one row per
  step per **round** (a resubmission starts a new round). Each records
  the part (B/A/C), role, assignee (the supervisor), action
  (`LeaveStepAction`), remarks, who acted and for whom (OIC), and HR's
  frozen `certified_credits`.
- **`leave_designations`** (`LeaveDesignation`): an officer-in-charge for
  the Head of Agency for a date range.
- **`notifications`**: Laravel's database notifications (the bell).

### Filing rules (`preview()` / `file()`)
- **Eligibility:** via `LeaveCredits::ineligibilityReasons` (sex,
  employment status, service, Solo Parent ID).
- **Supervisor:** standard leave needs an immediate supervisor set.
- **Dates:** they must hold working days (calendar days for maternity).
  A half day only where allowed, and for a single date. Advance filing
  (VL/FL 5 days). Max days per filing and per year.
- **No overlap** with another application under review, returned or
  approved.
- **Medical certificate** when the days exceed the type's threshold
  (SL over 5).
- **6.B details** ticked, and specified where the form asks.
- **Credits:** for leave paid from credits, the balance less what open
  applications on the same credits hold must cover the days. Legacy
  blocked an over-filing too.
- **Live preview:** the form shows the days, the credits, and every
  issue as you type, through a partial reload of `preview` on
  `GET /my/leave/apply`.

### The chain (CS Form 6, Section 7)
- **Routes:** standard leave goes supervisor (Part B) → HR (Part A) →
  Head of Agency (Parts C/D). Special leave (ML, Study, RL, SLBW,
  Adoption) goes HR → Head.
- **Who acts** (`authority()`):
  - the supervisor named on the employee's leave settings
  - HR: anyone with `leave.certify`
  - the Head: anyone with `leave.approve`, or an officer-in-charge
    designated for that day (recorded as acting for the Head)
  - **nobody on their own application**
- **Actions:**
  - recommended / not recommended (either goes on to HR; "not
    recommended" also tells the employee, as in legacy)
  - certified (the balances are frozen for 7.A) / insufficient credits
    (disapproved)
  - approved / disapproved
  - returned, from any step
- **Remarks** are required to disapprove, return, refuse for
  insufficient credits, or not recommend.
- **Returned:** the employee corrects and resubmits (a new round), or
  cancels. An application under review or returned can be cancelled.
- **Approval (7.C):**
  - days with pay are as many as the credits allow; the Head may approve
    fewer, never more. The rest is without pay; LWOP is all without pay.
  - **the days with pay are deducted** from the credits the type draws
    on (FL from VL), **dated the approval day**, as in legacy, so today's
    balance already excludes approved leave still to come
  - days without pay pro-rate that month's accrual (legacy formula:
    rate × weekdays rendered / weekdays) and appear in the **Service
    Record's** leave-without-pay column

### Notifications (`LeaveApplicationUpdate`, database channel)
- **Who is told:** the next actor(s) (the supervisor; all `leave.certify`
  holders; all `leave.approve` holders plus the OIC on duty), and the
  employee on a return, decision, or "not recommended".
- **The bell** in the top bar shows the unread count and leads to
  `/notifications`. Opening a notification marks it read and goes to its
  page; links are in-app paths only.
- No email yet. The legacy LeaveMailer emailed HRD on submission; that
  can be added as a mail channel once mail is configured.

### Pages
- **Portal → Human Resources:**
  - **File Leave** (`/my/leave/apply`; `?resubmit=` corrects a returned
    one)
  - **My Leave Applications**
  - **Leave Approvals** (`/leave/approvals`), shown only to approvers
    (the navigation `flag` `leaveApprover`: someone's supervisor, a
    certifier or approver, or an OIC on duty), with the waiting queue
    and recent actions
- **One application** (`/leave/applications/{id}`, for the employee,
  anyone on its chain, and HR):
  - its details and every round of the chain
  - the action form when the waiting step is yours
  - cancel, or correct and resubmit
  - the attachment, and **the printable CS Form No. 6**
    (`…/form`, `HR/LeaveFormPrint.vue`, 8.5 × 13 in): 6.A–6.D from the
    application; 7.A from HR's frozen credits (VL/SL total earned, less
    this application, balance); 7.B–7.D from the steps. Each signatory
    line names who acted in IMIS and when.
- **Admin → Human Resources:** **Leave Applications** (all, by year and
  status) and **OIC Designations**.

### Access and audit
- `LeaveApplicationPolicy`: view (the owner, the chain, HR while it's
  open, `leave.view`/`leave.manage`) and update (the owner, while it's
  open).
- **New permissions:** `leave.certify` and `leave.approve`.
- **Audit events:**
  - `leave_application.filed`, `resubmitted`, `cancelled`, `acted`
    (step, action, acting for)
  - `viewed` / `attachment_viewed` (anyone but the employee)
  - `leave_designation.saved`/`deleted`

### Tests
`tests/Feature/HR/LeaveApplicationTest.php`.

## Human Resources: Monetization, flexible work, reports, calendar — IMPLEMENTED (HR-6c)

This completes the port of the legacy LMS (HR-6).

### Wellness Leave
**Wellness Leave (`WL`)** follows **CSC MC No. 01, s. 2026** (CSC
Resolution No. 2501292, effective 1 January 2026). Its terms are in
`LeaveTypeSeeder::wellnessLeave()`, and migration `2026_10_30_090000`
applies them to an existing WL that HR hadn't filled in.
- **Entitlement:** 5 days a year, separate from VL, SL and SPL, and
  available from the date of assumption of duty. It lapses at year end
  and can't be monetized.
- **Who:** permanent, temporary, coterminous, casual and contractual
  staff. Job Order and Contract of Service workers are excluded, since
  the circular leaves them to their contracts.
- **Limits:** at most 3 days in a row (`max_consecutive_days`), counted
  across adjoining applications too.
- **Filing:** 5 days ahead, or on return in an emergency
  (`allows_emergency_filing`). The application then carries
  `is_emergency`, which shows on the form and the print.
- **Route:** supervisor, then HR, then the Head. No reason needs to be
  given (3.d, confidentiality).

These rules are leave-type settings, so other types can use them too.

**Annual grants from assumption of duty:** `LeaveCredits::grantDue()`
posts this year's annual grants (SPL, Solo Parent, WL, …) to anyone in
service who doesn't have them yet. That covers people who joined after
January 1 and types switched on mid-year. A grant is dated January 1, or
the first day in service if that's later. The monthly
`imis:leave-accrual` run calls it, and so does the year-start button for
the current year.

### Monetization and terminal leave (`Support/HR/Leave/LeaveMonetizations`)
- **Table:** `leave_monetizations` (`LeaveMonetization`), with reference
  `MON-YYYY-#####`, and balances and salary frozen at request time.
- **Partial monetization** follows the legacy MonetizationService rules:
  - at most 50 % of the VL balance (floored to 3 decimals)
  - VL plus SL after it must stay at least 15 days
  - once a calendar year (pending, approved or released all count)
  - amount = monthly salary ÷ 22 × days, where the salary is the
    current plantilla period's (HR-4)
- **Terminal leave** is filed by HR for someone with a separation date,
  for all remaining VL and SL.
- **HR's steps:** approve (the days come off the ledger as
  `monetization` entries), reject (with a reason), then mark released
  once paid. The employee can cancel a pending request. Never on your
  own request.

### Flexible work (`Support/HR/Leave/FlexibleWork`)
- **Arrangements** (`fwa_arrangements`, `FwaArrangement`): flexitime,
  compressed work week (CWW), work from home, or a combination, with a
  weekly schedule (each day office / work from home / off).
  - No overlaps with a pending or approved arrangement.
  - A CWW needs a rest day.
  - HR approves or rejects, and can end one early; the employee can
    cancel a pending one.
- **Weekly field activity reports** (`fwa_reports`, `FwaReport`):
  targets and accomplishments for a week (Monday to Friday), under an
  arrangement in effect that week. They can be edited until HR
  acknowledges them.
- **Leave counting:** `WorkingDays::count(…, $employee)` skips the rest
  days of an approved CWW in effect (legacy default: Friday off).
  Filing leave uses it.

### Reports (`Support/HR/Leave/LeaveReports`, Admin → Leave Reports)
The legacy report types that aren't already a page: on leave on a day,
utilization by type, **forced-leave compliance** (VL of 10 days or more,
and fewer than 5 days of VL/FL taken this year), leave without pay, and
manual credit entries (audit). The balance summary is Leave Credits; the
register is Leave Applications. Each is a DataTable with print and CSV.

### Calendar (Portal → Leave Calendar)
A month grid of your leave and the holidays. Approved leave is solid;
leave under review or returned is outlined.

It also shows co-workers in your division (your organizational unit)
who have filed leave: approved, under review or returned, on working
days only (`LeaveCalendar::colleagues`). Only the name, dates and status
are shared; the type of leave and the application stay private
(RA 10173). Yours is green, theirs blue; "Show my division" hides them,
and picking a day lists everyone in your division out that day.

### Leave Calendar for HR (Admin → Leave → Calendar)
New (legacy only had the personal calendar): everyone's leave on a month
grid, `/admin/hr/leave/calendar`, with `leave.view` or `leave.manage`
(`LeaveApplicationPolicy::viewAny`). `App\Support\HR\Leave\LeaveCalendar`
lists each application's days in the month, skipping weekends and
day-off holidays (except leave counted in calendar days, e.g.
maternity). Approved leave by default; "Include pending" adds leave
under review or returned (outlined). Filters: office (the user's
organizational unit) and type of leave. A day shows up to three names
and "+N more"; picking a day lists everyone out that day below the grid.
Like the applications list, the overview isn't audited; opening an
application is. Tests: `tests/Feature/HR/LeaveCalendarTest.php`.

### Notifications, access and audit
- **Notifications:** decisions on monetization, arrangements and early
  ends notify the employee (`HrUpdate`, database channel).
- **Permissions:** HR acts with `leave.manage` and reads with
  `leave.view`.
- **Audit events:** `leave_monetization.requested`/`decided`/`released`/`cancelled`,
  `fwa.requested`/`decided`/`terminated`/`cancelled`, and
  `fwa_report.submitted`/`acknowledged`.

### Not carried over
- **Automatic forfeiture of untaken forced leave:** legacy only
  reported it, and so does the compliance report here.
- **CS Form 6's "Other purpose: monetization / terminal leave" boxes:**
  monetization has its own request instead.

### Tests
`tests/Feature/HR/LeaveExtrasTest.php`.

## Phase HR-7 — Migration and cutover — IMPLEMENTED (import not yet run)

The tools for switching off the legacy LMS and ldms. (PRISM held no data
and its launcher is already deleted; see below.) The legacy
`lms_*` tables exist only on the live server, so **the leave import has
not been run against real data yet**. Its column names come from the
legacy LMS code's own queries.

### Leave import (`imis:import-legacy-leave`, `Support/Legacy/LegacyLeaveImporter`)
- **Options:** `--connection=` (default: the legacy connection) and
  `--dry-run`. Everything runs in one transaction, so a failure saves
  nothing.
- **Prerequisites:** run `imis:import-legacy` (sets `users.legacy_id`)
  and HR's "Set up records for staff" first. People are matched by
  legacy id, and only someone with an employee record is imported.
  Leave types are matched by code.
- **Parts:** each part is skipped and reported if its table is missing.
  - `lms_employee_profiles` fills only blank fields: supervisor,
    employment status, first day in service and solo-parent ID.
  - `lms_holidays_v2` (active ones) and `lms_agency_designations`
  - `lms_credit_ledger` is copied entry for entry, so balances carry
    over exactly. Legacy already posted the deductions of approved leave.
  - `lms_applications`: only decided ones (approved, rejected,
    cancelled), with their `lms_workflow_steps`. Open ones are listed,
    to be finished in legacy or re-filed.
  - `lms_fwa_arrangements`, `lms_fwa_far` and `lms_monetization_requests`
- **Re-running is safe:** every imported row keeps its legacy id
  (`legacy_id` on holidays, ledger, applications, designations,
  monetizations, arrangements and reports; migration
  `2026_10_29_090000`), and rows already imported are skipped.
- **Reference numbers** are prefixed `LMS-` so they never collide with
  new ones.
- **Attachments** (medical certificates) are not copied; their count is
  reported.
- **Legacy SPL** entries (legacy's solo-parent leave) come in as SPL and
  are counted for HR to review.
- **Audit:** `leave_credit.imported_from_legacy`, with the counts.

### Readiness (Admin → Cutover Readiness, `/admin/hr/readiness`)
`Support/HR/CutoverReadiness`: a checklist with counts only, no names.
- **People:** employee records, legacy links, employment status, first
  day in service
- **Plantilla:** a current item, a salary schedule in force, the Service
  Record officer
- **Leave:** credits, the LMS import, this year's holidays, supervisors,
  and someone holding `leave.certify` / `leave.approve`
- **Cutover:** launchers still active, whether the legacy database is
  reachable

Each item is "To do" (blocking), "Check" (worth fixing) or "Ready", with
a link to fix it. It needs `leave.manage`.

### Retiring the launchers (`imis:retire-legacy-hr`)
Sets the LMS and LDMS portal entries inactive, audited as
`application.status_changed`. Without `--apply` it only shows what it
would do. Access grants stay on record, and an entry can be reactivated
from System management. The legacy apps keep running until they are
switched off on the legacy server.

### PRISM removed
PRISM was blank, so nothing needed importing, and the HR module replaces
it. Migration `2026_10_31_090000_remove_prism_launcher` deletes its
launcher (`short_name` PRISM) outright: its system roles, access grants,
listings and SSO tokens go with it, and access-log rows keep their
history with the system cleared. It can't be rolled back; the launcher
can be re-added from System management. `imis:import-legacy` skips PRISM
(`LegacyImporter::RETIRED_SYSTEMS`), so a re-import doesn't bring it
back. The bridge leaves legacy-side PRISM grants alone, since it only
syncs systems that exist here. Test: `tests/Feature/RemovePrismLauncherTest.php`.

### Cutover order
1. Get the live `lms_*` tables reachable through the legacy connection.
2. Run `imis:import-legacy-leave --dry-run` and read the report.
3. Run the import, then compare a few leave cards with the legacy LMS.
4. Clear the "To do" items on Cutover Readiness.
5. Run both systems in parallel for a period.
6. Run `imis:retire-legacy-hr --apply`.

### Tests
`tests/Feature/HR/LegacyLeaveImportTest.php`, against fictional `lms_*`
tables.

## Human Resources: Payroll and payslips — IMPLEMENTED (HR-8)

Built from scratch (the legacy IMIS has no payroll). Decisions (user,
2026-10-04): IMIS computes each month and HR adjusts; one monthly payroll
with net pay released on the 15th and the 30th; withholding tax is entered
per employee (Accounting computes it), not computed; payslip requests are
approved by the head of HRD or whoever is designated head of HRD.

### Data (`2026_11_02_090000_create_payroll_tables`)
- `pay_components` (`PayComponent`): allowance, deduction and loan types.
  `kind` (`PayComponentKind`: earning, deduction, loan) and `method`
  (`PayComputation`): *fixed* amount, *percent* of basic salary between an
  optional floor and ceiling, or *manual* (per employee). `automatic` ones
  go on everyone's pay. `PayComponentSeeder` adds PERA, GSIS personal
  share (9%), PhilHealth (2.5% on ₱10,000–₱100,000), Pag-IBIG (2% of at
  most ₱10,000), withholding tax, RATA, MP2, cooperative, absences, and
  GSIS/Pag-IBIG/Landbank/cooperative loan types. **HR should check the
  rates against current issuances**; every one is editable.
- `employee_pay_items` (`EmployeePayItem`): someone's recurring item from
  a month (to a month). Its amount overrides the component's; 0 exempts
  them from an automatic one.
- `employee_loans` (`EmployeeLoan`): principal, total payable, monthly
  amortization, first deduction month, amount paid before IMIS, and a
  stop date. **No balance is stored**: it's total payable − paid before −
  its lines on finalized payrolls (`App\Support\HR\Payroll\Loans`), so a
  reopened payroll can't leave a balance wrong.
- `payroll_runs` → `payroll_entries` → `payroll_lines`: one month (unique),
  each person's snapshot (name, position, item, SG/step, office), basic
  salary, totals and the two releases, and their lines (`adjusted` marks
  HR's changes).
- `payslip_requests` (`PayslipRequest`, `PS-YYYY-NNNNN`): pending,
  approved, rejected or cancelled; who decided and whom they acted for.

### Rules (`App\Support\HR\Payroll\Payrolls`, the only writer)
- **Who is paid:** everyone in service that month (not separated before
  it, active account) with a complete plantilla period begun by its end,
  priced by the salary schedule in force on the 1st. Anyone else is listed
  under "Not on this payroll" with the reason.
- **Lines:** automatic components, the profile's items in effect, and each
  loan's amortization (or what's left). Earnings first, then deductions.
- **Releases:** the first half is net ÷ 2 rounded down to the centavo
  (integer centavos), the second takes the rest. Releases on the 15th and
  the 30th (the last day in February). They are called the **1st quincena**
  (15th) and **2nd quincena** (30th) and shown after the net pay on the
  payroll page, the payslip and My Earnings; earnings and deductions are
  not broken down per quincena.
- **Net pay below ₱5,000** (the GAA floor) is flagged, not blocked.
- **Draft:** HR changes, adds or removes lines, rebuilds one entry, or
  regenerates everything (discarding adjustments). **Finalized:** locked;
  loans count it as paid; payslips can be requested. **Reopen** returns it
  to a draft. A draft with no payslip requests can be deleted.
- Nobody adjusts their own pay, profile or loans (`PayrollRunPolicy::managePayOf`).

### Payslips (`PayslipRequests`, `PayslipApprovers`)
- The employee asks for a month whose payroll is final (one open request
  per month); the approvers are told under the bell.
- **Approvers:** the holder of the Chief Human Resource Specialist item at
  the office coded `HRD`, plus anyone designated head of HRD (HR-1b
  designation as head of office) while it runs. A designated head is
  recorded as acting for the Chief. Nobody decides their own; with no
  head, a Super Administrator can. Shared as the `payslipApprover` flag
  for the portal sidebar.
- Approving lets the employee open `/payslips/{entry}` (`HR/PayslipPrint`,
  half a short bond sheet). Rejecting needs remarks. Payroll staff open any
  finalized payslip; anyone other than the employee opening one is audited.

### Pages
- **Admin → Human Resources → Payroll:** Payrolls (`/admin/hr/payroll`:
  list, monitoring cards, loans nearly paid, who approves; one payroll at
  `/admin/hr/payroll/runs/{run}` with the register, entry dialog and
  actions), Pay Profiles (list and one person's), Loans (filterable,
  outstanding total), Payslip Requests (by status), Pay Components.
- **Portal → Human Resources:** My Payslips (`/my/payslips`) and Payslip
  Approvals (`/payslips/approvals`, approvers only).

### Permissions and audit
- `payroll.view` reads; `payroll.manage` also maintains components,
  profiles and loans and works on payrolls. Both open the HR area. The
  starter HR Officer role gets both (new installs only: existing roles are
  never changed by `HrRoleSeeder`, so grant them under Roles).
- Audit events `pay_component.*`, `employee_pay_item.*`, `employee_loan.*`,
  `payroll_run.*`, `payroll_entry.adjusted`, `payslip_request.*` and
  `payroll_entry.payslip_viewed`; amounts are never put in metadata.
  Opening someone's pay profile is logged as `employee.viewed`.

### Earnings & Deductions extension (`2027_01_20_090000_extend_payroll_for_overrides_and_schedules`)
Built on the tables above rather than beside them (decision 2026-10-27).
IMIS records and schedules; it never collects or remits anything, and it
is not the official payslip (the printout says so).
- **Overrides:** a changed payroll line keeps the generated amount in
  `original_amount`, with `adjusted_by/at` and an optional `adjust_reason`;
  the component, profile and loan are untouched. Setting it back clears
  the override. Loan amortization changes go through the loan (payroll
  managers only, never their own) with an optional reason, and tell the
  employee.
- **Installment schedule (`Loans::schedule`):** history from payroll lines,
  then projected months. Statuses: *scheduled* (no line yet), *reflected*
  (line on a draft payroll), *confirmed* (finalized payroll), *paid* (HR set
  `remitted_on`), *overdue* (confirmed, not marked paid 30 days after the
  second release). Loan states: active, suspended (`stopped_on`), cancelled
  (`cancelled_on`, final, reason required), completed (balance 0).
- **Cancelled payslips:** `payroll_entries.cancelled_at`; kept for audit,
  excluded from totals, loan balances and payslip requests. A finalized
  payroll is corrected by reopening it, which needs a reason (audited).
- **Notices (`DeductionNotices`, via `HrUpdate`):** assigned, amount changed,
  suspended, resumed, cancelled, reflected in a payslip and completed (on
  finalize), nearly completed (`imis:notify-loan-endings`, daily, once per
  loan and count).
- **`pay_components.category`** groups lines on **My Earnings & Deductions**
  (`/my/earnings`): mandatory contributions, government loans, other loans,
  other. It shows the employee's own latest *finalized* payroll only; the
  printable payslip still needs the approved request.
- Not done: dashboard widget for these notices; amount type "variable" and
  one-time vs recurring flags on components (fixed/percent/manual and
  profile effective months cover them). The automatic GSIS/PhilHealth/
  Pag-IBIG formulas from HR-8 remain (editable, or exempt per employee).
- Audit: `employee_loan.cancelled`, `employee_loan.remitted`,
  `payroll_entry.cancelled`; reasons go in metadata, amounts never.

### Tests
`tests/Feature/HR/PayrollTest.php`, `tests/Feature/HR/EarningsDeductionsTest.php`.

## Modular Architecture

Modules were introduced in this order, each depending on the ones before
it. Everything listed is built; what is still planned follows below.

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
    -> Per-system roles (IMPLEMENTED — Phase 15)
    -> System maintenance mode (IMPLEMENTED — Phase 16)
    -> Legacy sign-in experience & account features (IMPLEMENTED — Phase 17)
    -> Dashboard systems directory, division drill-down (IMPLEMENTED — Phase 18)
    -> Legacy admin panel adaptation (IMPLEMENTED — Phase 19)
    -> Legacy data import, legacy bridge & SSO (IMPLEMENTED)
    -> Human Resources HR-1 to HR-7 (IMPLEMENTED; the leave import awaits live data)
    -> Hardening: system health, scheduler monitoring, starter HR roles (IMPLEMENTED)
    -> Executive Dashboard: snapshots, system feeds, targets, alerts (IMPLEMENTED — Exec-1 to Exec-3)
```

New directories (`app/Actions`, `app/Services`, `resources/js/Composables`,
etc.) are created when the first module that needs them is built, not
preemptively.

Still planned: a public (unauthenticated) portal and versioned external
integrations, described below.

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
pages would need a new public layout instead of `PortalLayout` (the
authenticated application shell as of Phase 3); the former `Home` page and
its `PublicLayout` were removed, since `/` now goes to the login screen. The
`Visibility::PublicVisibility` tier already established in Phase 4 exists
for exactly this, even though no unauthenticated portal route uses it yet.

### Future Application/Integration Architecture — PLANNED
Any external system integrations (e.g. other CSC systems) are planned as
explicit, versioned service classes under `app/Services`, never embedded
directly in controllers.

## Error Handling Foundation — IMPLEMENTED

- Laravel's exception handler in `bootstrap/app.php` renders JSON for
  API/XHR requests (`shouldRenderJsonWhen`), which Inertia relies on for
  validation error handling.
- **Inertia error pages:** `Errors/403` (with its message) and
  `Errors/Show` for 404, 419, 429, 500 and 503. A 500 falls through to
  Laravel's debug page while `APP_DEBUG` is on.

## Hardening — IMPLEMENTED

### System Health (`Support/System/HealthChecks`)
Shown on Admin → System Health (`/admin/system-health`; needs
`settings.view` and the Setting policy) and by `imis:health`. The command
exits non-zero when a check fails, or also on a warning with `--strict`.
Each check is ok, warn or fail, and never shows a secret or a personal
record.
- **Application:** APP_KEY, debug mode, HTTPS
- **Database:** connection and latency, pending migrations
- **Services:** cache, private storage, free disk space, failed jobs,
  mail (a `log` mailer means password resets aren't sent)
- **Scheduler:** whether it runs at all, and each scheduled task's last
  run against its timetable
- **Legacy bridge:** whether it's on and reachable

### Scheduler monitoring (`Support/System/ScheduleMonitor`)
- `ScheduledTaskFinished` and `ScheduledTaskFailed` (listened to in
  `AppServiceProvider`) record each task's last run and outcome in the
  cache, keyed by the task's mutex name.
- The schedule itself is read from `Schedule::events()`, after
  bootstrapping the console kernel so `routes/console.php` is loaded on
  web requests too.
- Cron counts as stopped when no task has run for 15 minutes; the most
  frequent task is `imis:expire-sessions`, every five.

### Starter HR roles (`HrRoleSeeder`, called by `DatabaseSeeder`)
- **HR Officer:** `employees.*` (except `view_unit`), `pds.*`,
  `employee_documents.*`, `ld.*`, `plantilla.*`, `service_records.view`,
  and `leave.view`/`manage`/`certify`
- **Top Management** (Director IV and III): view-only oversight —
  `employees.view`, `plantilla.view`, `service_records.view`, `ld.view`,
  `leave.view`. Deliberately **not** `pds.view` or
  `employee_documents.view` (the user's choice, RA 10173 data
  minimization), and nothing that changes data
  (`TopManagementAccessTest`). The Director IV also gets Head of Agency.
- **Head of Agency:** `leave.approve`
- **Supervisor:** `employees.view_unit`

Each role is created only if missing and never changed afterwards, so
admins' edits survive re-seeding. No one is assigned a role
automatically. Intended holders (user, 2026-10-03): the HRD Chief →
HR Officer; the Director IV → Top Management and Head of Agency; the
Director III → Top Management. Director IIs (field office heads) get
nothing for now.

### Tests
`tests/Feature/SystemHealthTest.php`.

## Security Policy, Automatic Backups, Log Retention — IMPLEMENTED (Admin-1)

Three new System Settings groups (`SettingGroup::Security`, `Backups`,
`Retention`), defined in `SettingRegistry` like the others. The
credential guard skips the `password_policy_` prefix (these keys describe
rules, never a password) but still checks the rest of the key.
`SettingType::Integer` gained a `min_unless_zero` constraint: 0 means
"off/forever", any other value has a floor.

### Security (`Support/Security/SecurityPolicy`)
Everything that enforces these rules reads them through `SecurityPolicy`.
- **Session timeouts:** `security_idle_timeout_minutes` and
  `security_session_limit_minutes` override `session.lifetime` and
  `session.absolute_lifetime` at boot (`applySessionConfig()`, from
  `AppServiceProvider`). Empty keeps the `.env` value. If settings can't
  be read (fresh install), the server defaults stand.
- **Lockout:** `security_login_max_attempts` and
  `security_lockout_minutes` (defaults 5 and 15 minutes, as in the legacy
  IMIS) replace `LoginRequest`'s former constants.
- **Password rules:** `Password::defaults()` is
  `SecurityPolicy::passwordRule()`. It always requires letters and
  numbers, adds `password_policy_min_length`, `_mixed_case` and
  `_symbols`, and checks for breached passwords in production.
  `passwordRequirements()` describes the rules in words for Change
  Password and Reset Password (`Forms/PasswordRequirements`).
- **Reuse:** `password_histories` keeps the hashes of replaced passwords
  (User `updated` hook, newest 24). `Rules\NotRecentPassword` refuses the
  current password plus `password_policy_history` previous ones. For
  resets it runs inside the `Password::reset` callback, after the token
  is verified; failing there leaves the token usable.
- **Expiry:** `users.password_changed_at` is set whenever the password
  changes. The migration backfills existing accounts with the migration
  date, so turning expiry on gives everyone the full period.
  `EnsurePasswordIsCurrent` (web group) redirects every page to Change
  Password once `password_policy_expiry_days` have passed. Change
  Password, logout and the session heartbeat stay reachable; JSON
  requests get a 403. After the change the user goes to the dashboard.

### Automatic backups (`Support/Backup/BackupStore`, `imis:backup`)
- Backups are kept in private storage under `backups/`, named
  `imis-backup-YYYY-MM-DD-HHMMSS-{auto|manual}.sql.gz` (plain `.sql`
  without zlib). They are written to a `.partial` file and renamed only
  when complete. Only names matching that pattern are ever read or
  deleted.
- `imis:backup --if-enabled` runs nightly at 02:00 Asia/Manila and skips while `backup_schedule_enabled` is off. Run by
  hand, it always backs up. Each backup is followed by pruning to
  `backup_keep_count`.
- The Backup Database page lists the stored backups and adds "Back up
  now", download (password re-check, audited) and delete. It's still
  Super Administrators only, and it warns that encrypted PDS data needs
  the same `APP_KEY`.
- Audit events `system.backup_created`, `system.backup_failed` and
  `system.backup_deleted`.
- System Health gets a **Backups** check: warn when nightly backups are
  off in production or none exist yet, warn after 26 hours, fail after 74.

### Log retention (`Support/Audit/LogRetention`, `imis:prune-logs`)
- Runs nightly at 03:00 Asia/Manila. `--dry-run` prints the cutoffs and
  row counts.
- Periods in months, 0 = forever, and a floor applied both in the form
  and when pruning:

  | Log | Default | Floor |
  | --- | --- | --- |
  | Login history | 24 | 6 |
  | System access logs | 24 | 6 |
  | Audit and security log | forever | 12 |
  | Personal-data access entries | forever | 36 |

- Personal-data access entries are `AuditEvent::personalDataAccess()`:
  viewing or printing employee, PDS, 201, service record, leave, L&D and
  payslip records.
- This is the only code that deletes audit entries. It uses the query
  builder on purpose (`AuditLog` still refuses deletion through
  Eloquent) and deletes in chunks of 5,000. A run that deletes anything
  records `system.logs_pruned` with the counts and cutoffs.

### Tests
`tests/Feature/Admin/SecurityPolicyTest.php`, `BackupStoreTest.php`,
`LogRetentionTest.php`.

## Executive Dashboard — IMPLEMENTED (Exec-1 to Exec-3; Exec-4 pending)

Top management's (Director IV and III) view of every IMIS module and
connected system in one place, at `/executive` (Portal sidebar →
Management). It answers three questions in order: is anything wrong
(system tiles), how are we doing against targets (counts and each
figure's status), and where to look (each figure's breakdown, and a link
into the module).

**Figures and targets are not agreed yet** (as of 2026-10-07). The
built-in figures are starter totals; targets are data, entered on the
Settings page once management agrees on them. Until then figures show
with no on-track/behind status.

### Collection, never live (`Support/Executive/MetricCollector`)
- `imis:collect-metrics` runs every 30 minutes and saves each source's
  figures as an `executive_snapshots` row (`ok` with `metrics`, or
  `failed` with the reason). One source failing never stops the others.
- The dashboard reads only snapshots (`ExecutiveDashboard`), so it loads
  quickly and a system that is down shows its last figures and their age
  instead of breaking the page. Figures older than two hours are flagged
  as not current.
- History: everything for two days, then each source's last good
  snapshot per day; deleted after System Settings → Log Retention →
  *Executive Dashboard history* (24 months by default, floor 3).
- Trends are the daily series over 30 days; the change is against the
  first point in that window.

### Sources (`Support/Executive/Sources`, `MetricSource`)
| Key | Source | Starter figures |
| --- | --- | --- |
| `hr` | Human Resources | employees in service (by office), plantilla filled %, vacant items, compulsory retirement within 12 months |
| `leave` | Leave | on leave today (by office), awaiting action, days approved this month |
| `ld` | Learning & Development | trainings and hours this year, % of staff trained |
| `payroll` | Payroll | gross and net pay this month (with its draft/final state), payslip requests pending |
| `portal` | IMIS portal usage | % of active accounts that signed in, sign-ins, systems opened (by system), last 30 days |
| `imis` | IMIS health | checks failing (named) and needing attention, from `HealthChecks` |
| `system:{id}` | a connected system | whatever its summary feed sends |

Every figure takes one shape (`MetricFeed`): key, label, value, unit
(`count`, `percent`, `days`, `hours`, `peso`), optional period, note and
breakdown. IMIS's own figures may also carry `href` + `permission`; the
dashboard keeps a link only for viewers holding that permission. Totals
only, so viewing the dashboard isn't audited.

### Connected systems (Exec-2)
- `applications.feed_url` and `applications.feed_key` (encrypted with
  `APP_KEY`, hidden from serialization, not mass-assignable). Set only on
  Executive Dashboard → Settings, never by the general system form.
- `SystemFeedSource` GETs the address with the key as a bearer token
  (10 s timeout, no redirects, 256 KB limit) and validates the response
  with `MetricFeed::fromFeed`; a feed can't add links into IMIS.
- The contract and a copy-ready Laravel controller (PROCTAD) are in
  [`executive-feed.md`](executive-feed.md). The endpoints belong in each
  system's own repository. **JPortal** serves one (`/api/imis/summary`,
  tested locally, not yet connected in production); the others don't yet.
- System tiles: *Running*, *Maintenance* (IMIS maintenance mode),
  *Not answering*, *Not current*, *Waiting*, *Not connected*. The IMIS
  tile follows the health checks.

### Targets, alerts, weekly summary, print (Exec-3)
- `executive_targets`: one per figure, keyed `source/metric`, with a
  direction (higher or lower is better) and a tolerance in percent.
  `TargetStatus`: meeting the target → *On track*; short by no more than
  the tolerance → *At risk*; further → *Behind*.
- Alerts (`MetricAlerts`, the bell, `ExecutiveAlert`): when a figure
  turns *Behind*, when a source stops updating, and when it recovers —
  only on the change. System Settings → Executive Dashboard → *Alerts*
  (on by default).
- Weekly summary (`imis:executive-digest`, Mondays 07:00 Asia/Manila;
  `ExecutiveDigest`, email and the bell): systems needing attention and
  figures off target, built per recipient. *Weekly summary* setting, off
  by default; `--force` sends anyway.
- Recipients (`ExecutiveAudience`): active accounts whose roles grant
  `executive.view`.
- `/executive/print`: the same figures as a report to print or save as
  PDF, with no app layout.

### Permissions, policy and audit
- `executive.view` (the dashboard and its report) and `executive.manage`
  (Settings: feeds, keys, targets, *Collect now*), checked by the
  `permission:` middleware and `ExecutiveSnapshotPolicy` (`viewAny`,
  `manage`).
- The **Top Management** starter role now includes `executive.view` on a
  fresh install; on an existing install an administrator adds it to that
  role (starter roles are never changed after they are created). Nobody
  gets `executive.manage` by default.
- Audited: `executive.feed_updated`, `executive.feed_key_generated` (never
  the key itself), `executive.target_saved`, `executive.target_removed`,
  `executive.collected`.

### Exec-4 — PLANNED
The remaining legacy PHP systems (a small PHP file serving the same
feed, see `executive-feed.md`) and CTS once it is rebuilt. The legacy
code at `../old-imis` is reference only, so those endpoints are written
in the systems themselves.

### Deploying
`php artisan migrate`, then `php artisan db:seed --class=RolePermissionSeeder`
(new permissions) and `--class=SettingSeeder` (new settings). Grant
`executive.view` to Top Management and `executive.manage` to whoever
maintains the dashboard. The scheduler must be running.

### Tests
`tests/Feature/ExecutiveDashboardTest.php`.

## Privacy Notice and "Who Accessed My Record" — IMPLEMENTED (Admin-2)

RA 10173's right to be informed, in two pages.

### Privacy notice (`/privacy`, `Support/Privacy/PrivacyNotice`)
- Open to everyone, signed in or not; linked from the sign-in pages'
  footer (`AuthLayout`) and the account menu. Signed in, it sits in the
  portal layout; signed out, it stands alone with a link back to sign in.
- Written from what IMIS actually does: what it holds, why, who can see
  it (the starter roles and their limits — supervisors and top
  management never see the PDS or 201 file), what a connected system
  receives at sign-in (the hub SSO fields, no HR records), how it's
  protected, and the data subject's rights. Retention lines are read live
  from System Settings → Log Retention.
- System Settings → **Data Privacy**: the Data Protection Officer's name
  and email (empty → "the Human Resource Division"), and additional text
  the office adds (e.g. its Privacy Manual reference).
- `PrivacyNotice::UPDATED` is the date shown; bump it, and the wording,
  when a module starts holding a new kind of personal data.

### Who accessed my record (`/my/record-access`, `Support/Privacy/RecordAccessLog`)
- Everyone, no permission: the personal-data access entries
  (`AuditEvent::personalDataAccess()`) about their own records, newest
  first, 25 a page, filterable by year. Sidebar (Human Resources) and the
  account menu link to it.
- An entry targets the record that was opened, so each target type is
  traced back to the employee: `employee`, `pds_submission`,
  `employee_document`, `leave_application`, `ld_record`,
  `payroll_entry`.
- Not listed: the person's own visits, and the L&D training report
  (covers everyone, names no one). Changes to a record aren't accesses
  and aren't listed. Shows who (name, position), what, when and the
  entry's description — never the IP address.
- Without an employee record the page says so instead of a list.

### Tests
`tests/Feature/PrivacyTest.php`.

## Administration tools — IMPLEMENTED (Admin-3)

Seven independent features; none has a legacy counterpart.

### Scheduled tasks (`/admin/scheduled-tasks`, `Support/System/ScheduledTasks`)
- Every task in `routes/console.php`, in words: what it does, its timetable
  in office time ("Daily at 2:00 AM"), last and next run.
- `scheduled_tasks.manage`: **pause** (a `scheduled_task_pauses` row; the
  last block of `routes/console.php` adds a `skip()` to every task),
  **resume**, and **run now** (in the request; closures included). Every
  task skips work already done, so an early run is safe. Manual runs are
  remembered separately and don't count for System Health; a paused task
  shows there as "Check".
- Viewing needs `settings.view`. Audited: `system.task_paused`,
  `system.task_resumed`, `system.task_run`.

### Permission preview (`Utils/accessPreview.js`, `Components/Admin/AccessPreview.vue`)
- Works out which pages a set of permissions opens from the same
  navigation definitions the sidebar uses, plus the permissions that only
  allow actions inside pages.
- Role editor → **Preview** tab (follows the ticked permissions before
  saving); a user's page → **What this person can open** (follows the
  ticked roles; the controller sends each role's permissions).
- An inactive role grants nothing, so it previews as nothing; the role
  editor and a user's page say so.

### Account lifecycle (`Support/Security/AccountLifecycle`, `imis:account-lifecycle`)
- Nightly 00:30 Asia/Manila (`--dry-run` lists): deactivates the accounts
  of employees whose separation date has passed and, with System
  Settings → Security → *Deactivate after days without signing in* (0 =
  off, else 30–730), accounts with no sign-in for that long — counting
  from the last sign-in, creation or reactivation (`users.status_changed_at`,
  set whenever the status changes).
- Super Administrators are never deactivated automatically; they're
  reported instead.
- Deactivating — automatically or from Manage Users — also ends the
  person's open sessions and drops their designation's system access.
  Automatic ones are audited as `user.status_changed` with no actor,
  `automatic` and the reason.

### Bulk user import (`/users/import`, `Support/Users/UserImport`)
- CSV (template downloadable), up to 500 rows: preview first — the Add
  User rules, the office by code/short name/name, roles by name, and
  duplicates within the file — then the valid rows are created.
- Account creation is one class, `Support/Users/AccountCreator`, used by
  Add User and the import alike (username, employee record, set-password
  email, audit, designation access).
- Not importable: office designations and the Super Administrator role.
  `users.create`. Audited per account (`source: bulk import`) and as
  `user.imported`.

### Notification settings (`/account/notifications`, `Support/Notifications/NotificationPreferences`)
- Everything shows under the bell; per kind (leave, HR requests, and for
  `executive.view` holders the dashboard alerts and weekly summary), a
  person may also have it emailed (`users.notification_preferences`).
  Email is off by default except the weekly summary.
- Notification classes use `Notifications/Concerns/EmailsWhenWanted`
  (a `KIND` constant and their `toArray()`).

### Email templates (`/admin/email-templates`, `Support/Mail/EmailTemplates`)
- Subject, opening, button and closing of: new account (set your
  password), password reset, a notification sent by email, and the weekly
  summary. Placeholders in braces; unknown ones are refused. Only edited
  templates are stored (`email_templates`); deleting puts the default back.
- Password emails go through `ResetPassword::toMailUsing` (in
  `AppServiceProvider`): someone who has never signed in gets the
  new-account email.
- Live preview with sample values; *Send me a test* mails the signed-in
  administrator. `settings.view` to see, `settings.update` to change.

### Branding (`/admin/branding`, `Support/Branding/Branding`)
- Replace or reset the agency logo, the IMIS mark and the sign-in
  background (PNG/JPEG/WebP up to 2 MB, never SVG), stored on the public
  disk (`brand_assets`). Shared on every page as `branding` and read
  through `Composables/useBranding`; the preloader in `app.blade.php` uses
  `Branding::url()`.
- The name beside the header logo is the `header_name` setting (System
  Settings → General). Colors and type stay in the design tokens.

### Deploying
`php artisan migrate`, then `db:seed --class=RolePermissionSeeder` (new
`scheduled_tasks.manage`) and `--class=SettingSeeder`
(`account_inactive_days`, `header_name`). `php artisan storage:link` must
exist for uploaded branding.

### Tests
`tests/Feature/Admin/ScheduledTasksTest.php`, `PermissionPreviewTest.php`,
`AccountLifecycleTest.php`, `UserImportTest.php`, `EmailTemplatesTest.php`,
`BrandingTest.php`, and `tests/Feature/NotificationSettingsTest.php`.
