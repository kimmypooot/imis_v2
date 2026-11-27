# Calendar of Activities in IMIS — design

Date: 2026-11-27. Status: approved by the user on 2026-11-27.

## Intent

CSC RO VIII keeps a monthly **Calendar of Activities** in a shared Google
Sheet ("2025 Calendar of Activities.xlsx", one tab per month, headed
"CSC RO VIII CALENDAR OF ACTIVITIES for DECEMBER 2025"). Every office —
the seven Regional Office divisions (ORD, ESD, MSD, HRD, PALD, PSED, LSD),
the field offices (Leyte I and II, Eastern Samar, Samar, Biliran, Southern
Leyte, Northern Samar) and the Western Leyte satellite office — has
activities on it. The **Public Assistance and Liaison Division (PALD)** is
the process owner.

IMIS takes this over: offices post their own activities, PALD curates the
whole calendar, staff see what is happening across the region, and the
monthly sheet is printed and downloaded from IMIS in the same layout.

It is **for internal staff only** (no public page). It is **separate from
the leave calendar**, but looks like it.

## Decisions made with the user

1. **Inside IMIS**, not a separate hub app: it needs IMIS's offices, users,
   permissions and holidays, and it spans every office rather than belonging
   to one division.
2. **Internal staff** are the audience.
3. **Offices post directly; PALD curates** (option 2 of 3). Entries are
   visible at once. PALD can edit, return or remove any entry, sees what is
   new or changed, and gets clash warnings. There is no approval queue.
4. **A yearly plan kept up to date** (option 2 of 2): offices enter the
   year's planned activities, an activity may start with only a month, and
   it is marked scheduled, moved, cancelled or done through the year.
   Unplanned activities can be added any time and are flagged as such.
5. **Name and title:** it is the "Calendar of Activities"; the monthly output
   is headed **"CSC RO VIII CALENDAR OF ACTIVITIES for {MONTH} {YEAR}"**.
6. **Committees and other responsible parties:** the office hosting a
   committee's secretariat posts the committee's activities (e.g. MSD posts
   BAC meetings); the "Responsible Office/Personnel/Committee" is free text
   when it is not simply the posting office (e.g. "Spiritual Committee",
   "CSC RO XII"). PALD encodes anything that belongs to no office.
7. **Approach A**: proper activity records with screens, a month grid and a
   generated monthly print/Excel (not an on-screen spreadsheet, not reading
   the Google Sheet).
8. **A month calendar like the leave calendar** is part of it, alongside
   the sheet-style month list.

## The reference sheet (what the output must match)

Per month, a table (not a grid). Row 1: the title. Then a header row with
seven columns, in order:

| # | Column | Example |
|---|--------|---------|
| 1 | Date/Time | `8:00 AM - 10:00 AM`, `1:30 PM - onwards`, `12:00 NN - 1:00 PM` |
| 2 | Mode | `Face-to-face`, `Hybrid`, `Online` |
| 3 | Venue | `1st Floor, Patriotism Hall, HRC Building` |
| 4 | Participants | `RO & FOs`, `RO`, `BAC Members/BAC Secretariat` |
| 5 | Activity | `Special BAC Meeting` |
| 6 | Responsible Office/Personnel/ Committee | `MSD`, `Spiritual Committee`, `CSC RO XII` |
| 7 | Remarks | |

Each day with entries gets a **date row** — `1 December 2025 (Monday)` —
followed by that day's activities, earliest first. Holidays appear as rows
with `HOLIDAY` and the holiday's name (e.g. `Christmas Day`). No legend, no
signatories.

## Goals

- Offices post and maintain their own activities; PALD can maintain all.
- Staff see the region's activities as a month calendar (grid), a month list
  in the sheet's layout, and a week at a glance on their dashboard.
- PALD prints and downloads the monthly sheet exactly in the reference
  layout, holidays included.
- Plan versus actual: each activity's status and history (moved from, why
  cancelled) is kept; unplanned activities are marked.
- Clash warnings for PALD and for the poster.
- Repeating activities (daily, weekly, monthly) are entered once.

## Non-goals (for now)

