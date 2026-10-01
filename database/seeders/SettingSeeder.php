<?php

namespace Database\Seeders;

use App\Support\Settings\SettingRegistry;
use Illuminate\Database\Seeder;

/**
 * Syncs the settings defined in SettingRegistry into the `settings` table.
 * Safe to re-run on every deployment: editable values already saved
 * through the admin UI are kept; metadata and protected values follow the
 * code.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        SettingRegistry::sync();
    }
}
