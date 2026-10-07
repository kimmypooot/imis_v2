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

Phases 1–19 are implemented — `docs/architecture.md` has one section per
phase and is the source of truth for what exists. Admin-1 (security
policy, automatic backups, log retention, all in System Settings) and
Admin-2 (privacy notice at `/privacy`, "who accessed my record" at
`/my/record-access`) are done. Admin-3 (account lifecycle, permission
preview, notification settings, scheduled-task controls, bulk user
import, email templates, branding) is next. In
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
- The Executive Dashboard (`/executive`, Exec-1 to Exec-3) is done:
  scheduled snapshots of every module, connected systems' summary feeds
  (contract in `docs/executive-feed.md`), targets, alerts and a weekly
  summary. Which figures and targets top management tracks is not agreed
  yet: the built-in figures are starters and targets are data. No system
  serves a feed yet; Exec-4 (legacy PHP systems, CTS) is next.

The legacy PHP IMIS this replaces lives at `../old-imis` (reference only —
never edit it). Match its behavior when carrying a feature over.