- No public page, no public feed, no email (IMIS sends none for HR either).
- No approval queue.
- No room/venue booking or resource reservation; venue is text.
- No attendance, registration or post-activity reports.
- No automatic import of activities held in other modules (L&D trainings,
  PROCTAD exam schedules, SALN deadlines). A later step may show them.
- No import of the 2025/2026 Google Sheets yet (a possible later step).

## Anti-goals

- A calendar that offices stop updating because posting is slow: posting
  must be a short form, and a month-only entry must be allowed.
- PALD becoming a bottleneck: nothing waits for PALD.
- A printout HR/PALD must retouch by hand: the sheet must match the
  reference without editing.
- Activities silently disappearing: removal by PALD is logged with a reason,
  and the office sees it.

## Constraints

- Laravel + Vue 3 + Inertia, JavaScript only; business logic in the backend;
  UI from the shared design system and `@cscro8/ui`; tokens only (dark
  theme); toasts for flash; icon row-action buttons; no left colour bars.
- Routes guarded by `permission:` middleware **and** a policy; new
  permissions in `App\Enums\PermissionName`.
- Every create/update/delete is audited (`AuditEvent`).
- Excel written with PhpSpreadsheet (installed 2026-11-27 with the user's approval);
  the older workbooks stay plain OOXML.
- What's New: one entry in the current month's draft (3.0.0-beta.2).

## Approaches considered

- **A. Activity records + generated month sheet (chosen).** Statuses,
  clashes, plan-vs-actual, repeats and PALD's view work naturally; the sheet
  is generated, so it always matches.
- **B. An editable monthly sheet inside IMIS.** Familiar, but it is the
  spreadsheet again; statuses, clash checks and repeats would be awkward.
- **C. Keep the Google Sheet; IMIS reads it.** No permissions, no audit, no
  clash checks, and a dependency on outside access.

## Design

### People and permissions

| Permission | Who | Can |
|------------|-----|-----|
| `activities.view` | every staff account (default role) | see the calendar, list and details |
| `activities.post` | each office's poster(s) | add/edit/cancel/mark done their **own office's** activities |
| `activities.manage` | PALD | everything above for **any** office; return or remove; PALD's "what changed" view; print/Excel |

Posters may also print/download. "Own office" = **exactly** the user's
`organizational_unit_id` — no inheritance either way. Today only three
units have a parent: Leyte I and Leyte II (under the plantilla-only FO
Leyte, which is never a posting office) and Western Leyte (under Biliran).
Each of these posts as itself: a Leyte I poster cannot post for Leyte II,
and Biliran's poster does not manage Western Leyte. Plantilla-only units
are not offered in the office list. RO divisions have no sub-units today.

**Seeding:** `activities.view` goes to the default `user` role. A new role
**"Calendar of Activities — PALD"** carries `activities.manage` (and view,
post); a new role **"Calendar of Activities — office poster"** carries
`activities.post` (and view). Admins assign people to them; nobody is
assigned at install.

**Bell notifications** (as the leave module does; no email): when PALD
returns or removes an activity, every user holding `activities.post` in
that office, plus the activity's creator, gets a bell notification.

### Data

`activities` (one row per occurrence):

- `organizational_unit_id` — the posting office (required).
- `title` (Activity), `responsible` (free text; defaults to the office's
  short name when blank), `participants` (text), `venue` (text),
  `mode` (`face_to_face` | `hybrid` | `online`), `remarks` (text, nullable).
- **When:** `year`, `month` (always set); `starts_on`, `ends_on`
  (nullable — null means "month only, date to follow"; multi-day allowed);
  `starts_at`, `ends_at` (nullable times; `ends_at` null prints
  "- onwards"); `all_day` boolean.
- `status`: `planned` (month only) · `scheduled` · `cancelled` · `done`.
  `cancel_reason` when cancelled. **"Moved" is not a status:** it is derived
  from `activity_moves` (an activity has moved if it has any move rows), so
  a moved activity that is later held counts as both moved and done.
  Status rules: giving a month-only activity its first date is
  *scheduling* (planned → scheduled; no move record, no reason). Changing
  the date or time of an activity that already has a date — through Edit
  or the Move action alike — records a move and requires a reason.
  Remarks prints "Moved from {old date}" for the latest move.
- `unplanned` boolean — set when added after the year's plan was closed
  (see below) or when the poster ticks it.
- `series_id` (nullable) — ties occurrences of a repeating activity.
- `created_by`, `updated_by`, timestamps, soft delete (PALD removal keeps
  the row; `removed_reason`).

`activity_moves` — history of date changes: `activity_id`, old and new
dates/times, `reason`, `moved_by`, `moved_at`. The latest move sets status
`moved` and Remarks shows "Moved from 3 Dec".

`activity_series` — the repeat rule: `frequency` (`daily` weekdays only |
`weekly` | `monthly`), `interval`, weekday or day-of-month / "nth weekday"
(e.g. every 1st Monday), `until` (date; at most the end of the year being
planned). There is no yearly repeat: a once-a-year activity is simply part
of each year's plan. Saving a series **creates each occurrence** in
`activities` (status `scheduled`). Editing the series asks: "this one only"
or "this and the following ones". "This and the following" regenerates
only future occurrences that are still untouched (scheduled, never moved,
never returned, not done or cancelled); an occurrence changed on its own is
**detached** from the series (`series_id` cleared) the moment it is moved,
returned or edited individually, so its history is never lost. Holidays
that are days off are skipped by default ("skip holidays" on the series,
on by default).

### The yearly plan

- The year's plan is open for entry at any time; PALD sets a **plan cut-off
  date** per year (a setting; default 31 January). Activities created after
  it are flagged `unplanned` automatically.
- An activity can be entered with only a month (`planned`). The month sheet
  lists these at the end under **"Dates to be announced"**.

### Clashes

Warned, never blocked. When saving, and in PALD's view, flag another
non-cancelled activity on an overlapping date and time with the same venue
(case-insensitive, trimmed) or the same posting office. Shown as a warning
the poster can accept.

### Screens

**For all staff** (portal, "Calendar of Activities" in the sidebar):

1. **Month calendar** — a month grid like the leave calendar: previous/next
   month, today highlighted, holidays shaded with their names, each day
   listing its activities (time + title, coloured by status using tokens,
   cancelled struck through). Filters: office (all / mine / a chosen one),
   mode. Clicking an activity opens its details.
2. **Month list** — the sheet's layout on screen (date rows, then seven
   columns), same filters; "Dates to be announced" at the end.
