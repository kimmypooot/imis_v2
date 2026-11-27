<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notes the choice of what to include in Download my data, and the parts
 * it now covers, in the 3.0.0-beta.2 What's New draft. Nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    /** @var array<int, array{type: string, description: string}> */
    private const ITEMS = [
        [
            'type' => 'improvement',
            'description' => 'Download my data (under Account settings) now lets you choose what to include: tick the parts you want, such as your PDS, leave, DTR, pay and loans, SALN or sign-in history, and the copy holds only those. It now also covers your DTR, SALN, loans, payslip requests, 201 File list, salary notices, correction requests and sign-in history.',
        ],
    ];

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            $added = false;

            foreach (self::ITEMS as $item) {
                $exists = DB::table('changelog_items')
                    ->where('changelog_id', $changelog->id)
                    ->where('description', $item['description'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('changelog_items')->insert([
                    'changelog_id' => $changelog->id,
                    'type' => $item['type'],
                    'description' => $item['description'],
                    'sort_order' => (int) DB::table('changelog_items')->where('changelog_id', $changelog->id)->max('sort_order') + 1,
                ]);

                $added = true;
            }

            if ($added) {
                DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // A published release keeps its items.
    }
};
