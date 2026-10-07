# HR Module — Checkpoint

**Updated:** 2026-10-03 (after HR-7, Positions, Staffing and the plantilla spreadsheet).
**Branch:** `feature/imis-portal-legacy-parity`.

This is a hand-off note for continuing the Human Resources (HR) module in
a new session. Read it together with `CLAUDE.md` and the HR sections of
`docs/architecture.md`, which describe in detail what is built.

> **Committed.** HR-1 to HR-7 and the portal, admin and legacy-bridge
> work are in commit `f4ccaa9` (not pushed). Work after that commit is
> in the working tree.

---

## 1. Where we are

| Phase | What | Status |
|---|---|---|
| HR-1 | Employee record (one per user), HR permissions, access trail | ✅ Done |
| HR-2a | Personal Data Sheet (CS Form 212 Revised 2026): data and self-service editor | ✅ Done |
| HR-2b | E-signed submission, HR verify/return, snapshots, print page | ✅ Done |
| HR-2c | 201 file documents (private, archive-not-delete) | ✅ Done |
| HR-3a | My Learning & Development log; PDS Section VI managed by L&D | ✅ Done |
| HR-3b | HRD review queue, per-employee L&D, bulk add | ✅ Done |
| HR-3c | HRD L&D dashboard, training report, ldms data import | ✅ Done. The real ldms data isn't imported yet; see 4. |
| HR-1b | Reassignment history between offices | ✅ Done |
| HR-4 | Plantilla, appointments, salary schedules | ✅ Done, modelled on the DBM PSIPOP while the office's format is pending. HR still has to enter the NBC No. 601 amounts. |
| HR-5 | Service Record | ✅ Done, in the standard CSC columns (the user agreed). Adjust when the office's template arrives. |
| HR-6a | Leave foundations: types, holidays, working days, credit ledger, accrual, leave card | ✅ Done |
| HR-6b | Filing (CS Form 6), supervisor → HR → head approval, OIC designations, notifications, printable form, LWOP | ✅ Done |
| HR-6c | Monetization/terminal leave, FWA and FAR, reports, calendar, Wellness Leave (CSC MC No. 01, s. 2026) | ✅ Done |
| **HR-7** | Data migration and cutover | ✅ Tools built (`imis:import-legacy-leave`, Cutover Readiness, `imis:retire-legacy-hr`); migrated locally. ⏳ **The import has not been run on real data**: the `lms_*` tables are only on the live server. Its columns come from the legacy code, so check them with a `--dry-run` first. |
| HR-8 | Payroll and payslips: pay components, pay profiles, loans, monthly payrolls (15th/30th), payslip requests approved by the head of HRD | ✅ Done. Rates seeded from current law: HR should verify them. Not run on real data yet. |

**Tests:** the suite has 768 tests, all passing. HR tests are in
`tests/Feature/HR/`.

**Local setup:** every migration has been run (latest:
`2026_10_29_090000_create_positions_table`), and `RolePermissionSeeder`
has been re-seeded.

---

## 2. Decisions already made (don't re-ask)

### General
- **PRISM** was blank, so HR is built from scratch. ldms and LMS
  (`leave/`) are *ported*: match their behaviour.
- **One record per user.** Bulk "Set up records for staff" handles
  existing accounts. New accounts get an employee record automatically
  (`UserController::store`). Employment status is optional ("Not set").
- **Supervisors** (`employees.view_unit`) see only HR-1 summaries of
  their office. They never see the PDS, 201 documents or L&D.
- **Separation of duties:** HR never edits, verifies or manages their
  *own* records. A Super Administrator may, via `Gate::before`.

### PDS (HR-2)
- The form is **CS Form 212 Revised 2026**. The blank form is
  `../old-imis/CS Form No. 212 Revised 2026 Personal Data Sheet PDS.xlsx`.
- **Workflow:** the employee edits and e-signs (declaration plus
  password re-check), then HR verifies or returns it. Snapshots are
  frozen.
- **Printing:** a print-ready page on 8.5 × 13 in paper, with no new
  libraries.
- **Civil status** follows the form's checkboxes. Solo parent is
  question 40c, not a civil status.

### L&D (HR-3)
- **Types:** the PDS list (Managerial, Supervisory, Technical,
  Foundation, Other). ldms's "Leadership and Management" maps to
  Managerial.