3. **Dashboard** — see "The Portal dashboard" below.

**For posters**: "Add activity" (short form: title, office preselected,
date or month only, time, mode, venue, participants, responsible, remarks,
repeat) and row actions on their office's activities: edit, move (asks new
date + reason), cancel (reason), mark done.

**For PALD** (Admin → "Calendar of Activities"): everything above for any
office, plus:
- **What changed** — activities added, moved, cancelled or removed since
  PALD last looked (or in the last 7/30 days), with who and when.
- **Clashes** for the month.
- **Plan vs actual** for a year by office: planned, done, cancelled, moved,
  unplanned counts.
- **Return** an activity to its office with a note (it stays visible,
  marked "Returned by PALD: …", until the office edits it) and **Remove**
  (with reason; the office sees it under "Removed").
- Settings: the plan cut-off date per year.

### The monthly sheet (print and Excel)

- Title: `CSC RO VIII CALENDAR OF ACTIVITIES for DECEMBER 2025`
  (month in capitals).
- The seven columns above; date rows `1 December 2025 (Monday)`; holidays
  that are days off as `HOLIDAY` rows with their names (from
  `HolidayCalendar`); multi-day activities appear under each day.
- Date/Time text: `8:00 AM - 10:00 AM`; noon as `12:00 NN`; no end →
  `- onwards`; all-day → `Whole day`.
- Mode text: `Face-to-face`, `Hybrid`, `Online`.
- Remarks: the office's remarks, plus `Moved from …`, `Cancelled: reason`,
  `Unplanned`. Cancelled activities print with their remark (not hidden).
- "Dates to be announced" section last.
- **Font: Poppins**, the system's font (the user's decision) — not the
  reference workbook's font. The Excel file names Poppins too.
