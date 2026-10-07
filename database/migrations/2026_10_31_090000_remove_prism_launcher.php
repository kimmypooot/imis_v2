<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PRISM is replaced by the built-in HR module (it held no data, so there is
 * nothing to import), so its portal launcher is deleted outright: its
 * system roles, access grants, listings and SSO tokens go with it (cascade),
 * and its access-log rows keep their history with the system cleared.
 * `LegacyImporter::RETIRED_SYSTEMS` keeps a re-import from bringing it back.
 *
 * Not reversible: the launcher can be re-added from System management.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')->where('short_name', 'PRISM')->delete();
    }

    public function down(): void
    {
        //
    }
};