- **On the PDS:** approved and pending trainings appear. HRD reports
  count approved only.
- **Export:** CSV and print through the DataTable. No PhpSpreadsheet.
- **HRD entries** are approved immediately. Employee entries start
  pending.

---

## 3. Conventions to follow (patterns already in the code)

- **Permissions:** add cases to `App\Enums\PermissionName`, then run
  `php artisan db:seed --class=RolePermissionSeeder` (idempotent).
  - Guard every route with `permission:` middleware **and** a policy
    check.
  - A permission that should open the admin area also goes in
    `AppServiceProvider::ADMIN_ACCESS_PERMISSIONS` **and** the JS
    `adminAccessPermissions` (`resources/js/Utils/navigation.js`).
- **Policies** for models under `App\Models\Pds` must be registered with
  `Gate::policy()` in `AppServiceProvider`. Auto-discovery misses them.
- **Audit:** add cases to `App\Enums\AuditEvent` and add a morph-map
  alias in `AppServiceProvider::configureAuditing()`. Give models a
  `label` accessor so audit entries read well.
  - Never put sensitive *values* in audit metadata. Record field names
    or counts only.
- **Personal data:**
  - Files go on the **private** `local` disk under a random name.
  - Serve them through an authorized controller with
    `Cache-Control: private, no-store` and
    `X-Content-Type-Options: nosniff`.
  - Log access by anyone other than the owner.
  - Sensitive columns use `encrypted` casts, which depend on `APP_KEY`.
- **Single writer per aggregate:**
  - `EmployeeRecords`, `PdsWriter`, `PdsSubmissions`,
    `EmployeeDocuments` and `LdRecords`
  - all in `app/Support/HR/…`
