<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the PDS signing options, the PNPKI-signed copy and the 201 File
 * revamp to the 3.0.0-beta.2 What's New draft.
 * Runs after curate_v3_beta_2_note, which replaces every earlier entry, so
 * these are appended to the curated list. Nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

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
            ['feature', 'Print your PDS the way you will sign it: by hand (signature boxes and dates left blank), with your PNPKI digital certificate (boxes blank, dates filled, to sign the saved PDF), or with your IMIS e-signature on a signed PDS, which now keeps a copy of your e-signature when you sign.'],
            ['feature', 'Attach the PNPKI-signed PDF of a signed PDS: IMIS checks every digital signature in it (intact, nothing changed after the last one, one per page), shows who signed and when, and files it in your 201 File. HR sees the same details when verifying.'],
            ['improvement', 'The 201 File lists documents in a searchable table with filters for each part of the file (appointment, personal records, education and eligibility, service and performance), and the verified PDS history with View and Print.'],
        ];
    }
};
