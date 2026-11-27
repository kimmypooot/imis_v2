# Calendar of Activities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A region-wide Calendar of Activities in IMIS — offices post, PALD curates, staff see it as a month grid, a sheet-style list and on a revamped Portal dashboard; the monthly "CSC RO VIII CALENDAR OF ACTIVITIES for {MONTH} {YEAR}" prints and downloads as Excel.

**Architecture:** Activities are records (one row per occurrence) owned by an organizational unit. Business rules live in `app/Support/Activities/*` services (status/move rules, series generation, clashes, the month sheet); controllers stay thin; Vue pages render props. Holidays come from the existing `App\Support\HR\Leave\HolidayCalendar`. Excel is written with PhpSpreadsheet (`phpoffice/phpspreadsheet` ^5.12, installed with the user's approval on 2026-11-27).

**Tech Stack:** Laravel (PHP 8.4), PHPUnit, Inertia v3 + Vue 3 (JavaScript), Tailwind v4 via `@cscro8/ui` tokens/components.

**Spec:** `docs/superpowers/specs/2026-11-27-calendar-of-activities-design.md` (approved 2026-11-27). Approved dashboard mockup: `.superpowers/brainstorm/1759-1795747958/content/dashboard-full-E.html` (local only).

## Global Constraints

- JavaScript only; no TypeScript. No new dependencies beyond `phpoffice/phpspreadsheet` (already installed).
- Controllers thin; logic in `app/Support/Activities/`.
- Every route: `permission:` middleware **and** a policy check. Permissions in `App\Enums\PermissionName`: `activities.view`, `activities.post`, `activities.manage`.
- Every create/update/move/cancel/done/return/remove is audited via `$this->audit()->record(...)` with new `AuditEvent` cases.
- UI: `@cscro8/ui` components and tokens only (dark theme must work), toasts for flash, icon row-action buttons, no coloured left-edge bars.
- Font everywhere, including print and Excel: **Poppins**.
- Sheet title exactly: `CSC RO VIII CALENDAR OF ACTIVITIES for {MONTH} {YEAR}` with the month in capitals, e.g. `CSC RO VIII CALENDAR OF ACTIVITIES for DECEMBER 2025`.
- Sheet columns exactly, in order: `Date/Time`, `Mode`, `Venue`, `Participants`, `Activity`, `Responsible Office/Personnel/ Committee`, `Remarks`.
- Mode labels exactly: `Face-to-face`, `Hybrid`, `Online`.
- No email is sent (bell notifications only). Internal staff only; no public route.
- Migrations: use the next free date after the newest file in `database/migrations` (at writing, `2027_04_14_*`; this plan uses `2027_04_20_*` — bump if taken).
- Run `vendor/bin/pint --dirty --format agent` after PHP changes; `npm run build` after Vue changes.
- Commit only when the user asks (project rule); "Commit" steps below mean: stage and leave a ready commit message, then commit if the user has authorised commits for this work.

## Review Focus

1. **Activity spanning a month boundary** (30 Nov – 2 Dec): it must appear on each of its days in both months' grids and sheets. → test in Task 6.
2. **Poster whose account has no office** (`organizational_unit_id` null): posting is refused with a validation message, never a 500. → test in Task 5.
3. **Monthly series on the 31st or the 5th Monday**: months without that day are skipped, never rolled into the next month. → test in Task 3.
4. **Changing only the time of a dated activity** counts as a move (reason required); giving a month-only activity its first date does not. → tests in Task 2.
5. **"This week" and "today" use the app timezone** (Asia/Manila), and cancelled activities are excluded from the tile count but listed (struck through) in "Coming up". → tests in Task 9.

---

### Task 1: Permissions, roles and the data model

**Files:**
- Modify: `app/Enums/PermissionName.php` (add `ActivitiesView = 'activities.view'`, `ActivitiesPost = 'activities.post'`, `ActivitiesManage = 'activities.manage'`)
- Create: `database/seeders/ActivityRoleSeeder.php`; Modify: `database/seeders/DatabaseSeeder.php` (call it after `RolePermissionSeeder`)
- Create migration `2027_04_20_090000_create_activities_tables.php` (tables below)
- Create migration `2027_04_20_090100_grant_activity_permissions.php` (creates the three permission rows if missing, gives `activities.view` to role `user` (`RoleName::User`), creates the two roles via `ActivityRoleSeeder::roles()` if missing, `PermissionCache::flush()`; pattern: `2027_04_01_090001_grant_salary_notices_to_hr_staff.php`)
- Create: `app/Enums/ActivityMode.php` (`FaceToFace='face_to_face'`, `Hybrid='hybrid'`, `Online='online'`; `label()` → the exact mode labels), `app/Enums/ActivityStatus.php` (`Planned`, `Scheduled`, `Cancelled`, `Done`; `label()`), `app/Enums/SeriesFrequency.php` (`Daily`, `Weekly`, `Monthly`)
- Create: `app/Models/Activity.php`, `app/Models/ActivityMove.php`, `app/Models/ActivitySeries.php`, `app/Models/ActivityPlanCutoff.php`, `database/factories/ActivityFactory.php`, `database/factories/ActivitySeriesFactory.php`
- Modify: `app/Enums/AuditEvent.php` (add `ActivitySaved='activity.saved'`, `ActivityMoved='activity.moved'`, `ActivityCancelled='activity.cancelled'`, `ActivityDone='activity.done'`, `ActivityReturned='activity.returned'`, `ActivityRemoved='activity.removed'`, `ActivitySeriesSaved='activity.series_saved'`, `ActivityCutoffSaved='activity.cutoff_saved'`)
- Modify: `app/Providers/AppServiceProvider.php` morph map (`'activity' => Activity::class`)
- Test: `tests/Feature/Activities/ActivitySetupTest.php`

**Tables:**
- `activities`: `id`, `organizational_unit_id` (fk, restrict), `title` string, `responsible` string nullable, `participants` string nullable, `venue` string nullable, `mode` string(20), `remarks` text nullable, `year` smallint, `month` tinyint, `starts_on` date nullable, `ends_on` date nullable, `starts_at` time nullable, `ends_at` time nullable, `all_day` bool default false, `status` string(20), `cancel_reason` string nullable, `unplanned` bool default false, `series_id` fk nullable (null on delete), `returned_note` string nullable, `returned_at` timestamp nullable, `removed_reason` string nullable, `created_by`/`updated_by` fk users nullable, timestamps, softDeletes. Indexes: (`year`,`month`), `starts_on`, `ends_on`, `organizational_unit_id`.
- `activity_moves`: `id`, `activity_id` fk cascade, `from_starts_on`, `from_ends_on`, `from_starts_at` nullable, `from_ends_at` nullable, `to_starts_on`, `to_ends_on`, `to_starts_at` nullable, `to_ends_at` nullable, `reason` string, `moved_by` fk users nullable, `created_at`.
- `activity_series`: `id`, `organizational_unit_id` fk, `frequency` string(20), `interval` tinyint default 1, `weekday` tinyint nullable (ISO 1–7), `day_of_month` tinyint nullable, `nth_weekday` tinyint nullable (1–5), `starts_on` date, `until` date, `skip_holidays` bool default true, `template` json (title, responsible, participants, venue, mode, remarks, starts_at, ends_at, all_day), `created_by` fk nullable, timestamps.
- `activity_plan_cutoffs`: `year` smallint primary, `cuts_off_on` date, `updated_by` fk nullable, timestamps.

**Roles (`ActivityRoleSeeder::roles()`, same shape as `HrRoleSeeder::roles()`):**
- `activities-curator` — "Calendar of Activities — PALD": `ActivitiesView`, `ActivitiesPost`, `ActivitiesManage`.
- `activities-poster` — "Calendar of Activities — office poster": `ActivitiesView`, `ActivitiesPost`.

**Interfaces — Produces:**
- `Activity` casts: `mode` → `ActivityMode`, `status` → `ActivityStatus`, `starts_on`/`ends_on` → `date:Y-m-d`, `all_day`/`unplanned` → bool. Relations: `office()` (`organizational_unit_id`), `moves()` (latest first), `series()`, `creator()`. Scopes: `inMonth(int $year, int $month)` (dated rows overlapping the month **or** month-only rows with that year/month), `between(Carbon $from, Carbon $to)` (dated rows overlapping the range), `notCancelled()`. Accessor `hasMoved` (bool, from `moves`).
- `ActivityPlanCutoff::for(int $year): Carbon` — the stored date or `{year}-01-31`.

- [ ] **Step 1: Write the failing tests** — `test_staff_role_can_view_activities` (a user with role `user` has `activities.view` after migrations), `test_curator_and_poster_roles_exist_with_their_permissions`, `test_in_month_includes_month_only_and_overlapping_dated_activities` (factory rows: month-only Dec 2025; 30 Nov–2 Dec; 5 Jan 2026 → `inMonth(2025, 12)` returns the first two), `test_plan_cutoff_defaults_to_january_31`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Activities/ActivitySetupTest.php` — expect FAIL (classes missing).
- [ ] **Step 3: Implement** the enums, migrations, models, factories, seeder, audit cases and morph map above.
- [ ] **Step 4: Run** the test file — expect PASS. Run `php artisan migrate` locally.
- [ ] **Step 5: Commit** — `Add Calendar of Activities permissions, roles and tables`

---

### Task 2: Posting rules — save, schedule, move, cancel, done

**Files:**
- Create: `app/Support/Activities/Activities.php`
- Test: `tests/Feature/Activities/ActivityRulesTest.php`

**Interfaces:**
- Consumes: Task 1 models/enums.
- Produces (all return the fresh `Activity`, all run in a transaction, none audits — controllers audit):
  - `save(array $data, User $by, ?Activity $activity = null, ?string $moveReason = null): Activity` — `$data` keys: `organizational_unit_id, title, responsible, participants, venue, mode, remarks, year, month, starts_on, ends_on, starts_at, ends_at, all_day, unplanned`.
  - `move(Activity $activity, array $when, string $reason, User $by): Activity` — `$when` keys `starts_on, ends_on, starts_at, ends_at, all_day`.
  - `cancel(Activity $activity, string $reason, User $by): Activity`
  - `markDone(Activity $activity, User $by): Activity`
  - `MoveReasonRequired` exception (`App\Support\Activities\MoveReasonRequired extends \DomainException`).

**Rules (from the spec):**
- No `starts_on` → status `planned`, `year`/`month` from data. With `starts_on` → `scheduled`; `year`/`month` derived from `starts_on`; `ends_on` defaults to `starts_on`.
- `save()` on an existing activity that already had a date, changing any of `starts_on/ends_on/starts_at/ends_at/all_day` → records an `ActivityMove` (old → new) and needs `$moveReason`, else throws `MoveReasonRequired`. Planned → first date: no move, no reason.
- `unplanned` is true when the user ticks it **or** the activity is created after `ActivityPlanCutoff::for($year)`; never cleared automatically.
- Editing, moving or returning an occurrence that has `series_id` sets `series_id` to null (detach).
- `cancel` keeps dates, sets `cancel_reason`. `markDone` only for dated activities (throws `\DomainException` for month-only).
- `title` and `mode` required; `ends_on >= starts_on`; `ends_at > starts_at` when both on the same day.

- [ ] **Step 1: Write the failing tests:**
  - `test_month_only_activity_is_planned` (status `planned`, `starts_on` null)
  - `test_first_date_schedules_without_a_move` (planned → save with `starts_on` 2025-12-03 → `scheduled`, `moves()->count() === 0`)
  - `test_changing_a_date_records_a_move_and_needs_a_reason` (expects `MoveReasonRequired` without reason; with reason one `ActivityMove` from 2025-12-02 to 2025-12-04)
  - `test_changing_only_the_time_is_a_move` (13:30 → 14:00 same day → one move) *(Review Focus 4)*
  - `test_created_after_cutoff_is_unplanned` (travel to 2026-02-10, year 2026 → `unplanned` true; travel to 2026-01-15 → false)
  - `test_editing_an_occurrence_detaches_it_from_its_series`
  - `test_cancel_keeps_dates_and_reason`
  - `test_moved_then_done_counts_as_both` (`hasMoved` true and status `done`)
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Activities/ActivityRulesTest.php` — FAIL.
- [ ] **Step 3: Implement** `App\Support\Activities\Activities` per the rules.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `Add the Calendar of Activities posting rules`

---

### Task 3: Repeating activities

**Files:**
- Create: `app/Support/Activities/ActivitySeriesBuilder.php`
- Test: `tests/Feature/Activities/ActivitySeriesTest.php`

**Interfaces:**
- Consumes: `Activities::save()` (Task 2), `HolidayCalendar::between()`.
- Produces:
  - `create(array $rule, array $template, int $officeId, User $by): ActivitySeries` — `$rule` keys `frequency, interval, weekday, day_of_month, nth_weekday, starts_on, until, skip_holidays`. `until` is capped at 31 Dec of `starts_on`'s year. Creates one `scheduled` activity per occurrence date.
  - `occurrences(ActivitySeries $series): Collection<int, Carbon>` — pure date list (used by `create` and `updateFollowing`).
  - `updateFollowing(ActivitySeries $series, Carbon $from, array $rule, array $template, User $by): ActivitySeries` — deletes and regenerates only the series' activities dated `>= $from` that are still untouched (`series_id` set, status `scheduled`, no moves, `returned_at` null); touched ones were already detached by Task 2.

**Date rules:** `daily` = weekdays only (Mon–Fri), every `interval` days; `weekly` = on `weekday` every `interval` weeks; `monthly` = `day_of_month` **or** `nth_weekday`+`weekday` (e.g. 1st Monday) every `interval` months; a month without that day (31st, 5th Monday) is **skipped** *(Review Focus 3)*; when `skip_holidays`, drop dates where `HolidayCalendar` has a day-off holiday.

- [ ] **Step 1: Write the failing tests:**
  - `test_weekly_series_creates_each_occurrence_to_until` (every Monday 2026-01-05 → 2026-01-26 = 4)
  - `test_daily_series_skips_weekends`
  - `test_monthly_on_the_31st_skips_short_months` (Jan–Jun 2026 → Jan 31, Mar 31, May 31)
  - `test_fifth_monday_skips_months_without_one`
  - `test_holidays_are_skipped_by_default` (repeating holiday on 2026-06-12, weekly Friday series → no 2026-06-12 occurrence)
  - `test_until_is_capped_at_year_end`
  - `test_update_following_keeps_moved_and_detached_occurrences`
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Activities/ActivitySeriesTest.php` — FAIL.
- [ ] **Step 3: Implement** `ActivitySeriesBuilder`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `Add repeating activities to the Calendar of Activities`

---

### Task 4: Clashes

**Files:**
- Create: `app/Support/Activities/ActivityClashes.php`
- Test: `tests/Feature/Activities/ActivityClashesTest.php`

**Interfaces:**
- Produces:
  - `for(array $data, ?int $ignoreId = null): Collection<int, Activity>` — other non-cancelled dated activities whose date range overlaps and, when both have times, whose times overlap (all-day overlaps everything that day), **and** same venue (`mb_strtolower(trim())`, non-empty) **or** same `organizational_unit_id`.
  - `forMonth(int $year, int $month): Collection<int, array{activity: Activity, with: Collection<int, Activity>}>` — each clashing pair once.

- [ ] **Step 1: Write the failing tests:** `test_same_venue_overlapping_time_clashes`, `test_same_venue_different_time_does_not_clash`, `test_same_office_same_day_all_day_clashes`, `test_cancelled_activities_never_clash`, `test_venue_match_ignores_case_and_spaces`, `test_for_month_lists_each_pair_once`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Activities/ActivityClashesTest.php` — FAIL.
- [ ] **Step 3: Implement** `ActivityClashes`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `Warn about clashing activities`

---

### Task 5: Policy, controllers, routes and bell notifications

**Files:**
- Create: `app/Policies/ActivityPolicy.php` (register like the other policies)
- Create: `app/Http/Controllers/ActivityController.php`, `app/Http/Requests/Activities/SaveActivityRequest.php`
- Create: `app/Notifications/ActivityUpdate.php` (copy of `HrUpdate`, `KIND = 'activities'`); Modify: `app/Support/Notifications/NotificationPreferences.php` (add `'activities' => ['label' => 'Calendar of Activities', 'description' => 'When PALD returns or removes an activity your office posted.', 'email' => false, 'permission' => 'activities.post']`)
- Modify: `routes/portal.php` (inside the `auth` group)
- Test: `tests/Feature/Activities/ActivityHttpTest.php`

**Policy:**
- `viewAny(User)`: `activities.view`.
- `create(User)`: `activities.post` or `activities.manage`.
- `update(User, Activity)`: `activities.manage`, or `activities.post` and `$activity->organizational_unit_id === $user->organizational_unit_id` (exact unit; no parent/child inheritance).
- `manage(User)`: `activities.manage`.
- Posting offices offered in the form: all units except plantilla-only ones (`plantilla_only` true) for `manage`; only the user's own unit for posters.

**Routes** (`permission:activities.view` group; write routes add `permission:activities.post|activities.manage` as the project's middleware syntax allows — check `app/Http/Middleware` for the multi-permission form):
- `GET activities` → `index` (query `month=YYYY-MM`, `view=grid|list`, `office`, `mode`) — Inertia `Activities/Index`
- `GET activities/{activity}` → `show` (JSON for the details modal, or Inertia partial)
- `POST activities` → `store`; `PUT activities/{activity}` → `update` (accepts `move_reason`, `scope=this|following` for series)
- `POST activities/{activity}/move` → `move`; `POST activities/{activity}/cancel` → `cancel`; `POST activities/{activity}/done` → `done`
- `POST activities/{activity}/return` → `returnToOffice` (manage); `DELETE activities/{activity}` → `remove` (manage; soft delete with `removed_reason`)
- Store with a `repeat` block calls `ActivitySeriesBuilder::create`.

**Return / Remove:** Return sets `returned_note` and `returned_at` and clears `series_id` (the activity stays visible); the office's next save of that activity clears `returned_note`/`returned_at`. Remove sets `removed_reason` then soft-deletes.

**Placement call:** PALD's screens live in the portal (`/activities`, `/activities/manage`), not under Admin, so posters and PALD need no admin-area access; the spec left the portal/admin split to the builder.

**Behaviour:** controllers call the Task 2–4 services, audit each action with the Task 1 events, flash a toast message, and on save return clash warnings (`session('clashes')` list of titles/dates) without blocking. `MoveReasonRequired` → validation error on `move_reason`. Return and Remove notify (bell) every active user with `activities.post` in that office plus the creator (deduplicated), `url` `/activities?month=YYYY-MM`.

- [ ] **Step 1: Write the failing tests:**
  - `test_staff_can_view_but_not_post`
  - `test_poster_posts_for_their_own_office_only` (other office → 403)
  - `test_poster_without_an_office_gets_a_validation_error` *(Review Focus 2)*
  - `test_leyte_i_poster_cannot_edit_leyte_ii_activity` (units `LFO-I`/`LFO-II` from `RegionalOfficeSeeder`)
  - `test_curator_can_edit_any_office_and_return_and_remove`
  - `test_return_and_remove_notify_the_offices_posters_and_creator` (`Notification::fake()`; asserts `ActivityUpdate` to both, once each)
  - `test_moving_without_a_reason_is_a_validation_error`
  - `test_saving_a_clash_warns_but_saves`
  - `test_each_action_is_audited` (one `audit_logs` row per action with the expected event)
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Activities/ActivityHttpTest.php` — FAIL.
- [ ] **Step 3: Implement** policy, request, controller, routes, notification, preference kind.
- [ ] **Step 4: Run** — PASS; then `php artisan route:list --path=activities` shows the routes with their middleware.
- [ ] **Step 5: Commit** — `Let offices post activities and PALD curate them`

---

### Task 6: The month sheet (data, print page, Excel)

**Files:**
- Create: `app/Support/Activities/MonthSheet.php`, `app/Support/Activities/ActivityWorkbook.php`
- Create: `app/Http/Controllers/ActivitySheetController.php`; Modify: `routes/portal.php` (`GET activities/sheet/print`, `GET activities/sheet/excel` — `activities.view`)
- Create: `resources/js/Pages/Activities/SheetPrint.vue` (no app layout, like `Pages/HR/ServiceRecordPrint.vue`)
- Test: `tests/Feature/Activities/MonthSheetTest.php`

**Interfaces:**
- Produces:
  - `MonthSheet::build(int $year, int $month, ?int $officeId = null): array{title: string, days: array<int, array{label: string, holiday: ?string, rows: array<int, array{time: string, mode: string, venue: string, participants: string, activity: string, responsible: string, remarks: string, cancelled: bool}>}>, tba: array<int, array{...same row shape}>}`
  - `MonthSheet::timeText(Activity $a): string`, `MonthSheet::remarksText(Activity $a): string` (public static, tested directly)
  - `ActivityWorkbook::build(array $sheets): string` — `$sheets` is a list of `MonthSheet::build()` results; one worksheet per month, tab name = month name (`December`); built with PhpSpreadsheet (`PhpOffice\PhpSpreadsheet\Spreadsheet`, `PhpOffice\PhpSpreadsheet\Writer\Xlsx` saved to `php://memory`); returns XLSX bytes.
  - Controller: `print` (query `month`, `office`) → Inertia `Activities/SheetPrint` with `sheet`; `excel` (query `month` **or** `year`, `office`) → download `CSC RO VIII Calendar of Activities - {Month} {Year}.xlsx` (or `- {Year}.xlsx` for a whole year).

**Formatting (exact, from the spec):**
- Title: `CSC RO VIII CALENDAR OF ACTIVITIES for DECEMBER 2025`.
- Day label: `1 December 2025 (Monday)`; one day entry per date that has activities or a day-off holiday; multi-day activities appear under each of their days *(Review Focus 1)*; rows sorted by start time, all-day first.
- Holiday row: `time` = `HOLIDAY`, `activity` = holiday name, other cells empty.
- `timeText`: `8:00 AM - 10:00 AM`; 12:00 PM shown as `12:00 NN`; no end → `1:30 PM - onwards`; all-day → `Whole day`.
- `mode`: `ActivityMode::label()`. `responsible`: the field, or the office's `short_name` when blank.
- `remarksText`: the office's remarks, then `Moved from 2 December` (latest move's old start), `Cancelled: {reason}`, `Unplanned`, joined with `; `. Cancelled rows stay, flagged `cancelled` (struck through in print).
- `tba`: month-only (planned) activities, listed under **"Dates to be announced"** at the end.
- Print page: landscape, Poppins, header row repeats on each page (`thead { display: table-header-group }`), column widths Date/Time 13%, Mode 9%, Venue 17%, Participants 14%, Activity 25%, Responsible 12%, Remarks 10%; date rows span all columns on a light token background; text wraps.
- Workbook (PhpSpreadsheet): same columns and proportions, default font Poppins (`getDefaultStyle()->getFont()->setName('Poppins')`), title row merged across A–G, date rows merged with a light fill, wrap text, header row repeated (`setRowsToRepeatAtTopByStartAndEnd`), landscape, fit to one page wide.

- [ ] **Step 1: Write the failing tests:**
  - `test_title_and_day_labels` (asserts the exact title and `3 December 2025 (Wednesday)`)
  - `test_activity_across_a_month_boundary_appears_in_both_months` *(Review Focus 1)*
  - `test_holiday_rows_come_from_holiday_calendar` (repeating Christmas → `HOLIDAY` / `Christmas Day` under `25 December 2025 (Thursday)`)
  - `test_time_text_formats` (`8:00 AM - 10:00 AM`, `12:00 NN - 1:00 PM`, `1:30 PM - onwards`, `Whole day`)
  - `test_remarks_text_combines_moved_cancelled_unplanned`
  - `test_month_only_activities_are_listed_as_dates_to_be_announced`
  - `test_office_filter_limits_rows`
  - `test_excel_download_is_an_xlsx_with_the_title` (response `Content-Type` `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`; unzip `xl/sharedStrings.xml` or the sheet XML contains the title and `Poppins` appears in `xl/styles.xml`)
  - `test_print_page_renders_the_sheet` (`assertInertia` component `Activities/SheetPrint`, `sheet.title`)
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Activities/MonthSheetTest.php` — FAIL.
- [ ] **Step 3: Implement** `MonthSheet`, `ActivityWorkbook`, the controller, routes and `SheetPrint.vue`.
- [ ] **Step 4: Run** — PASS. `npm run build` succeeds.
- [ ] **Step 5: Commit** — `Print and download the monthly Calendar of Activities`

---

### Task 7: The calendar page (grid, list, forms)

**Files:**
- Create: `resources/js/Components/Calendar/MonthGrid.vue` — props `month` (`YYYY-MM`), `holidays` (`[{date,name,day_off}]`), `selected` (date string), `today`; emits `select(date)`; default slot `#day="{ date, iso }"`. Markup and classes copied from the table in `resources/js/Pages/Portal/LeaveCalendar.vue` (weeks from Sunday, weekend shading, today ring, holiday chip with tooltip) so both calendars look the same. Leave calendars are **not** refactored in this plan.
- Create: `resources/js/Pages/Activities/Index.vue` (PortalLayout): PageHeader with month ‹ ›, view toggle **Calendar / List**, filters (office: all / mine / one; mode), buttons **Add activity** (when `canPost`), **Print** and **Excel** (links to Task 6 routes with the current filters).
  - Calendar view: `MonthGrid`; each day lists activities as `time · title` chips (status via `StatusBadge` tones: scheduled `info`, done `success`, cancelled struck-through `draft`, returned `warning`); "+n more" → day list; click → details modal.
  - List view: the sheet layout on screen (same seven columns, date rows, "Dates to be announced").
  - Details modal: all fields, office, status, move history, returned note; row actions (icon buttons) by permission: Edit, Move, Cancel, Mark done; PALD also Return, Remove.
- Create: `resources/js/Components/Activities/ActivityForm.vue` (modal form: office select (only own unit for posters), title, date **or** month only, time / whole day, mode, venue, participants, responsible, remarks, unplanned checkbox, repeat section: none/daily/weekly/monthly + options + until + skip holidays) and `MoveActivityModal.vue`, `ReasonModal.vue` (cancel/return/remove).
- Modify: `ActivityController@index` (Task 5) to pass: `month`, `view`, `filters`, `activities` (for the month, shaped for the grid and list), `sheet` (from `MonthSheet::build` for list view), `holidays` (`HolidayCalendar::between` mapped like `LeaveCalendar::holidays()`), `offices` (filter options), `postOffices` (form options), `canPost`, `canManage`, `clashes` flash.
- Modify: `resources/js/Utils/navigation.js` — portal item `{ label: 'Calendar of Activities', href: '/activities', icon: 'calendar', permission: 'activities.view' }` right after Announcements.
- Test: extend `tests/Feature/Activities/ActivityHttpTest.php`

- [ ] **Step 1: Write the failing tests:** `test_index_props_for_the_month` (`assertInertia`: component `Activities/Index`, `activities` count for Dec 2025, `holidays` contains `2025-12-25`, `canPost` false for plain staff), `test_list_view_includes_the_sheet`, `test_office_filter_mine`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the props, components and page.
- [ ] **Step 4: Run** tests — PASS; `npm run build` succeeds; open `/activities` in the browser, add a month-only, a dated and a weekly activity, move one, cancel one, switch to List, Print and Excel — all work, dark theme readable.
- [ ] **Step 5: Commit** — `Add the Calendar of Activities page`

---

### Task 8: PALD's management page

**Files:**
- Create: `app/Http/Controllers/ActivityManageController.php`, `app/Support/Activities/PlanVersusActual.php`
- Create: `resources/js/Pages/Activities/Manage.vue` (PortalLayout)
- Modify: `routes/portal.php` (`GET activities/manage`, `PUT activities/cutoff` — `permission:activities.manage` + `ActivityPolicy::manage`); `navigation.js` portal **Management** group item `{ label: 'Manage Calendar of Activities', href: '/activities/manage', icon: 'clipboard', permission: 'activities.manage' }`
- Test: `tests/Feature/Activities/ActivityManageTest.php`

**Interfaces:**
- `PlanVersusActual::for(int $year): Collection<int, array{office: string, planned: int, done: int, cancelled: int, moved: int, unplanned: int, open: int}>` — counts per office for the year; `moved` = activities with moves; `planned` = all non-unplanned activities of the year; `open` = not done, not cancelled.
- Page sections (cards): **What changed** (audit rows for `activity.*` events in the last 7 or 30 days — toggle — with who, when, office, link), **Clashes** for a chosen month (`ActivityClashes::forMonth`), **Plan vs actual** table for a chosen year, **Plan cut-off** (date input per year; audited `ActivityCutoffSaved`), **Removed** list (soft-deleted, with reason).

- [ ] **Step 1: Write the failing tests:** `test_only_curators_open_manage` (poster → 403), `test_plan_versus_actual_counts` (fixture: 2 planned done, 1 moved+done, 1 cancelled, 1 unplanned → exact counts), `test_what_changed_lists_recent_activity_events`, `test_cutoff_is_saved_and_audited`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Activities/ActivityManageTest.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; `npm run build`.
- [ ] **Step 5: Commit** — `Add PALD's Calendar of Activities management page`

---

### Task 9: The Portal dashboard (banner E, tile, Calendar of Activities section)

**Files:**
- Modify: `resources/js/Components/Portal/WelcomeBanner.vue` — text **left-aligned**; new prop `shortcuts` (`[{key,label,href,icon}]`); a right-hand "dock" of round icon buttons with labels at the bottom right (44px circles, `bg-white/15`, `border-white/25`, label under, `aria-label`), wrapping below the text on small screens (horizontal scroll if needed). Keep photo tint, `OrbitCircles`, greeting/division badge, title/version badge, message.
- Create: `app/Support/Portal/QuickAccess.php` — `for(User $user): array<int, array{key: string, label: string, href: string, icon: string}>` returning, in this order and only when the user may open the page: File Leave `/my/leave/apply`, My DTR `/my/dtr`, My Payslip `/my/payslips`, My PDS `/my/pds`, Calendar of Activities `/activities`. Use the same checks those routes' middleware/policies use (read each route's middleware with `php artisan route:list --path=my`).
- Create: `app/Support/Activities/UpcomingActivities.php` — `for(User $user, ?Carbon $now = null): array{items: array<int, array>, month: array, count: int}`: next 7 days from today in the app timezone (`now()->timezone(config('app.timezone'))`), at most 6 items incl. day-off holidays, each with date, `today` flag, title, time text, mode, venue, responsible, participants, tags (`mine`, `moved_from`, `cancelled`); `month` = days of the current month that have activities and holidays (for the mini calendar); `count` = non-cancelled activities in the next 7 days *(Review Focus 5)*.
- Modify: `app/Support/Portal/AtAGlance.php` — tile `{key:'activities', label:'Activities', value: count, hint:'this week', href:'/activities', icon:'calendar', tone:'success'}` when the user has `activities.view`.
- Modify: `app/Http/Controllers/PortalController.php` — props `shortcuts` (`QuickAccess`), `activities` as `Inertia::defer(...)` (`UpcomingActivities`, null without `activities.view`).
- Create: `resources/js/Components/Portal/UpcomingActivities.vue` — section titled **Calendar of Activities** with "Open calendar ›"; left (wider) "Coming up in the region · next 7 days" list (date chip, today highlighted, tags "Your office", "Moved from …", "Cancelled" struck through, holidays inline, click → `/activities?month=…`); right a mini month calendar (dots for activity days, holidays in danger tokens, today highlighted, ‹ › month via partial reload of `activities` with `month` query). Skeleton fallback via `<Deferred data="activities">`. Hidden when no items and no activity days.
- Modify: `resources/js/Pages/Portal/Dashboard.vue` — pass `shortcuts` to `WelcomeBanner`; insert `UpcomingActivities` **right after the At a glance block and before the maintenance notice/Announcements**. Everything else unchanged (System Directory and its "View Systems (n)" / "Access System" buttons untouched).
- Test: `tests/Feature/Portal/DashboardActivitiesTest.php`