- **Frontend:**
  - Pages render props and dispatch Inertia requests. Options always
    come from the backend (enums' `options()`).
  - Reuse `Card`, `DataTable` (client mode has CSV and print),
    `RecordModal`/`SchemaForm`, `Modal`, `StatusBadge`, `ActionButton`
    and `confirm()`.
  - For file uploads on edits, use **POST + `_method: 'put'`** with
    `forceFormData`.
  - Plain dates use `formatCalendarDate()`.
- **Tests run on SQLite.** Avoid MySQL-only SQL such as `YEAR()` in
  `selectRaw`. Use `whereYear` or compute in PHP.
- **Shell editing:** `sed`/`perl` regexes with backslashes kept failing
  on Windows Git Bash. Prefer the Edit tool for PHP `use` lines and
  namespaces.
- **Legacy data:** read only aggregates or structure from dumps that
  contain real data (`old-imis/db_imis.sql`, `old-imis/ld_cscro8.sql`).
  Never print rows. Never open `old-imis/leave/uploads/` (it holds
  medical certificates).

---

## 4. Remaining phases — plans

### HR-3b — done (see `docs/architecture.md`, "L&D review and management")
Built as planned, with these choices worth knowing for HR-3c:
- `ld.view` (read), `ld.manage` (add/edit/remove for others, bulk add)
  and `ld.approve` (review queue). All three open the admin area.
- `review` also allows a **rejected** entry to be approved later (from
  the employee's log page); the queue itself lists pending only.
- HRD edits never change an entry's status.
- Opening someone's log is audited (`ld_record.log_viewed`).
- `LdRecords::yearLog()` and the `LdLog` / `LdTrainingFields`
  components are shared between the employee's and HRD's pages — reuse
  them for the dashboard/report rather than re-querying.

### HR-3c — done (see `docs/architecture.md`, "L&D dashboard, report and ldms import")
Built as planned below. What's left is **running the import on real
data**, which needs the user's go-ahead (`ld_cscro8.sql` holds real
records):
1. Load `old-imis/ld_cscro8.sql` into the legacy database (the `legacy`
   connection, `LEGACY_DB_*`).
2. Make sure accounts carry their legacy ids: re-run
   `php artisan imis:import-legacy ../old-imis/db_imis.sql`.
3. HR runs **Employees → Set up records for staff**.
4. Run `php artisan imis:import-legacy-ld --dry-run`, check the counts
   (394 rows, 59 people expected), then run it without `--dry-run`. It
   lists anyone it couldn't match.

The original plan, for reference:
- **Dashboard** (ldms `hrd/fetch_hrd_dashboard.php`), for a selected year:
  - total active employees
  - trainings this year vs last year
  - total hours
  - offices
  - type breakdown
  - top 5 employees by hours
  - monthly activity (use `Components/Charts/SimpleChart.vue`)
  - recent trainings
- **Training report** (ldms `hrd/training_report.php`): filter by year,
  month and office.
  - Approved only.
  - Columns: name, sex, position, office, training, provider, type,
    modality, hours, venue, speaker(s), start and end dates.
  - Printable, with CSV export.
- **Import command** `php artisan imis:import-legacy-ld`, with a
  `--dry-run` option.
  - **Source:** the `ld_cscro8` table on the **legacy DB connection**.
    `old-imis/ld_cscro8.sql` is the live export with real data. It is
    not loaded into the local legacy DB yet, so ask the user before
    importing it.
  - **Live structure:** `id`, `user_id`, `training_name`,
    `training_provider`, `training_type`,
    `training_hrs decimal(10,2)`, `training_venue`, `resource_speaker`,
    `start_date`, `end_date`, `modality` enum, `certificate_url` (all
    empty), `approval_status` enum, `approved_by`, `created_by` (all 0),
    `otrs_training_id`, `created_at`, `updated_at`, `deleted_at`,
    `training_hrs_dec`.
  - **Facts (aggregate):** 394 rows, 59 people, 2025–2026. All are
    approved and face-to-face (that's the column default, so treat it
    as unknown). None are deleted, none link to OTRS, and the data has
    no problems.
  - **Mapping:**
    - `training_provider` → `sponsor`
    - `training_venue` → `venue`
    - `resource_speaker` → `resource_speaker`
    - type: Technical / Foundation map directly; "Leadership and
      Management" → `managerial`
    - modality → **null** ("not recorded"), because the default is
      unreliable
    - `approval_status` kept
    - `created_by` → null
    - `legacy_id` = `ld_cscro8.id`, which keeps the import idempotent
    - `deleted_at` rows skipped or kept soft-deleted
  - **Matching people:** legacy `user_id` maps to a new user through
    the legacy bridge/import mapping. Look at
    `app/Support/Legacy/LegacyImporter.php` for how users were matched.
    Only users with an **employee record** can receive trainings, so
    HR must run "Set up records for staff" first. Report anyone
    unmatched.

### HR-1b — done (see `docs/architecture.md`, "HR-1b: Reassignment history")
- **Decided with the user (2026-10-03):** only a *reassignment* moves
  the account's office. A detail and a designation keep the mother unit;
  a return ends an open detail.
- **Timing:** future-dated reassignments move the office on their date
  (`imis:apply-reassignments`, daily). Each moves it once.
- **Scans** are filed in the 201 file as an Office Order.
- **For HR-5:** `EmployeeAssignments::stationsOn($employees, $date)`
  gives each person's office (and any detail) on a date.
- **Deploying:** `php artisan migrate`, re-run `RolePermissionSeeder`
  (adds `employees.reassign`), grant it to the HR role, and make sure
  cron runs `schedule:run`.
- **Head-of-office designations (2026-10-04):** for the Field Director
  who heads two field offices for want of a second Director II item.
  Record a *designation* to the second office with Capacity
  "Officer-in-Charge" or "Caretaker" (both head the office), usually with
  no end date. While it runs, the director (with the
  Supervisor role) sees that office's staff records. Optionally the
  office's staff take them as immediate supervisor, and get their
  previous supervisors back when it ends. The Staffing page lists
  offices headed by designation. Deploying: `php artisan migrate`. See
  `docs/architecture.md`, "Head-of-office designations".

### HR-4 — done (see `docs/architecture.md`, "Plantilla")
Built on the user's go-ahead (2026-10-03) without the office's format:
- **Items** follow the DBM PSIPOP columns. Adjust them when the office's
  format arrives.
- **Appointments** are periods of service. Promotions and step
  increments end the previous period the day before, which is exactly
  the shape HR-5 needs.
- **Salary amounts** are HR's to enter, by typing or pasting the NBC
  No. 601 table. Nothing is seeded.
- **Deploying:** migrate, re-run `RolePermissionSeeder` (adds
  `plantilla.view`/`plantilla.manage`), grant them to HR, then enter the
  schedule and the items.
- **Purpose (user, 2026-10-03):** the recruitment and staffing plans,
  and tracking staff who serve away from their item's place of
  assignment on a reassignment order. Hence Positions, Staffing and
  `imis:link-plantilla` (see `docs/architecture.md`).
- **Local data:** 55 staff were linked with `imis:link-plantilla --apply`
  (5 inactive accounts skipped). Every link is incomplete: HR still has
  to enter item numbers, grades (Positions), steps, natures and start
  dates, and the NBC No. 601 amounts. The place of assignment was taken
  from each account's office, so anyone already serving elsewhere on an
  older reassignment order needs their item's place corrected, or the
  order recorded in the reassignment history. HR completes all of this
  at once with **Plantilla → Complete from spreadsheet**
  (`/admin/hr/plantilla-sheet`).
- **The office's format arrived (2026-10-04):** the certified *Plantilla
  of Personnel as of 1 October 2026* workbook. `imis:import-plantilla`
  reads it. Decisions (user, 2026-10-04): keep IMIS's full position
  titles; the sheet wins on sex, birthday and middle name; infer nature
  and start date from the last-promotion date; leave "FO No. 6 Leyte"
  unmapped (place of assignment unchanged) until HR says which office.
  Applied to the local database the same day: 8 vacant items created,
  53 items numbered and placed, 53 periods completed, 10 accounts
  corrected. 50 of 55 filled items are now complete. Left for HR:
  - two rows with step "19" (Accountant III, a Senior HRS): enter the step;
  - the Attorney III's last promotion is dated 2 Dec 2026 (future): check
    the sheet, then set nature and start date;
  - ~~an Admin. Officer II promotion not yet recorded in IMIS~~ —
    recorded locally (2026-10-05): Elizar T. Casinillo, Promotion to
    ADOF2-37-2005 from 10 Sep 2026; the Admin. Assistant III period was
    completed as Original from 10 Jan 2020 (step 1 assumed). His old
    item is ADAS3-57-2005 (user): the period was moved onto it and the
    unnumbered duplicate item (created when the account was linked)
    deleted. Do the same on the live server;
  - grades that disagree with the Positions list: Administrative Officer
    IV (sheet SG 15, IMIS 14) and Special Investigator III (sheet 18,
    IMIS 19);
  - the OAC-SAS-sourced Supervising HRS item (Biliran) has no item
    number of its own;
  - duplicate Director II accounts (Dela Cruz, Uy) without employee records;
  - in service in IMIS but not on the plantilla: four inactive accounts
    (separations not recorded; one transferred to the Supreme Court
    effective 1 Oct 2026) and the Computer Technician (not a plantilla
    item?);
  - typed dates "03/09/2026" were read as 9 March 2026 and "8/3/2026" as
    3 August 2026; confirm with HR.
  On the live server: copy the workbook there (never into git: personal
  data), run the preview, then `--apply`.

The original plan, for reference:

### HR-4 — Plantilla
- **Positions:** item number (unique), position title, salary grade,
  office/unit, authorization (e.g. DBM approval), status (filled,
  vacant, abolished).
- **Salary schedule:** salary grades × steps with a tranche effective
  date, so the monthly salary can be computed.
- **Assignments:** which employee holds which item, with dates.
  Vacant and filled views, and printable lists.
- **Permissions:** `plantilla.view` and `plantilla.manage`.
- Built from scratch; there's no legacy source.
- **Salary schedule:** the 3rd tranche, 2026, under **National Budget
  Circular No. 601** (user, 2026-10-03). Enter the grade × step amounts
  from the NBC itself (ask the user for the table, or have HR type it
  in). Don't fill them in from memory.
- **Still needed:** the office's plantilla format (the fields HR keeps
  per position item).

