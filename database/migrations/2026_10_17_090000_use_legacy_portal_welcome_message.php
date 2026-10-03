<?php

use App\Support\Settings\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_DEFAULT = 'Welcome to the CSC RO VIII Integrated Management Information System.';

    private const LEGACY = 'Enhancing public service delivery through innovation and digital transformation.';

    /**
     * The portal welcome message now defaults to the legacy dashboard's
     * banner subtitle. Only a value still equal to the old default is
     * changed; a message an admin has customized is left alone.
     */
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'portal_welcome_message')
            ->where('value', self::OLD_DEFAULT)
            ->update(['value' => self::LEGACY, 'updated_at' => now()]);

        Settings::flush();
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'portal_welcome_message')
            ->where('value', self::LEGACY)
            ->update(['value' => self::OLD_DEFAULT, 'updated_at' => now()]);

        Settings::flush();
    }
};