- [ ] **Step 1: Write the failing tests:**
  - `test_shortcuts_follow_access` (user without employee record lacks File Leave/My DTR/My Payslip/My PDS as their routes require; has Calendar of Activities with `activities.view`)
  - `test_upcoming_lists_next_seven_days_in_manila_time` (travel to `2025-12-02 23:30:00` Asia/Manila → items start on 2025-12-02 and stop at 2025-12-08) *(Review Focus 5)*
  - `test_cancelled_shown_but_not_counted` (2 scheduled + 1 cancelled → `count` 2, 3 items, one with `cancelled` tag) *(Review Focus 5)*
  - `test_glance_has_activities_tile`
  - `test_dashboard_defers_activities` (`assertInertia` has `shortcuts`; `activities` is deferred — load with the deferred partial reload header as other dashboard tests do for `glance`)
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Portal/DashboardActivitiesTest.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new test file and `tests/Feature/Portal` — PASS; `npm run build`; open `/dashboard` and compare against `dashboard-full-E.html` (banner left text + dock, tile, section after tiles, directory unchanged); check dark theme and a narrow window.
- [ ] **Step 5: Commit** — `Revamp the Portal dashboard with quick access and the Calendar of Activities`

---

### Task 10: Release note and docs

**Files:**
- Create migration `2027_04_20_090200_add_calendar_of_activities_to_v3_beta_2_note.php` (pattern: `2027_04_12_090100_add_repeating_holidays_to_v3_beta_2_note.php`; type `feature`): "The Calendar of Activities: every office posts its activities and PALD keeps the regional calendar in order. See it as a month calendar or as the monthly sheet, print or download "CSC RO VIII CALENDAR OF ACTIVITIES" for any month, and see the coming week on your dashboard." and a second item (type `improvement`): "The dashboard's welcome banner has quick access to File Leave, My DTR, My Payslip, My PDS and the Calendar of Activities."
- Modify: `docs/architecture.md` — new section "Calendar of Activities" (permissions, roles, tables, rules, sheet, dashboard), and `CLAUDE.md` — one line under the modules list noting the Calendar of Activities (PALD process owner).

- [ ] **Step 1:** Write the migration; run `php artisan migrate`; confirm the two items in What's New (beta.2 draft).
- [ ] **Step 2:** Update the docs.
- [ ] **Step 3: Run** `php artisan test --compact tests/Feature/Activities tests/Feature/Portal tests/Feature/HR/RepeatingHolidaysTest.php` — PASS; `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 4: Commit** — `Note the Calendar of Activities in What's New and the docs`