### HR-5 — done (see `docs/architecture.md`, "Service Record")
- **Built from the plantilla periods.** Lines break at reassignments and
  at new salary schedules.
- **Who sees it:** HR with `service_records.view` prints a certified
  copy (officer from System Settings → Human Resources). Employees print
  a reference copy. Supervisors never see it.
- **For HR-6:** fill the "leave without pay" column from the leave
  ledger (`ServiceRecords::build()`, `leave_without_pay`).
- **Local setup done** (2026-10-03): permission and settings seeded, and
  Super Administrator has the permission.

The original plan, for reference:

### HR-5 — Service Record
- A chronological record per employee, built from appointments to
  plantilla items (HR-4) and reassignments (HR-1b).
- **Columns:** inclusive dates, designation, status, salary, station or
  place of assignment, branch, leave without pay (from HR-6), and
  separation date and cause.
- **Print:** the CSC Service Record format, with HR certification.
  Ask the user for their office's template.
- PDS Section V (Work Experience) can be cross-checked against it.

### HR-6a — done (see `docs/architecture.md`, "Leave foundations")
- **Decided with the user (2026-10-03):**
  - the 6a/6b/6c split
  - SPL and Solo Parent Leave are two separate leaves, per CSC
  - approvals use permissions (`leave.certify`, `leave.approve`) plus
    officer-in-charge designations
