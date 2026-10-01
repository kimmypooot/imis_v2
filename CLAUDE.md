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

Phases 1–18 are implemented — `docs/architecture.md` has one section per
phase and is the source of truth for what exists. In particular:
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

The legacy PHP IMIS this replaces lives at `../old-imis` (reference only —
never edit it). Match its behavior when carrying a feature over.
