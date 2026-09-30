# CSC RO VIII Next-Generation Integrated Management Information System

## 1. Project Purpose

This is the successor to the Civil Service Commission Regional Office VIII
(CSC RO VIII) Integrated Management Information System (IMIS).

**This is a fresh project, not a migration.** The previous IMIS codebase was
not inspected, reused, or ported. This repository is being built from the
ground up using a modern stack and current development practices. The old
IMIS exists only as the conceptual predecessor of this system.

This README currently documents **Phase 0 — Foundation**: the technical base
the application is built on. Business modules (authentication, user
management, roles/permissions, portal content, administration, etc.) are
introduced in later phases. See [`docs/architecture.md`](docs/architecture.md)
for what's implemented today versus what's planned.

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

No business tables exist yet — only Laravel's framework-required tables
(users, cache, jobs).

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

The testing infrastructure (PHPUnit, `RefreshDatabase`, feature/unit test
directories) is in place; a minimal smoke test proves the environment works.
Comprehensive test coverage is added alongside each feature in later phases.

## 10. Project Structure

```
app/
    Http/
        Controllers/     Route handlers
        Middleware/       Including HandleInertiaRequests
    Models/               Eloquent models
resources/
    css/app.css           Tailwind entry + design tokens
    js/
        app.js            Inertia/Vue bootstrap
        Pages/            Inertia page components
        Layouts/          AuthLayout, PortalLayout, AdminLayout
        Components/       Shared UI components (e.g. Button)
    views/
        app.blade.php     Inertia root template
routes/
    web.php               Web routes
database/
    migrations/           Framework-only migrations for now
docs/
    architecture.md        Architecture decisions and future plans
```

Folders are created only when needed — this structure grows as features are
added, not ahead of time.

## 11. Development Conventions

- **Backend**: PSR-12, Laravel conventions, thin controllers, no business
  logic in route closures.
- **Frontend**: Vue 3 Composition API (`<script setup>`), plain JavaScript
  (no TypeScript), small focused components.
- **Design tokens**: CSC brand colors, typography, and spacing are defined
  once in `resources/css/app.css` (`@theme` block) and consumed everywhere
  else via Tailwind utility classes — never hard-coded per component.
- **Dependencies**: kept minimal; a new package must justify itself over
  native Laravel/Vue/Tailwind functionality.

## Brand

- Primary CSC Blue: `#2A338F`
- Primary CSC Red: `#EC1C2D`
- Primary typeface: Poppins