- **For 6b, reuse:**
  - `LeaveCredits::ineligibilityReasons()` and `balance()` (via
    `creditType()`, so FL draws on VL)
  - `WorkingDays::count()`
  - post approved leave as a `deduction` ledger entry
  - fill the Service Record's leave-without-pay column
  - pro-rate accrual for LWOP
- **Legacy reference:** the workflow is in `old-imis/leave/services/LeaveWorkflowService.php`.
  - Actors are snapshotted when the application is submitted.
  - Actions: recommended / not recommended → certified / insufficient
    credits → approved / disapproved; any step can return it.
  - Reference numbers are `LV-YYYY-#####`.
- **Legacy CS Form 6 PDF was never finished** (placeholder coordinates,
  no template). Build it as a print-ready page.
- **Local setup done:** migrated, permissions and leave types seeded.
  - The first local run failed on MySQL's 64-character index-name limit;
    it's fixed with explicit short names.
  - The partial tables (holding only the seeded types) were dropped and
    the migration re-run.

### HR-6b — done (see `docs/architecture.md`, "Leave filing and approval")
- **Deduction date:** credits are deducted on the **approval date**, as
  in legacy. Dating it to the leave's first day let approved future
  leave be filed against twice; a test caught it.
- **Notifications** use the database channel and the top-bar bell.
  Email isn't wired yet.
- **Approvers:** "Leave Approvals" shows through the navigation `flag`
  `leaveApprover`, a new generic mechanism in `SidebarNav`.
- **Local setup done:** migrated, and `leave.certify`/`leave.approve`
  seeded (on Super Administrator).
- **For 6c:**
  - monetization (legacy `MonetizationService`: 50 % of VL, keep 15
    days, once a year, monthly salary ÷ 22) — salary from the plantilla
  - terminal leave
  - FWA and FAR (`FwaService`), plus CWW rest days in `WorkingDays`
  - reports and calendar
  - 6.B's "Other purpose: monetization/terminal leave" boxes on the form

### HR-6 — Leave (ports `old-imis/leave` and ldms's leave and FAR pages)
- **Reference code:** `old-imis/leave/services/*.php` (about 3,600
  lines), `leave/api/v2/*`, `leave/hrd/*`, and `leave/sql/leave.sql`
  (**structure only; it's missing `lms_leave_types` and
  `lms_employee_profiles`**, so ask the user for a fresh
  structure-only export).
- **Scope:**
  - leave types and policies, as data
  - filing with working-day calculation (holidays and weekends)
  - CS Form 6 generation
  - a supervisor approval queue, then HR processing
  - notifications and an audit trail
  - monthly accrual, credits and the leave card (VL/SL ledger),
    carry-forward and adjustments
  - monetization
  - FWA/FAR (flexible work arrangement accomplishment)
  - holidays, policy documents and reports
- **Supervisors:** leave approval needs a supervisor relationship. That
  likely needs HR-1b or office heads (`lms_forsu.head` in the legacy
  data).
- **Privacy:** medical certificates are health data. Use the same
  private-file rules.

