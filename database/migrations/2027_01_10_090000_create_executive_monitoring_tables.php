<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exec-1 to Exec-3, the Executive Dashboard:
     * - `executive_snapshots`: each source's figures as collected by
     *   `imis:collect-metrics` (the dashboard only reads these, so it never
     *   waits on a slow or failing system), kept as history for trends.
     * - `executive_targets`: the agreed target for a figure, and how far
     *   off counts as "at risk" rather than "behind".
     * - a system's summary feed: the address IMIS pulls its figures from,
     *   and the key it sends (encrypted with APP_KEY).
     */
    public function up(): void
    {
        Schema::create('executive_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->timestamp('collected_at');
            $table->string('status', 16);
            $table->string('error', 255)->nullable();
            $table->json('metrics')->nullable();

            $table->index(['source', 'collected_at']);
        });

        Schema::create('executive_targets', function (Blueprint $table) {
            $table->id();
            $table->string('metric', 150)->unique();
            $table->decimal('target', 14, 2);
            $table->string('direction', 8);
            $table->unsignedTinyInteger('tolerance')->default(10);
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->string('feed_url', 500)->nullable()->after('url');
            $table->text('feed_key')->nullable()->after('feed_url');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['feed_url', 'feed_key']);
        });

        Schema::dropIfExists('executive_targets');
        Schema::dropIfExists('executive_snapshots');
    }
};
