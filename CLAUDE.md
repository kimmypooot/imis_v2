# CSC RO VIII IMIS

This is a Laravel + Vue 3 + Inertia.js + Tailwind CSS + MySQL application.
See [`README.md`](README.md) for setup and conventions, and
[`docs/architecture.md`](docs/architecture.md) for what's implemented versus
planned.

Key conventions:
- JavaScript only — no TypeScript.
- Business logic belongs in the backend (controllers stay thin); Vue
  components render props and dispatch Inertia requests.
- Design tokens (brand colors, typography) live in `resources/css/app.css`
  under `@theme` — don't hard-code brand values in components.
- Keep dependencies minimal; prefer what Laravel/Vue/Tailwind already
  provide before adding a package.
- UI follows the shared design system (`docs/ui-design-system.md`, live
  page at `/design-system`). Reuse its components in
  `resources/js/Components/` (Dropdown, Tooltip, Switch, StatTile,
  InputLabel, rich file upload, alerts, tables, pager, badges, form
  errors) instead of hand-writing markup, and change tokens in
  `app.css` first. Any new color token needs a dark value too.
- There is a dark theme: `data-theme="dark"` on `<html>`, set by
  `useTheme` and an inline script in `app.blade.php`, switched from
  "Appearance" in the user menu. Only tokens change, so components
  follow; use tokens (including the `-fg` text tokens), never fixed
  colors. Printing stays light.
- Flash messages show as toasts, once each. Row actions use the icon
  row-action buttons; no colored left-edge bars on toasts, cards or tiles.

Phases 1–19 are implemented — `docs/architecture.md` has one section per
phase and is the source of truth for what exists. Admin-1 (security
policy, automatic backups, log retention, all in System Settings) and
Admin-2 (privacy notice at `/privacy`, "who accessed my record" at
`/my/record-access`) and Admin-3 (scheduled-task controls, permission
preview, account lifecycle, bulk user import, notification settings,
email templates, branding) are done. In
particular:
- Authentication is session-based and signs in by **username** (legacy
  IMIS convention), not email; password resets still go by email. Don't
  re-scaffold login, password reset, or email verification — extend the
  existing controllers (`routes/auth.php`, `LoginRequest`).
- Authorization is roles → permissions on Laravel Gates/Policies (no
  Spatie). Every route is guarded by `permission:` middleware *and* a
  policy check; new permissions go in `App\Enums\PermissionName`.
- Each external system can have its own roles (`ApplicationRole` /
  `ApplicationAccess`), separate from IMIS platform roles.
- Self-registration is not implemented; accounts are created by admins.
- Human Resources is being built in phases HR-1–HR-7 (plus HR-1b,
  reassignment history between offices, done), replacing the
  legacy PRISM, ldms (`../old-imis/ldms`) and LMS (`../old-imis/leave`)
  subsystems. HR-1 (employee record), HR-2a (PDS, CS Form 212 Revised
  2026), HR-2b (e-signing, HR verification, printing) HR-2c (201 file
  documents), HR-3a (Learning & Development log, replacing ldms), HR-3b
  (HRD L&D review queue, per-employee management, bulk add) and HR-3c
  (L&D dashboard, training report, `imis:import-legacy-ld`) HR-4
  (plantilla, appointments, salary schedules) and HR-5 (Service Record,
  built from plantilla periods; standard CSC columns) are done. HR-6
  (Leave, porting `../old-imis/leave`) is split into 6a/6b/6c; 6a (leave
  types as data, holidays, working days, the append-only credit ledger,
  accrual, leave card) 6b (filing on CS Form 6, supervisor → HR →
  Head approval with OIC designations, notifications/bell, printable
  form, LWOP) and 6c (monetization/terminal leave, flexible work and
  weekly reports, leave reports, calendar) are done. Wellness Leave
  follows CSC MC No. 01, s. 2026 (5 days/year, max 3 in a row, emergency
  filing on return). HR-7's cutover tools are
  built (`imis:import-legacy-leave`, Cutover Readiness page,
  `imis:retire-legacy-hr`), but the leave import hasn't run on real
  data: the `lms_*` tables are only on the live server. HR
  data is personal data under RA 10173: permission-gated, and every
  access by someone other than the employee is audited. PDS identity
  numbers, items 34–40 and signed submissions are encrypted with
  `APP_KEY`, so that key must never be lost or changed carelessly.
  HR-8 (payroll: pay components, pay profiles, loans, monthly payrolls
  released on the 15th/30th, payslips requested by staff and approved by
  the head of HRD) is done; it has no legacy counterpart.
  Progress and next steps: `docs/hr-checkpoint.md`.
- GAD Corner (`../gad-corner-v2`, a separate app under the hub like JPortal)
  replaces the legacy GAD Corner panel (`../gad-corner`, reference only).
- The Executive Dashboard (`/executive`, Exec-1 to Exec-3) is done:
  scheduled snapshots of every module, connected systems' summary feeds
  (contract in `docs/executive-feed.md`), targets, alerts and a weekly
  summary. Which figures and targets top management tracks is not agreed
  yet: the built-in figures are starters and targets are data. JPortal
  (`../jportal`, a separate app under the hub) serves a feed; no other
  system does yet. Exec-4 (legacy PHP systems, CTS) is next.
- Released versions are tracked in What's New notes (latest 3.7.0, 2026-11-14;
  3.7.0 refreshed the look: error pages, solid-blue dialog headers, button
  icons, a flat progress bar/spinner and stagger/count-up on dashboards;
  3.6.0 added a dark theme, phone install and the Executive dashboard
  revamp; 3.5.x added ETTS and CARES hub APIs, richer branded emails with a live
  Email Templates preview, payslip approvals / my payslips / my earnings
  pages, PDS import from the CSC Excel form, monthly leave cards imported
  from HR's workbook, and the security hardening below).
- Hub systems: ETTS (ESD Transactions Tracking System), TIMS (signs out
  with IMIS, see `docs/sso-logout.md`), CARES (directory API and roles) and
  HUMANS are registered alongside JPortal, GAD Corner, IPCRS and ICT SRTS.
  Feeds to other systems (Recruitment, PROCTAD, ETTS, CARES, ICT SRTS) are
  bearer-token APIs; Recruitment API reads are audited. Details per system
  are in `docs/architecture.md`.
- Security hardening is in place: security headers, account-wide login
  limit, trusted hosts, capped decompressed XLSX parts with libxml network
  access disabled. Keep these when touching uploads, imports or middleware.
- Manage Users supports bulk activate/deactivate; inactive accounts are
  hidden from access screens and plantilla staffing. The sidebar is split
  into smaller groups (blue sidebar for admin/HR, HR Leave split into daily
  work and setup) and remembers open groups.

The legacy PHP IMIS this replaces lives at `../old-imis` (reference only —
never edit it). Match its behavior when carrying a feature over.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
