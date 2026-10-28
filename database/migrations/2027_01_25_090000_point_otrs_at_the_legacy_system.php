<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OTRS is the legacy Online Training Registration System; the Laravel
 * replacement is the separate TIMS entry. Where OTRS's address was pointing
 * at the Laravel app (as it did while TIMS was being built), this points it
 * back at the legacy system: users open `/otrs/index` and the Admin role
 * opens `/otrs/hrd/index`, as in the legacy IMIS.
 *
 * Left alone when OTRS already points at a legacy-style `/otrs` address, so a
 * working setup is never overwritten, and when a role already has its own
 * launch address. The host is the legacy IMIS host (`LEGACY_SSO_HOSTS`).
 *
 * Not reversible: set the address again in System management.
 */
return new class extends Migration
{
    private const LEGACY_ORIGIN = 'https://imis.cscro8.com';

    public function up(): void
    {
        $otrs = DB::table('applications')->where('short_name', 'OTRS')->first();

        if ($otrs === null || str_starts_with((string) parse_url((string) $otrs->url, PHP_URL_PATH), '/otrs')) {
            return;
        }

        DB::transaction(function () use ($otrs) {
            DB::table('applications')->where('id', $otrs->id)->update([
                'url' => self::LEGACY_ORIGIN.'/otrs/index',
                'updated_at' => now(),
            ]);

            DB::table('application_roles')
                ->where('application_id', $otrs->id)
                ->where('code', 'admin')
                ->whereNull('launch_url')
                ->update(['launch_url' => self::LEGACY_ORIGIN.'/otrs/hrd/index', 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        //
    }
};