- **Column widths are IMIS's own** (the user's decision), chosen for
  readability rather than copied: on landscape, roughly Date/Time 13%,
  Mode 9%, Venue 17%, Participants 14%, Activity 25%, Responsible 12%,
  Remarks 10%; text wraps, rows grow; date rows span all columns with a
  light token background; the header row repeats on every printed page.
  The reference workbook only fixes the title, the column order and
  headings, and the date/holiday row pattern.
- Landscape, A4/Folio per the print conventions; Excel one sheet per month,
  or a whole year as one workbook with one tab per month (like the
  reference file).
- Filter by office applies to the print too (an office can print its own).

### The Portal dashboard (approved with mockups, 2026-11-27)

Approved mockup: `.superpowers/brainstorm/1759-1795747958/content/dashboard-full-E.html`
(local only, not committed). Everything on today's dashboard stays, in the
same order; three things change.

1. **Welcome banner, layout "E".** `WelcomeBanner.vue` keeps its content
   (greeting with division badge, system title with version badge, welcome
   message, navy with the facade photo tint and orbit circles) but the text
   is **left-aligned** and a row of **round shortcut icons with labels**
   sits at the bottom right, like a dock: **File Leave**
   (`/my/leave/apply`), **My DTR** (`/my/dtr`), **My Payslip**
   (`/my/payslips`), **My PDS** (`/my/pds`), **Calendar of Activities**.
   The same five for everyone (the user's decision); a shortcut is hidden
   when the user cannot open that page (e.g. no employee record). Icons from
   the `@cscro8/ui` icon set (the mockup's emoji are stand-ins). On small
   screens the row moves below the text and may scroll sideways. Choosing
   your own shortcuts is a later addition, not part of this build.
2. **At a glance** gains a tile: **"Activities · this week"** (count of
   non-cancelled activities in the next 7 days, links to the calendar).
3. **New "Calendar of Activities" section right after the At a glance
   tiles** (before Announcements; the user's decision), loaded deferred
   like At a glance, with a skeleton:
   - left (wider): **"Coming up in the region · next 7 days"** — date chip
     (today highlighted), title, tags ("Your office", "Moved from …",
     "Cancelled"), time · mode · venue, responsible · participants; holidays
     inline; at most six items; click opens the details;
   - right: a **month calendar** — dots on days with activities, holidays
     in red, today highlighted, ‹ › to change month, clicking a day lists
     its activities;
   - "Open calendar ›" links to the full calendar. Hidden when there is
     nothing in the next 7 days and no activities this month.

Unchanged: Announcements, the **System Directory** (search, division cards
with the navy "View Systems (n)" buttons, system cards with "Access
System"), Services, the install offer, Getting Started, the maintenance
notice and the What's New pop-up. The sidebar gets "Calendar of
Activities" under Announcements.

### Audit events

`ActivitySaved`, `ActivityMoved`, `ActivityCancelled`, `ActivityReturned`,
`ActivityRemoved`, `ActivitySeriesSaved`.

### Release notes

One feature entry in the 3.0.0-beta.2 What's New draft.

## Left to the builder

- Exact route paths and controller split (portal vs admin), following the
  existing leave calendar's split.
- Whether to extract the leave calendar's month grid into a shared
  component (preferred, so both look the same) or build a sibling.
- Status colours (tokens only, with dark values).
- Seeding: give `activities.view` to the default staff role; who gets
  `activities.post` per office is set by admins in roles.

## Calls made by the designer

- Permissions split into view / post (own office) / manage (PALD).
- Repeats create real occurrences (each can be moved, cancelled or done on
  its own); daily repeats are weekdays only; holidays skipped by default.
- Plan cut-off date (default 31 January) decides "unplanned" automatically.
- Clashes warn, never block; matched by venue or posting office.
- Cancelled activities stay on the sheet with a remark.
- PALD's "Return" keeps the entry visible; "Remove" is a soft delete with a
  reason the office can see.
- "Own office" is the user's exact unit (Leyte I, Leyte II and Western
  Leyte each post as themselves).
- Two seeded roles (PALD curator, office poster); nobody assigned at install.
- "Moved" is derived from move history, not a status; first dating a
  month-only entry is not a move; any later date change needs a reason.
- No yearly repeat; individually changed occurrences detach from their
  series.
- Bell notifications to the office's posters and the creator on Return
  and Remove.
