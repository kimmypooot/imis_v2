<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar of Activities.
 *
 * `activities` holds one row per occurrence. An activity always has a year
 * and month; its dates may follow later (month only, `planned`). "Moved"
 * is derived from `activity_moves`, the history of date changes. A
 * repeating activity is an `activity_series` rule whose occurrences are
 * real rows in `activities`. `activity_plan_cutoffs` holds PALD's plan
 * cut-off per year (31 January when none is set); activities added after
 * it are flagged unplanned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizational_unit_id')->constrained()->restrictOnDelete();
            $table->string('frequency', 20);
            $table->unsignedTinyInteger('interval')->default(1);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('nth_weekday')->nullable();
            $table->date('starts_on');
            $table->date('until');
            $table->boolean('skip_holidays')->default(true);
            $table->json('template');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizational_unit_id')->constrained()->restrictOnDelete();
            $table->string('title', 255);
            $table->string('responsible', 255)->nullable();
            $table->string('participants', 255)->nullable();
            $table->string('venue', 255)->nullable();
            $table->string('mode', 20);
            $table->text('remarks')->nullable();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('status', 20);
            $table->string('cancel_reason', 255)->nullable();
            $table->boolean('unplanned')->default(false);
            $table->foreignId('series_id')->nullable()->constrained('activity_series')->nullOnDelete();
            $table->string('returned_note', 255)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('removed_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['year', 'month']);
            $table->index('starts_on');
            $table->index('ends_on');
            $table->index('organizational_unit_id');
        });

        Schema::create('activity_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->date('from_starts_on');
            $table->date('from_ends_on');
            $table->time('from_starts_at')->nullable();
            $table->time('from_ends_at')->nullable();
            $table->date('to_starts_on');
            $table->date('to_ends_on');
            $table->time('to_starts_at')->nullable();
            $table->time('to_ends_at')->nullable();
            $table->string('reason', 255);
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('activity_plan_cutoffs', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->date('cuts_off_on');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_plan_cutoffs');
        Schema::dropIfExists('activity_moves');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('activity_series');
    }
};
