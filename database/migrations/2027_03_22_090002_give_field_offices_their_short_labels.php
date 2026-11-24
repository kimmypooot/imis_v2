<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The field offices are labelled FO-Leyte I, SO-WL and so on in IMIS. Only
 * the display label (`short_name`) changes: the office `code` stays the key
 * that connected systems (PROCTAD, TIMS, Recruitment, ...) match on.
 * A label an admin has already edited (no longer equal to the code) is kept.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const LABELS = [
        'LFO' => 'FO-Leyte',
        'LFO-I' => 'FO-Leyte I',
        'LFO-II' => 'FO-Leyte II',
        'SLFO' => 'FO-SL',
        'WLSO' => 'SO-WL',
        'BFO' => 'FO-B',
        'SFO' => 'FO-S',
        'ESFO' => 'FO-ES',
        'NSFO' => 'FO-NS',
    ];

    public function up(): void
    {
        foreach (self::LABELS as $code => $label) {
            DB::table('organizational_units')
                ->where('code', $code)
                ->where(fn ($query) => $query->whereColumn('short_name', 'code')->orWhereNull('short_name'))
                ->update(['short_name' => $label, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $code => $label) {
            DB::table('organizational_units')
                ->where('code', $code)
                ->where('short_name', $label)
                ->update(['short_name' => $code, 'updated_at' => now()]);
        }
    }
};
