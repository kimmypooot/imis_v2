# First production install

IMIS goes to production on a **fresh database**, built from the migrations
and the imports below. The local development database (`csc_ro8_imis` on
`localhost`) is never copied to the server: its ids have gaps from
re-imports and deleted test rows, and it holds test data. A fresh build
numbers everything in order by itself. Updating a server that already runs
IMIS is covered in `docs/hr-checkpoint.md` §5.

The order matters: **IMIS first, then the connected systems.** Nine hub
systems (JPortal, PROCTAD, TIMS, GAD Corner, ICT SRTS, IPCRS, HUMANS, ERIS,
Recruitment) keep each person's IMIS user id as `imis_user_id`, and
Recruitment also keeps plantilla item ids. After they connect, IMIS ids
must never change: no renumbering and no re-importing accounts.

## 1. Server and database
1. Use the same MariaDB/MySQL version the app is tested on locally.
2. Create the database with `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
   (the server default may be `utf8mb4_general_ci`).
3. Set `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, the
   database, mail and trusted hosts. Generate `APP_KEY` once
   (`php artisan key:generate`) and **back it up off the server right away**:
   it decrypts PDS identity numbers, items 34–40, signed PDS submissions and
   SALNs, and cannot be replaced.

## 2. Build IMIS
1. `composer install --no-dev --optimize-autoloader`, then `npm ci && npm run build`
   (or upload the built `public/build`).
2. `php artisan migrate --force`
3. `php artisan db:seed --force`. This runs `DatabaseSeeder`: roles and
   permissions, starter HR roles, settings, the regional offices (in
   office order), leave types and pay components. Never run
   `PayrollDemoSeeder` on the server.
4. `php artisan storage:link`, then the cache commands
   (`config:cache`, `route:cache`, `view:cache`).

## 3. Load the data (dry run first, then `--apply`)
Each import shows what it would do; run it again with `--apply` once the
output looks right.
1. Accounts, systems, access and history from the legacy IMIS:
   `php artisan imis:import-legacy ../old-imis/db_imis.sql` (or
   `--database=` for a schema that already holds the legacy tables). This
   keeps each account's `legacy_id`, which the legacy PHP systems sign in with.
2. Create the accounts that exist only in IMIS 3 under **Users** (or Bulk import).
3. Plantilla: `imis:import-plantilla <workbook.xlsx>`, then `imis:link-plantilla`.
4. As HR, run **Employees → Set up records for staff** once.
5. Pay profiles: `imis:import-payroll-workbook <workbook.xlsx> --month=YYYY-MM`.
6. Leave: `imis:import-leave-cards <workbook.xlsx> --as-of=YYYY-MM` and
   `imis:import-leave-card-history`; on the server that still has the
   `lms_*` tables, `imis:import-legacy-leave` (see `docs/hr-checkpoint.md`).
7. Training records: `imis:import-legacy-ld`.
8. Re-enter what was only typed in locally: committees and members,
   reassignments and supervisor handovers, System Settings, and anything
   else the imports don't bring over.

## 4. Point IMIS at production
1. In **Manage Systems**, change every system's address from `localhost:80xx`
   to its production address, and set feed addresses and keys.
2. Issue each connected system its API token (Admin → API tokens).
3. Turn on automatic backups in System Settings, and back up
   `storage/app/private` (201 files, PDS photos, signatures) with the database.
4. Add the scheduler cron entry (`* * * * * php artisan schedule:run`). It also
   runs the queue.
5. `php artisan imis:health` and clear every failure.

## 5. ORS and OTRS (the legacy bridge)
Every system is rebuilt except two legacy PHP systems, both run by HRD:
**ORS** (direct applications; its live data stays in ORS, separate from the
account-based Recruitment Portal) and **OTRS** (HRD administers it, Field
Office staff are its users). They read people from the legacy database
(`users_cscro8`) and their roles from `imis_system_access`, by legacy id.

Turn the bridge on so IMIS 3 stays the only place access is managed: roles
granted, new accounts and deactivations in IMIS 3 are written through to
the legacy tables (within moments, and hourly by `imis:sync-legacy`).
1. Apply the single sign-on patch to the legacy IMIS and set the `.env` lines
   in `deploy/legacy-imis/README.md` (`LEGACY_SSO_HOSTS`,
   `LEGACY_SSO_REQUIRE_PATH`, `LEGACY_BRIDGE=true`, `LEGACY_DB_*`).
2. After `imis:import-legacy` (step 3.1), run `php artisan imis:sync-legacy`
   once. It gives the IMIS 3-only accounts a legacy id and writes the merged
   accounts back (`rbuy` on legacy row 52, `mmdelacruz` on row 21, with all
   their roles).
3. The folded accounts (`LegacyImporter::MERGED_ACCOUNTS`: legacy rows 50
   `rbuy-esamar` and 53 `mmdelacruz-wleyte`) are not in IMIS 3, so the bridge
   never touches them. Set them inactive in the legacy database so they can't
   open ORS or OTRS:
   `UPDATE users_cscro8 SET status = 'Inactive' WHERE id IN (50, 53);`
4. At cutover, redirect the old dashboard and `login.php` to IMIS 3 and retire
   the old `admin/`. Edits made there would be overwritten by the bridge.
5. In IMIS 3, ORS and OTRS roles are granted under System Access by anyone
   with `applications.manage_access`. The HRD Division Chief gets admin access
   to HRD's systems automatically (office designation).

When ORS and OTRS are retired, set `LEGACY_BRIDGE=false`, empty
`LEGACY_SSO_HOSTS`, and remove the `LEGACY_DB_*` lines: IMIS 3 then has no
legacy connection at all.

## 6. Connect the systems
For each hub system, start with an **empty database**. Set `IMIS_SSO_URL`,
`IMIS_SSO_AUDIENCE`, `IMIS_PORTAL_URL` and its token in its `.env`. Accounts
are created there the first time each person opens the system from IMIS, so
they link to the right production ids. If a system's local data must be
kept, remap its `imis_user_id` by username before it goes live.