### HR-7 — Migration and cutover
**Built** (see `docs/architecture.md`, Phase HR-7). Waiting on access to
the live `lms_*` tables. The original plan:
- **Import legacy data:**
  - leave balances, leave cards and history from the leave tables
  - remaining legacy `first_day` dates (only 2 of 61 are set)
- **Reconcile** counts and balances before switching.
- **Run old and new in parallel** for a period, then retire the
  LMS (`imis_projects` id 23) and ldms launcher entries from the
  Systems Directory. PRISM (id 22) is already deleted (migration
  `2026_10_31_090000_remove_prism_launcher`, user's decision 2026-10-03).

---

## 5. Deploying to the live server (everything so far)
1. `php artisan migrate`
2. `php artisan db:seed --class=RolePermissionSeeder`, and (HR-8) `php artisan db:seed --class=PayComponentSeeder`; grant `payroll.view`/`payroll.manage` to the HR Officer role under Roles (existing roles are not changed by `HrRoleSeeder`).
3. `npm run build`, or deploy the built `public/build`.
4. Run `php artisan db:seed --class=HrRoleSeeder` to create the starter
   **HR Officer**, **Top Management**, **Head of Agency** and
   **Supervisor** roles. It only creates missing ones. Then assign them
   to people under Users: the HRD Chief → HR Officer; the Director IV →
   Top Management and Head of Agency; the Director III → Top Management.
   (Done on the local database on 2026-10-03.)
5. As HR, run **Employees → Set up records for staff** once.
6. Run `php artisan imis:health` and clear any failures.
7. **Back up `APP_KEY`.** It decrypts PDS IDs, answers and snapshots.
   Also add `storage/app/private` to backups, because it holds 201
   files, PDS photos and certificates.

## 6. Where things live
| Area | Backend | Frontend |
|---|---|---|
| Employee record | `Admin\EmployeeController`, `Support/HR/EmployeeRecords`, `EmployeePolicy` | `Pages/Admin/HR/Employees/*`, `Components/HR/*` |
| Plantilla | `Support/HR/Plantilla/{Plantilla,SalarySchedules}`, `Admin\{PlantillaController,PlantillaAppointmentController,SalaryScheduleController}`, `PlantillaItemPolicy` | `Pages/Admin/HR/Plantilla/*`, `Pages/Admin/HR/SalarySchedules/Index.vue`, `Components/HR/PlantillaHistory.vue`, `Components/HR/plantillaFields.js` |
| Service Record | `Support/HR/ServiceRecords`, `Admin\EmployeeServiceRecordController`, `MyServiceRecordController` | `Components/HR/ServiceRecordTable.vue`, `Pages/Admin/HR/Employees/ServiceRecord.vue`, `Pages/Portal/MyServiceRecord.vue`, `Pages/HR/ServiceRecordPrint.vue` |
| Reassignments | `Support/HR/EmployeeAssignments`, `Admin\{EmployeeAssignmentController,ReassignmentReportController}`, `Console/Commands/ApplyDueReassignments` | `Components/HR/Assignment{Timeline,Modal}.vue`, `Pages/Admin/HR/Reassignments/Index.vue` |
| PDS | `Support/HR/Pds/{PdsSchema,PdsWriter,PdsReader,PdsSubmissions,PdsPhotos}`, `MyPdsController`, `Admin\EmployeePdsController`, `Admin\PdsVerificationController` | `Components/HR/Pds/*`, `Pages/Portal/MyPds*.vue`, `Pages/HR/PdsPrint.vue` |
| 201 file | `Support/HR/EmployeeDocuments`, `Admin\EmployeeDocumentController`, `EmployeeDocumentFileController` | `Pages/Admin/HR/Employees/Documents.vue`, `Pages/Portal/My201File.vue` |
| L&D | `Support/HR/LearningDevelopment/{LdRecords,LdReports}`, `MyLearningDevelopmentController`, `Admin\{LearningDevelopmentController,EmployeeLearningDevelopmentController,LdReviewController,LdReportController}`, `PdsLearningDevelopmentPolicy`, `Console/Commands/ImportLegacyLd` | `Pages/Portal/MyLearningDevelopment.vue`, `Pages/Admin/HR/LearningDevelopment/*` (incl. `Dashboard`, `Report`), `Pages/Admin/HR/Employees/LearningDevelopment.vue`, `Components/HR/Ld*.vue` |
