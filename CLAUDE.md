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
