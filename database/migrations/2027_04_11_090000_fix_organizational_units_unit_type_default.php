<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renaming `type` to `unit_type` on MariaDB 10.4 stored the default with
 * its quotes (the text 'other' including the quote marks), which isn't a
 * unit type. Every insert sets the type today; this restores the default
 * in case one doesn't.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_units', function (Blueprint $table) {
            $table->string('unit_type')->default('other')->change();
        });
    }

    public function down(): void
    {
        // The quoted default was a defect, not a state to return to.
    }
};
