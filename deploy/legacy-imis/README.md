# Legacy IMIS: single sign-on patch

While the old PHP subsystems (OTRS, ERIS, LMS, CTS, …) still run, people
launch them from the new IMIS. Each launch carries a one-time
`?sso_token=`. Every subsystem page includes the legacy
`includes/session.php`, which already redeems such tokens. This patch
fixes how it does that.

`session-sso.patch` is a diff of `includes/session.php` against the
version copied from the live server on 2026-10-02. It changes three
things.

1. **Where the token is checked.** The URL comes from
   `IMIS_SSO_VALIDATE_URL` in `config.local.php`. The old code built it
   from the request's `Host` header (pointing at the retired
   `/vilt-sandbox` prototype), so a forged header could send the check
   elsewhere. Certificate verification is now on, and the call times out.
   The page path is sent too, so IMIS can confirm the token was issued
   for this system.
2. **What a sign-in sets up.** It does the per-user setup
   `api/system-redirect.php` used to do at launch:
   - CTS `cts_user_id` / `ao_number`
   - RFCS `driver_id`
   - ICTSRTS `is_itg_member`
   - OTRS `?location=<office>` for the User role

   It also reads `type` and `role` from `users_cscro8` and loads the
   per-system roles. The session starts empty, so it never inherits
   another account's roles.
3. **The token never stays in the address bar**, even when it's
   rejected.

Everything else in `session.php` is unchanged.

## Apply

1. **Back up** `includes/session.php` on the server.
2. Make sure the server copy still matches what this patch was made from:

   ```bash
   cd /path/to/imis            # the folder holding includes/
   patch -p1 --dry-run < session-sso.patch
   ```

   If it doesn't apply, the server copy has changed since 2026-10-02.
   Send the current file over and the patch will be regenerated. Don't
   force it.
3. Apply: `patch -p1 < session-sso.patch`. On Hostinger without shell
   access, apply it locally to a copy and upload the patched
   `session.php` through File Manager.
4. Add to `includes/config.local.php`, using the new IMIS's real address:

   ```php
   // ── IMIS single sign-on ───────────────────────────────────────────────
   // Where subsystems redeem the one-time ?sso_token= from IMIS.
   putenv('IMIS_SSO_VALIDATE_URL=https://imis.cscro8.com/api/v1/sso/validate');
   ```

## New IMIS `.env`

```dotenv
# Launch URLs on these hosts get a sign-in token.
LEGACY_SSO_HOSTS=imis.cscro8.com
# Once the patched session.php is live: require the page path.
LEGACY_SSO_REQUIRE_PATH=true

# Mirror accounts and access into the legacy database.
LEGACY_BRIDGE=true
LEGACY_DB_HOST=localhost
LEGACY_DB_DATABASE=u390694310_cscro8
LEGACY_DB_USERNAME=u390694310_cscro8
LEGACY_DB_PASSWORD=...
```

Then run `php artisan imis:import-legacy` (or re-run it) so every
imported account records its legacy id. After that, run
`php artisan imis:sync-legacy` once to mirror the accounts made in the
new IMIS. The scheduler repeats the sync hourly.

## At cutover

- Point the old dashboard and `login.php` at the new IMIS (a redirect),
  and retire the old `admin/`. Access is now managed only in the new
  IMIS, which writes it through to `imis_system_access`. Edits made in the
  old admin would be overwritten.
- Once every launch goes through the new IMIS, `api/system-redirect.php`
  is no longer used.

## Rollback

Restore the backed-up `session.php`. The new IMIS keeps working. Launches
just land on the subsystem's own login, because no sign-in is handed over.
