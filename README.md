# CSC RO VIII Next-Generation Integrated Management Information System

## 1. Project Purpose

This is the successor to the Civil Service Commission Regional Office VIII
(CSC RO VIII) Integrated Management Information System (IMIS).

**This is a fresh project, not a migration.** The previous IMIS codebase was
not inspected, reused, or ported. This repository is being built from the
ground up using a modern stack and current development practices. The old
IMIS exists only as the conceptual predecessor of this system.

This README currently documents **Phase 0 — Foundation** and **Phase 1 —
Authentication**. Business modules (user management, roles/permissions,
portal content, administration, etc.) are introduced in later phases. See
[`docs/architecture.md`](docs/architecture.md) for what's implemented today
versus what's planned.

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
(`users`, `cache`, `jobs`, `password_reset_tokens`, `sessions`), plus a
`status` column (`active`/`inactive`) added to `users` for Phase 1.

To create a user for local testing (there is no public registration):

```bash
php artisan tinker --execute="App\Models\User::factory()->create(['email' => 'you@example.test']);"
```

The factory default password is `password`.

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
route boundary.

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
- **Rate limiting**: login attempts are limited to 5 per minute per
  email+IP; the forgot-password and verification-resend endpoints are
  limited to 6 per minute per IP.

## 11. Project Structure

```
app/
    Enums/                UserStatus, etc.
    Http/
        Controllers/
            Auth/         Login, logout, password reset, email verification
        Middleware/       Including HandleInertiaRequests
        Requests/
            Auth/         LoginRequest (validation + authentication logic)
    Models/               Eloquent models
resources/
    css/app.css           Tailwind entry + design tokens
    js/
        app.js            Inertia/Vue bootstrap
        Pages/            Inertia page components
            Auth/         Login, ForgotPassword, ResetPassword, VerifyEmail
        Layouts/          AuthLayout, PortalLayout, AdminLayout
        Components/       Shared UI components (Button, TextInput,
                          PasswordInput, Checkbox, InputError, ...)
    views/
        app.blade.php     Inertia root template
routes/
    web.php               Web routes
    auth.php              Authentication routes
database/
    migrations/           Users table + status column, framework tables
docs/
    architecture.md        Architecture decisions and future plans
```

Folders are created only when needed — this structure grows as features are
added, not ahead of time.

## 12. Development Conventions

- **Backend**: PSR-12, Laravel conventions, thin controllers, no business
  logic in route closures.
- **Frontend**: Vue 3 Composition API (`<script setup>`), plain JavaScript
  (no TypeScript), small focused components.
- **Design tokens**: CSC brand colors, typography, and spacing are defined
  once in `resources/css/app.css` (`@theme` block) and consumed everywhere
  else via Tailwind utility classes — never hard-coded per component.
- **Dependencies**: kept minimal; a new package must justify itself over
  native Laravel/Vue/Tailwind functionality.

## 13. Security Considerations

- Sessions use Laravel's default cookie settings (`httponly`, `samesite=lax`).
  Set `SESSION_SECURE_COOKIE=true` in `.env` once the app is served over
  HTTPS in production — this is left unset (not hardcoded) so local HTTP
  development keeps working.
- CSRF protection is on by default and untouched.
- Passwords are never logged, never returned in Inertia props, and never
  included in validation error payloads beyond the field name.
- Login and password-reset endpoints are rate-limited (see the
  Authentication section above).
- Account status (`active`/`inactive`) is enforced server-side only, inside
  `LoginRequest`; there's no client-side gate to bypass.

## Brand

- Primary CSC Blue: `#2A338F`
- Primary CSC Red: `#EC1C2D`
- Primary typeface: Poppins
