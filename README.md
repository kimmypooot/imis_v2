# CSC RO VIII Next-Generation Integrated Management Information System

## 1. Project Purpose

This is the successor to the Civil Service Commission Regional Office VIII
(CSC RO VIII) Integrated Management Information System (IMIS).

**This is a fresh project, not a migration.** The previous IMIS codebase was
not inspected, reused, or ported. This repository is being built from the
ground up using a modern stack and current development practices. The old
IMIS exists only as the conceptual predecessor of this system.

This README currently documents **Phase 0 — Foundation**, **Phase 1 —
Authentication**, **Phase 2 — Authorization**, **Phase 3 — UI/UX Design
System & Application Shell**, **Phase 4 — IMIS Portal Foundation &
Dashboard**, **Phase 5 — Organizational Structure & Regional Support
Units**, and **Phase 6 — Services Directory**. Full management modules
(application CRUD, Committees, Announcements, Audit, etc.) are introduced
in later phases. See [`docs/architecture.md`](docs/architecture.md) for
what's implemented today versus what's planned.

## 2. Technology Stack

| Layer      | Technology                          |
|------------|--------------------------------------|
| Backend    | Laravel 13, PHP 8.4                  |
| Frontend   | Vue 3 (Composition API), Inertia.js  |
| Styling    | Tailwind CSS v4                      |
| Database   | MySQL / MariaDB                      |
| Build tool | Vite                                 |

JavaScript only — this project does not use TypeScript.

## 3. Requirements

- PHP 8.3+
- Composer 2.x
- Node.js 20+ and npm
- MySQL 8+ or MariaDB 10.4+
- Git

## 4. Installation

```bash
git clone <repository-url> new-imis
cd new-imis
composer install
npm install
cp .env.example .env
php artisan key:generate
```

## 5. Environment Setup

All environment-specific configuration lives in `.env` (never committed).
Copy `.env.example` and adjust values for your machine — application name,
URL, database credentials, cache/session/queue drivers, and mail settings.

## 6. Database Configuration

1. Create a MySQL/MariaDB database (utf8mb4, InnoDB):
   ```sql
   CREATE DATABASE csc_ro8_imis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env` to match.
3. Run migrations:
   ```bash
   php artisan migrate
   ```

Besides Laravel's framework-required tables (`users`, `cache`, `jobs`,
`password_reset_tokens`, `sessions`) and the `status` column added to
`users` in Phase 1, Phase 2 adds `roles`, `permissions`, `role_user`, and
`permission_role`; Phase 4 adds `organizational_units`, `applications`,
and `services`; Phase 5 adds a hierarchy (`parent_id`) and contact/code
fields to `organizational_units` (a migration extending the Phase 4
table, not a new one); Phase 6 adds `service_categories` and extends
`services` with the directory fields (purpose, requirements, procedure,
fees, processing time, contact info, category) described below.

To create a user for local testing (there is no public registration):

```bash
php artisan tinker --execute="App\Models\User::factory()->create(['email' => 'you@example.test']);"
```

The factory default password is `password`.

Seed the reference data (the `super-admin` role and permission set,
default settings, and the 15 real CSC RO VIII offices). No demo records
or test accounts are seeded:

```bash
php artisan db:seed
```

