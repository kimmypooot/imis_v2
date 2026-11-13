# IMIS UI design system

The shared look for IMIS and the systems under the hub (JPortal, GAD Corner,
IPCRS, ICT SRTS, …). IMIS is the reference: when this guide and the code
disagree, `resources/css/app.css` and the **Design system** page
(`/design-system`, local only) win.

Stack: Tailwind CSS 4 (CSS-first, no `tailwind.config.js`), Vue 3, Inertia.

## Principles

- **Flat and quiet.** Cards are white with a 1px border and no shadow. Only
  floating layers (menus, dialogs) are elevated. No gradients, and no colored
  left-edge bars: mark active or status states with a tinted background, a
  full thin border, an icon or a dot.
- **Tokens, not values.** Colors, radii, shadows, type sizes, z-index layers
  and easing come from `@theme`. No raw palette classes (`gray-500`), no hex
  in components, no arbitrary `text-[13px]`, `z-[70]` or `rounded-[…]`.
- **Color is never the only signal.** Status shows a word as well as a tone.
- **Accessible by default.** Form-control edges meet 3:1, focus is always
  visible, motion respects `prefers-reduced-motion`, touch targets are 44px
  on phones.
- **Business logic stays out of components.** UI components render props and
  emit events.

## Adopting it in another system

1. **Stack.** Tailwind 4 with `@tailwindcss/vite`; load Poppins (400–700).
2. **Copy `app.css`** from IMIS into the system's stylesheet: the `@theme`
   block, the `@utility` entries (`z-tooltip`, `z-dialog`, `z-toast`,
   `skeleton`, `table-head`, `filter-control`, `form-control`,
   `scrollbar-on-dark`), the `@layer base` rules and the reduced-motion and
   phone input-size rules at the bottom. Do not copy `boot.css` unless the
   system has the same opening screen.
3. **Copy the components** it needs from `resources/js/Components/`
   (below). They depend only on Vue, Inertia's `Link` and each other.
4. **Keep one source.** Change a token in IMIS first, then re-copy. Do not
   fork values per system.
5. **Check against `/design-system`** side by side before release.

## Tokens (in `@theme`)

| Group | Tokens | Use |
|---|---|---|
| Brand | `csc-blue`, `-dark`, `-light`, `csc-red`, `-dark` | Identity only; use the semantic names below in components |
| Roles | `primary`, `primary-hover`, `accent`, `surface`, `surface-muted`, `border`, `border-strong`, `text`, `text-muted`, `focus-ring`, `focus-ring-on-dark` | `border` for cards and dividers; `border-strong` for form-control edges |
| Feedback | `success`, `warning`, `danger`, `info`, each with `-bg` and `-border` (plus `-hover` for success and danger); `bypass` | Alerts, badges, status. `danger` is the darker CSC red for AA contrast; `accent` (brand red) is decoration only |
| Charts | `chart-1`, `chart-2`, `chart-neutral` | Fixed series order |
| Radius | `sm` 4px, `md` 6px, `lg` 8px, `xl` 12px | `sm` badges/checkboxes, `md` controls and buttons, `lg` cards, `xl` modals and auth cards |
| Shadow | `shadow-menu`, `shadow-modal` | Menus/popovers, dialogs. Nothing else is shadowed |
| Type | Tailwind `xs`–`4xl`, plus `text-2xs` (11px), `text-compact` (13px), `text-stat` (26px) | See the type scale below |
| Layers | `z-10`–`z-50` in-page; `z-tooltip` 70, `z-dialog` 90, `z-toast` 95 | Header 40, sidebar 30, menus and modals 50 |
| Motion | `ease-standard`; animations `fade-in`, `rise`, `shimmer`, `shake`, `breathe` | Always pair with `motion-reduce:` |

### Type scale

