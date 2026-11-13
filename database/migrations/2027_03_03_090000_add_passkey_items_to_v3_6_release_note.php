<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the fingerprint / face sign-in and home-screen items to the v3.6.0 What's New release where
 * it already exists (databases that ran add_v3_6_release_note before these were written). A fresh
 * database already has them from that migration, so nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.6.0';

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            $existing = DB::table('changelog_items')->where('changelog_id', $changelog->id)->pluck('description')->all();
            $next = (int) DB::table('changelog_items')->where('changelog_id', $changelog->id)->max('sort_order') + 1;

            foreach (self::items() as [$type, $description]) {
                if (in_array($description, $existing, true)) {
                    continue;
                }

                DB::table('changelog_items')->insert([
                    'changelog_id' => $changelog->id,
                    'type' => $type,
                    'description' => $description,
                    'sort_order' => $next++,
                ]);
            }

            DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // A published release keeps its items.
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            ['feature', 'Sign in with your fingerprint or face: on your own phone or laptop, open Account settings, then Fingerprint sign-in, add the device, and next time choose Sign in with fingerprint or face on the login page. Your fingerprint never leaves your phone, and your password still works.'],
            ['improvement', 'Added to the home screen, IMIS now shows the CSC logo and the name CSC RO VIII - IMIS on iPhone and Android.'],
        ];
    }
};