Then import the legacy IMIS data: accounts (with their existing
passwords), systems and roles, system access, dashboard committees,
announcements, release notes, and sign-in/launch history. See
[`docs/architecture.md`](docs/architecture.md#legacy-data-import--implemented).

```bash
php artisan imis:import-legacy ../old-imis/db_imis.sql
```

The dump holds real staff data. Never commit it or copy it into this
repository.

## 7. Development Commands

```bash
composer run dev      # Laravel server + queue listener + Vite, concurrently
php artisan serve     # Laravel dev server only
npm run dev           # Vite dev server only (HMR)
```

## 8. Build Commands

```bash
npm run build          # Production frontend build
php artisan optimize   # Cache config/routes/views for production
```

## 9. Testing

```bash
php artisan test
```

Tests run against an in-memory SQLite database (configured in
`phpunit.xml`), independent of your local MySQL setup. Phase 1 adds feature
tests under `tests/Feature/Auth/` covering login, logout, remember me,
password reset, email verification, account status, and the guest/auth
route boundary. Phase 2 adds `tests/Feature/Authorization/` (role/permission
relationships, route security, direct-request security, privilege-escalation
prevention) and `tests/Unit/` coverage for mass-assignment protection and
the permission cache. Phase 3 adds `tests/Feature/ErrorPagesTest.php`
(404/403 Inertia error rendering). Phase 4 adds `tests/Feature/Portal/`
(portal/applications/services/organizational-units access and visibility,
admin access, search) and `tests/Unit/Models/PortalVisibilityTest.php`
(the `HasPortalVisibility` scope/method pair). Phase 5 adds
`tests/Feature/Admin/OrganizationalUnitsTest.php` (CRUD authorization,
validation incl. duplicate code/self-parent/circular hierarchy/invalid
URL, mass-assignment protection, activate/deactivate) and
`tests/Unit/Models/OrganizationalUnitHierarchyTest.php` (relations,
ancestors/descendants, cycle detection, slug uniqueness). Phase 6 adds
`tests/Feature/Admin/ServicesTest.php` (CRUD authorization, search/
category/unit filtering, validation incl. required fields/invalid URL/
non-http URL scheme, slug uniqueness, mass-assignment protection,
activate/deactivate) and `tests/Unit/Models/ServiceModelTest.php`
(category/organizational-unit relationships, slug uniqueness, visibility
enforcement).

## 10. Authentication

Session-based login at `/login`, using Laravel's own `Auth`/`Password`
facades (no third-party auth package). See
[`docs/architecture.md`](docs/architecture.md#authentication--implemented-phase-1)
for the full route table and design decisions. Key points:

- **Account status**: new users default to `active`. An `inactive` user
  cannot log in and sees a generic "contact the system administrator"
  message. There is no UI yet to change status — that arrives with
  administration in a later phase; use `php artisan tinker` in the
  meantime.
- **Password reset**: request a link at `/forgot-password`. Locally, since
  `MAIL_MAILER=log`, the email (including the reset link) is written to
  `storage/logs/laravel.log` instead of actually being sent — open that
  file after requesting a reset to get the link. To test with real email
  delivery, point `MAIL_*` in `.env` at a provider (or a local catcher like
  Mailpit) and set `MAIL_MAILER=smtp`.
- **Email verification**: implemented (`/verify-email`, resend, and the
  signed verify link) but **not currently enforced** — `/dashboard` only
  requires `auth`, not `verified`. Add the `verified` middleware to that
  route in `routes/web.php` when this should be required.
- **Rate limiting**: 5 failed sign-ins per username+IP lock that pair out
  for 15 minutes (as in the legacy IMIS); the forgot-password and
  verification-resend endpoints are limited to 6 per minute per IP.
- **Session limits** (legacy IMIS): a sign-in ends after
  `SESSION_LIFETIME` minutes idle (default 120) or `SESSION_ABSOLUTE_LIFETIME`
  minutes in total (default 480), with a browser warning
  `SESSION_IDLE_WARNING` minutes before (default 5). "Remember me"
  sign-ins are exempt. Expired sign-ins are closed in the login history
  by `imis:expire-sessions`, which the scheduler runs every five minutes,
  so production needs the usual cron entry:
  `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`.

## 11. Authorization

Role/permission authorization built on Laravel's native Gate/Policy system
(no third-party package). See
[`docs/architecture.md`](docs/architecture.md#authorization--implemented-phase-2)
for the full data model, route table, and security design. Key points:

- A user can hold multiple roles; a role can hold multiple permissions.
  Duplicate assignments are prevented at the database level (unique
  composite constraints on `role_user` and `permission_role`).
- **Super Administrator** (`super-admin` role) bypasses all permission
  checks via a single `Gate::before` callback in `AppServiceProvider` —
  identified only by role name, never by email or user ID, and it cannot
  be self-assigned by anyone who doesn't already hold it (enforced in
  `App\Http\Requests\User\AssignRolesRequest`).
- `$user->can('resource.action')`, `$user->hasPermission(...)`,
  `$user->hasRole(...)` and their `any`/`all` variants are all available;
  policies (`app/Policies`) and route middleware (`role:`, `permission:`)
  both use them.
- `/authorization-demo` (as a Super Administrator) shows your roles, permissions, and example checks. `/roles` and
  `/permissions` are minimal admin screens to prove role/permission
  management works — not the future user-administration module.
- Frontend `can()`/`hasRole()` helpers (`resources/js/Composables/useAuthorization.js`)
  are UX-only; every action they could hide is independently authorized
  server-side.

## 12. UI/UX Design System & Application Shell

A reusable component library and authenticated application shell — see
[`docs/architecture.md`](docs/architecture.md#uiux-design-system--application-shell--implemented-phase-3)
for the full breakdown. Key points:

- **Design tokens** live in `resources/css/app.css` (`@theme`): CSC brand
  colors, semantic surface/text colors, semantic feedback colors
  (success/warning/danger/info), and a radius scale. Never hardcode a hex
  value in a component — reference the token (`bg-primary`, `text-danger`).
- **Component library** (`resources/js/Components/`): `UI/` (Button, Card,
  Badge, EmptyState, Spinner, Icon, Table, PageHeader), `Forms/` (TextInput,
  PasswordInput, Checkbox, Radio, Select, Textarea, FileInput, FormGroup,
  ...), `Navigation/` (Breadcrumbs, SidebarNav, UserMenu), `Feedback/`
  (Alert, Modal, ConfirmDialog). Every component is presentation-only — it
  never knows about a specific business module.
- **Application shell** (`Layouts/AppShell.vue`): responsive sidebar (drawer
  on mobile, persistent on desktop), header, user menu. `PortalLayout` and
  `AdminLayout` are both thin wrappers around it with their own nav config
  (`resources/js/Utils/navigation.js`). There is no public landing page:
  `/` goes to the login screen.
- **Authorization-aware navigation**: nav items declare an optional
  `permission`; `SidebarNav` hides ones the user lacks using the Phase 2
  `useAuthorization()` composable. This is UX only — the route itself is
  still independently protected server-side.
- **Error pages** (`Pages/Errors/`): 403/404/419/429/500/503 all render a
  professional Inertia page (never a stack trace or internal detail); 500
  falls back to Laravel's own debug page locally when `APP_DEBUG=true`.
- **Design system demo**: visit `/design-system` (any authenticated user)
  for a live reference of every component — typography, colors, buttons,
  form controls, alerts, badges, a table, a modal, empty/loading states.
  It is development-only and not connected to real data.

## 13. IMIS Portal

The authenticated home of the app (`/dashboard`) — the central platform for
discovering applications, services, and organizational units. See
[`docs/architecture.md`](docs/architecture.md#imis-portal-foundation--dashboard--implemented-phase-4)
for the full breakdown. Key points:

- **Routes**: `/dashboard` (portal home), `/applications` (+
  `/applications/{id}`), `/services` (+ `/services/{id}`),
  `/organizational-units` (+ `/organizational-units/{id}`), `/search`,
  `/admin` (landing page, requires an admin-qualifying permission). `/`
  redirects an authenticated user to `/dashboard` and a guest to `/login`
  (no public landing page).
- **Directory models**: `App\Models\{Application,Service,
  OrganizationalUnit}`. Every listing/detail query goes through
  `HasPortalVisibility` (`status` must be `active`, `visibility` must be
  `public`/`authenticated`/an `administrative` tier the viewer qualifies
  for) — never filtered ad hoc, so a new page can't forget the rule. An
  inactive or not-visible-to-you record returns a plain 404, not a 403
  (it doesn't exist for you, rather than exists-but-forbidden).
- **External applications**: a directory card always links to the entry's
  own internal detail page; only that page exposes the real external
  `url` as a plain link (`target="_blank" rel="noopener noreferrer"`, no
  server-side redirect route, so there's no open-redirect surface). A
  `null` url renders "not yet configured" rather than a fabricated link.
  Imported systems carry their legacy launch paths, made absolute against
  the legacy host (`--legacy-url`).
- **Search**: `/search?q=...` — plain `LIKE` queries across all three
  models (visibility-scoped, 10 results per category), no search engine
  dependency.
- **Administration entry point**: the `access-admin` Gate ability
  (`AppServiceProvider::ADMIN_ACCESS_PERMISSIONS`) is true for anyone
  holding *any* of `roles.view`/`permissions.view`/`users.view`/
  `organizational_units.view`. It gates the Administration nav entry,
  `/admin`, and `administrative`-visibility directory entries — never a
  hardcoded email or user ID.
- **No fake data**: only the real CSC RO VIII offices are seeded; systems,
  accounts and access come from the legacy import, and services are
  created by administrators. The dashboard shows real (possibly empty) query results, never invented
  statistics or charts.

### Organizational structure (Phase 5)

`organizational_units` now supports a real parent/child hierarchy and has
full admin management. See
[`docs/architecture.md`](docs/architecture.md#organizational-structure--regional-support-units--implemented-phase-5)
for the full breakdown. Key points:

- **Hierarchy**: `parent_id` (adjacency list) + `parent()`/`children()`/
  `ancestors()`/`descendants()` on `App\Models\OrganizationalUnit`.
  Self-parenting and circular hierarchies (assigning a descendant as
  parent) are rejected server-side in `UpdateOrganizationalUnitRequest`,
  independent of what the admin form offers.
- **Unit types**: `App\Enums\UnitType` (`regional_support_unit`,
  `field_office`, `satellite_office`, `other`) — the frontend renders
  options the backend passes as props, never a hardcoded list.
- **Admin CRUD**: `/admin/organizational-units` (list/search/filter,
  create, edit, activate/deactivate) — distinct from the portal's
  read-only `/organizational-units`. No hard delete; deactivation only.
- **Permissions**: `organizational_units.view/create/update/archive`,
  gated by `App\Policies\OrganizationalUnitPolicy`.
- **Portal directory**: `/organizational-units` now lists root-level units
  only; a unit's detail page shows its parent breadcrumb, active
  sub-units, and contact info (email/telephone/address/website) when set.

### Services directory (Phase 6)

`services` now describes what a service actually is (purpose,
requirements, procedure, fees, processing time, contact info) and has
full admin management, plus a lightweight category taxonomy. See
[`docs/architecture.md`](docs/architecture.md#services-directory--implemented-phase-6)
for the full breakdown. Key points:

- **Data model**: extends the existing `services` table (not a new one) —
  adds `short_name`, `slug`, `purpose`, `requirements`, `procedure`,
  `fees`, `processing_time`, `contact_information`, `featured`, and
  `category_id` (FK to the new `service_categories` table, replacing the
  old free-text `category` string); `url` renamed to `external_url`.
- **Categories**: `App\Models\ServiceCategory` (name/slug/description/
  status/sort_order) — deliberately minimal, no dedicated admin CRUD
  screens yet; picked from a dropdown when managing a service.
- **Admin CRUD**: `/admin/services` (list/search/filter by status,
  category, organizational unit; create, edit, activate/deactivate) —
  distinct from the portal's read-only `/services`. No hard delete.
- **Permissions**: `services.view/create/update/archive`, gated by
  `App\Policies\ServicePolicy`.
- **Portal directory**: `/services` supports `?search=`, `?category_id=`,
  and `?organizational_unit_id=` filters and orders featured services
  first; a service's detail page shows only the fields actually set,
  never invented placeholder content.
- **Security**: `external_url` is validated as `http(s)` only (rejects
  `javascript:`/other schemes); every long-text field is a plain
  `Textarea` rendered through Vue's auto-escaping interpolation — no rich
  text/HTML input exists to sanitize.

## 14. Project Structure

```
app/
    Enums/                UserStatus, RoleName, PermissionName, RoleStatus,
                          Status, Visibility, UnitType
    Http/
        Controllers/
            Auth/         Login, logout, password reset, email verification
            Admin/         OrganizationalUnitController, ServiceController
                          (admin CRUD)
            RoleController, PermissionController, UserController,
            AuthorizationDemoController, PortalController,
            ApplicationController, ServiceController,
            OrganizationalUnitController (portal directory, read-only),
            PortalSearchController, AdminController
        Middleware/       HandleInertiaRequests, EnsureUserHasRole,
                          EnsureUserHasPermission
        Requests/
            Auth/         LoginRequest (validation + authentication logic)
            Role/          StoreRoleRequest, UpdateRoleRequest,
                          AssignPermissionsRequest
            User/          AssignRolesRequest
            OrganizationalUnit/ StoreOrganizationalUnitRequest,
                          UpdateOrganizationalUnitRequest (incl. cycle
                          validation), UpdateOrganizationalUnitStatusRequest
            Service/        StoreServiceRequest, UpdateServiceRequest
                          (incl. http(s)-only URL validation),
                          UpdateServiceStatusRequest
        Resources/         ApplicationResource, ServiceResource,
                          OrganizationalUnitResource (shape Inertia props)
    Models/
        Concerns/          HasPortalVisibility, HasUniqueSlug
        User, Role, Permission, Application, Service, ServiceCategory,
        OrganizationalUnit (parent/children/ancestors/descendants)
    Policies/             RolePolicy, PermissionPolicy, UserPolicy,
                          OrganizationalUnitPolicy, ServicePolicy
    Providers/            AppServiceProvider (Gate/authorization wiring,
                          access-admin ability)
    Support/              PermissionCache
resources/
    css/app.css           Tailwind entry + design tokens
    js/
        app.js            Inertia/Vue bootstrap
        Composables/       useAuthorization.js (frontend can()/hasRole())
        Utils/             navigation.js (nav item configs)
        Pages/            Inertia page components
            Auth/         Login, ForgotPassword, ResetPassword, VerifyEmail
            Admin/         Index.vue (landing), Roles/, Permissions/,
                          Users/, OrganizationalUnits/, Services/
                          (each Index/Create/Edit)
            Authorization/ Demo.vue
            Dev/           DesignSystem.vue (component library reference)
            Errors/        403.vue, Show.vue (404/419/429/500/503)
            Portal/         Dashboard.vue, Search.vue, Applications/,
                          Services/, OrganizationalUnits/
        Layouts/          AuthLayout, AppShell, PortalLayout,
                          AdminLayout
        Components/
            UI/            Button, Card, Badge, EmptyState, Spinner, Icon,
                          Table, PageHeader, ErrorPage
            Forms/          TextInput, PasswordInput, Checkbox, Radio,
                          Select, Textarea, FileInput, InputLabel,
                          HelperText, InputError, FormGroup
            Navigation/     Breadcrumbs, SidebarNav, UserMenu
            Feedback/       Alert, Modal, ConfirmDialog
            Portal/         PortalSection, PortalCard, QuickAccess,
                          PortalSearch, SearchResults, ExternalLinkIndicator
            AuthBrandHeader.vue
    views/
        app.blade.php     Inertia root template
routes/
    web.php               Web routes (/, guest-vs-authenticated branch)
    auth.php              Authentication routes
    portal.php             Portal, applications, services, org units, search
    admin.php             /admin landing + roles/permissions/users +
                          admin/organizational-units + admin/services + demo
database/
    migrations/           Users, roles, permissions, role_user,
                          permission_role, organizational_units,
                          applications, services, service_categories,
                          framework tables, plus Phase 5/6 migrations
                          extending organizational_units and services
    seeders/               RolePermissionSeeder, SettingSeeder,
                          RegionalOfficeSeeder
docs/
    architecture.md        Architecture decisions and future plans
```

Folders are created only when needed — this structure grows as features are
added, not ahead of time.

## 15. Development Conventions

- **Backend**: PSR-12, Laravel conventions, thin controllers, no business
  logic in route closures.
- **Frontend**: Vue 3 Composition API (`<script setup>`), plain JavaScript
  (no TypeScript), small focused components — build new UI from
  `resources/js/Components/{UI,Forms,Navigation,Feedback}` rather than
  writing one-off markup; add a component there only if a second page will
  reuse it. Portal-specific reusable pieces live in `Components/Portal/`
  rather than `UI/Forms/Navigation/Feedback`, since they're specific to
  the directory-of-things concept (Application/Service/OrganizationalUnit
  cards, search) rather than generic UI primitives.
- **Design tokens**: CSC brand colors, typography, and spacing are defined
  once in `resources/css/app.css` (`@theme` block) and consumed everywhere
  else via Tailwind utility classes — never hard-coded per component.
- **Dependencies**: kept minimal; a new package must justify itself over
  native Laravel/Vue/Tailwind functionality — the icon system, for
  example, is a handful of inline SVGs (`Components/UI/Icon.vue`) rather
  than an icon library dependency.

## 16. Security Considerations

- Sessions use Laravel's default cookie settings (`httponly`, `samesite=lax`).
  Set `SESSION_SECURE_COOKIE=true` in `.env` once the app is served over
  HTTPS in production — this is left unset (not hardcoded) so local HTTP
  development keeps working.
- CSRF protection is on by default and untouched.
- Passwords are never logged, never returned in Inertia props, and never
  included in validation error payloads beyond the field name.
- Login and password-reset endpoints are rate-limited (see the
  Authentication section above).
- Every authorization check is enforced server-side (policies, gates,
  middleware); frontend `can()`/`hasRole()` only hides UI. Assigning the
  `super-admin` role requires already holding it — see the Authorization
  section above.
- Account status (`active`/`inactive`) is enforced server-side only, inside
  `LoginRequest`; there's no client-side gate to bypass.
- Error pages (403/404/419/429/500/503) never receive a stack trace, SQL
  error, file path, or environment variable from the backend — only a
  status code and a fixed, plain-English message.
- Portal directory cards never link straight to a stored external `url` —
  they always link to the entry's own internal detail page, and there is
  no server-side "redirect to this URL" route, so there is no open-redirect
  surface. An inactive or visibility-restricted application/service/
  organizational unit 404s on direct URL access rather than leaking that
  it exists via a 403.
- Organizational unit hierarchy edits are validated server-side
  (`UpdateOrganizationalUnitRequest`) against self-parenting and circular
  hierarchies, independent of what the admin form offers as options — a
  hand-crafted request cannot bypass it. Mass assignment is limited to
  each model's `#[Fillable]` list, same as every other model.
- A service's `external_url` is validated with the `url` rule *and* a
  `regex:/^https?:\/\//i` check, so a stored value can never resolve to a
  `javascript:`/`data:` URI when rendered as a link. Every service
  long-text field (description, purpose, requirements, procedure, contact
  information) is plain text rendered through Vue's auto-escaping — no
  rich text/HTML input exists, so there's nothing to sanitize.

## Brand

- Primary CSC Blue: `#2A338F`
- Primary CSC Red: `#EC1C2D`
- Primary typeface: Poppins
