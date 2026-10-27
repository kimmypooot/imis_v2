<div align="center">

# CSC RO VIII IMIS

**The next-generation Integrated Management Information System of the Civil Service Commission, Regional Office VIII**

One portal for the office's systems, services, people and reporting.

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Vue](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)
![Inertia](https://img.shields.io/badge/Inertia.js-3-9553E9?logo=inertia&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white)

</div>

---

## Overview

CSC RO VIII IMIS replaces the legacy PHP IMIS. It is the staff-facing hub for
the regional office: a directory of internal systems and services with
single sign-on, a full Human Resources suite, an executive dashboard for top
management, and the administration tools to run it all.

Features carried over from the legacy system keep its behavior. Where the
legacy system had no counterpart (payroll, the executive dashboard), the
feature is new.

## Features

### Portal and directory
- **Dashboard** with committees, announcements, release notes and a systems
  directory with division drill-down
- **Applications, services and organizational units** directories with global
  search; inactive or restricted records return a plain 404
- **Single sign-on** into connected systems, including legacy PHP subsystems
  (see [`docs/architecture.md`](docs/architecture.md#legacy-bridge--single-sign-on--implemented))
- **Per-system roles**, separate from IMIS platform roles

### Human Resources
Replaces the legacy PRISM, ldms and LMS subsystems.

| Area | What it covers |
|------|----------------|
| Employee record | Core employee profile, reassignment history between offices |
| Personal Data Sheet | CS Form 212 (Revised 2026), e-signing, HR verification, printing |
| 201 file | Document storage per employee |
| Learning & Development | Training log, HRD review queue, dashboard and reports |
| Plantilla | Positions, appointments, salary schedules |
| Service Record | Built from plantilla periods, standard CSC columns |
| Leave | Leave types, holidays, credit ledger, accrual, leave card, filing on CS Form 6, supervisor → HR → Head approval, monetization, flexible work, reports, calendar |
| Payroll | Pay components and profiles, loans, monthly payrolls, payslip requests |

HR data is personal data under RA 10173: access is permission-gated, and every
access by someone other than the employee is audited.

### Executive Dashboard
Scheduled snapshots of every module, summary feeds from connected systems
(contract in [`docs/executive-feed.md`](docs/executive-feed.md)), targets,
alerts and a weekly summary.

### Administration
- Users, roles and permissions, with permission preview
- Account lifecycle and bulk user import
- Audit and activity logs, plus a "who accessed my record" page for staff
- System settings: security policy, automatic backups, log retention,
  scheduled-task controls, notification settings, email templates, branding
- System health checks and maintenance mode
- Privacy notice at `/privacy`

## Tech Stack

| Layer      | Technology                              |
|------------|------------------------------------------|
| Backend    | Laravel 13, PHP 8.4                      |
| Frontend   | Vue 3 (Composition API), Inertia.js 3    |
| Styling    | Tailwind CSS v4                          |
| Database   | MySQL / MariaDB                          |
| Build tool | Vite                                     |
| Tests      | PHPUnit                                  |

JavaScript only. The project does not use TypeScript.

## Getting Started

### Requirements

- PHP 8.3+ (8.4 recommended)
- Composer 2.x
- Node.js 20+ and npm
- MySQL 8+ or MariaDB 10.4+
- Git

### Installation

```bash
git clone <repository-url> new-imis
cd new-imis
composer install
npm install
cp .env.example .env
php artisan key:generate
```

> [!WARNING]
> `APP_KEY` encrypts sensitive HR data (PDS identity numbers, items 34–40 and
> signed submissions). Back it up, and never change or lose it in an
> environment that holds real data.

### Database

1. Create the database:
   ```sql
   CREATE DATABASE csc_ro8_imis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Set `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `.env`.
3. Migrate and seed the reference data (the `super-admin` role and permission
   set, default settings and the 15 CSC RO VIII offices). No demo records or
   test accounts are seeded.
   ```bash
   php artisan migrate
   php artisan db:seed
   ```
4. Optionally import the legacy IMIS data (accounts with their existing
   passwords, systems and roles, access, committees, announcements, release
   notes and sign-in history):
   ```bash
   php artisan imis:import-legacy ../old-imis/db_imis.sql
   ```
   The dump holds real staff data. Never commit it or copy it into this
   repository.

There is no public registration. For a local account:

```bash
php artisan tinker --execute="App\Models\User::factory()->create(['username' => 'you']);"
```

The factory's default password is `password`. Sign-in is by **username**.

### Run

```bash
composer run dev      # Laravel server + queue listener + Vite
php artisan serve     # Laravel server only
npm run dev           # Vite dev server only
```

### Build

```bash
npm run build         # production frontend build
php artisan optimize  # cache config, routes and views
```

### Test

```bash
php artisan test
```

The application itself runs on MySQL/MariaDB. Only the test suite uses a
throwaway in-memory SQLite database (see `phpunit.xml`), so tests never touch
your real data.

## Operations

- **Scheduler**: production needs the usual cron entry, which also expires
  idle sign-ins, runs backups and collects dashboard snapshots:
  ```
  * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
  ```
- **Health check**: Admin → System Health, or `php artisan imis:health`
  (add `--strict` to fail on warnings). Run it after every deploy.
- **Mail**: locally `MAIL_MAILER=log` writes emails, including password-reset
  links, to `storage/logs/laravel.log`.
- **Sessions**: sign-ins end after `SESSION_LIFETIME` minutes idle (default
  120) or `SESSION_ABSOLUTE_LIFETIME` in total (default 480). "Remember me"
  sign-ins are exempt.
- **HTTPS**: set `SESSION_SECURE_COOKIE=true` once served over HTTPS.

## Security

- Session-based authentication; 5 failed sign-ins per username and IP lock
  that pair out for 15 minutes.
- Authorization is roles → permissions on Laravel Gates and Policies. Every
  route has `permission:` middleware **and** a policy check; frontend
  `can()` helpers only hide UI.
- The `super-admin` role bypasses permission checks and cannot be
  self-assigned.
- External URLs are validated as `http(s)` only, and directory cards never
  redirect to a stored URL.
- Error pages never expose stack traces, SQL, paths or environment values.
- Sensitive HR fields are encrypted at rest.

## Project Structure

```
app/
  Enums/            Status, Visibility, PermissionName, ...
  Http/             Controllers (thin), Middleware, Form Requests, Resources
  Models/           Eloquent models and shared concerns
  Policies/         One policy per resource
  Support/          Domain logic (HR, leave, payroll, plantilla, ...)
  Console/Commands/ imis:* commands (import, health, backup, accrual, ...)
resources/
  css/app.css       Tailwind entry and design tokens (@theme)
  js/
    Pages/          Inertia pages
    Components/     UI, Forms, Navigation, Feedback, Portal, ...
    Layouts/        AuthLayout, AppShell, PortalLayout, AdminLayout
routes/             web, auth, portal, admin
database/           migrations and seeders
docs/               architecture.md, executive-feed.md, hr-checkpoint.md
tests/              Feature and Unit tests
```

## Conventions

- **Backend**: Laravel conventions, thin controllers; business logic lives in
  the backend, not in Vue.
- **Frontend**: Vue 3 `<script setup>`, plain JavaScript, small components.
  Build new UI from the existing component library before writing one-off
  markup.
- **Design tokens**: brand colors and typography live in
  `resources/css/app.css` under `@theme`. Don't hard-code brand values in
  components.
- **Permissions**: add new ones to `App\Enums\PermissionName`.
- **Dependencies**: kept minimal. Prefer what Laravel, Vue and Tailwind
  already provide.

## Documentation

| Document | Contents |
|----------|----------|
| [`docs/architecture.md`](docs/architecture.md) | One section per phase: what is implemented, design decisions, route tables |
| [`docs/hr-checkpoint.md`](docs/hr-checkpoint.md) | HR module progress and next steps |
| [`docs/executive-feed.md`](docs/executive-feed.md) | Contract for systems that feed the Executive Dashboard |

## Status

Phases 1–19 (portal, administration, authorization), HR-1 to HR-8 (Human
Resources and payroll), Admin-1 to Admin-3 and Exec-1 to Exec-3 are done.

Still open: running the legacy leave import on real data (the `lms_*` tables
exist only on the live server), and Exec-4 (legacy PHP systems and CTS).

## Brand

| | |
|---|---|
| CSC Blue | `#2A338F` |
| CSC Red | `#EC1C2D` |
| Typeface | Poppins |