| Class | Used for |
|---|---|
| `text-xl sm:text-2xl font-semibold text-primary` | Page title (one `h1`, from `PageHeader`) |
| `text-base font-semibold text-text` | Card title (`h2`, from `Card`) |
| `text-sm font-semibold text-text` | Sub-heading (`h3`) |
| `text-xs font-semibold tracking-wide text-text-muted uppercase` | Small section label |
| `text-sm` | Body |
| `text-compact` | Dense menus and table text |
| `text-xs text-text-muted` | Captions, helper text |
| `text-2xs` | Smallest labels |
| `text-stat font-bold` | Big figures |

## Components

All live in `resources/js/Components/`. Use these instead of raw markup.

- **UI:** `Button` (variants primary, secondary, outline, danger, success,
  ghost, link; sizes sm/md/lg), `ActionButton` (icon + tooltip), `Badge`
  (`pill` for counts), `StatusBadge`, `Card`, `PageHeader`, `Tabs`, `Table`,
  `DataTable`, `Pagination`, `EmptyState`, `Skeleton`, `Spinner`, `Icon`,
  `UserAvatar`, `StatTile` (counts up, bar fills on arrival), `Dropdown`,
  `Tooltip`, `ProgressBar` (determinate or indeterminate), `LoadingState`
  (in-panel spinner + line), `ErrorPage` (403/404/419/429/500/503).
- **Forms:** `TextInput`, `PasswordInput`, `Select`, `Textarea`, `Checkbox`,
  `CheckboxGroup`, `Radio`, `Switch`, `FileInput`, `FormGroup`, `InputError`.
  For a compact field in a filter bar use `class="filter-control"`; for a
  one-off raw field use `class="form-control block w-full px-3 py-2"`.
- **Effects (in `app.css`):** `stagger` (children rise in turn, once per
  page), `card-interactive`, `ripple` (Button), the red page-load bar,
  `animate-pop`/`animate-ping-soft`. `Button` takes `icon`/`iconRight`;
  `Badge` takes `pulse`; `Card` takes `interactive`. All are flat: no
  gradients; every motion has a reduced-motion fallback.
- **Feedback:** `Modal` (solid brand-navy header by default; green for "Add", red for
  destructive), `ConfirmDialog`, `Alert`, and the toast/confirm
  host (`DialogHost`, `useDialog`).
- **Shell:** `AppShell` with `AdminLayout`, `PortalLayout`, `AccountLayout`
  and `AuthLayout`; `SidebarNav`, `UserMenu`, `HelpMenu`, `NotificationMenu`.

Choosing between similar pieces: `Badge` is a tag (rounded-md); `StatusBadge`
is a status with a dot; `Switch` applies immediately, `Checkbox` is saved
with a form; `Table` is for small static tables, `DataTable` for searchable,
sortable, paged lists. Tables that mirror a paper form keep their own
form-like header.

## Reusing this in other hub systems

Copy, in this order, so the look stays identical: the `@theme` block, `@utility`
blocks and keyframes in `resources/css/app.css`; `Components/UI/` (Button,
Spinner, ProgressBar, LoadingState, Skeleton, Collapse, Badge, Card, tables,
ErrorPage); `Components/Feedback/` (Modal, Alert, DialogHost);
`Composables/useCountUp.js`. Error pages render `ErrorPage` from a thin page per
status (see `Pages/Errors`). Keep it flat: no gradients, no container shadows.

## Rules for new code

- Reach for a component first; if none fits, build from tokens, and if it
  will be used twice, add it to `Components/UI` and to the Design system page.
- Labels on every field; errors next to the field; destructive actions go
  through `ConfirmDialog`.
- Every transition has `motion-reduce:transition-none`.
- Print pages (`*Print.vue`) are exempt: they mirror official paper forms and
  keep their own pt and hex values.
- Dark mode is not built. Color tokens are CSS variables, so it can be added
  later with one override block (and a review of the white-on-navy surfaces
  and print pages).

## Known gaps

- Compact inline tables (plantilla history, payroll profile, PDS lists) still
  use their own header style instead of `table-head`.
- `NotificationMenu` has its own open/close logic (it loads on open and has
  a phone-width panel) rather than using `Dropdown`.
- Executive `MetricTile` and `SystemTile` are executive-specific and are not
  merged into `StatTile`.
