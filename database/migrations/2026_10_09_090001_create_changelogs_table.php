<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "What's New" release notes, carried over from the legacy
     * imis_changelog / imis_changelog_items tables.
     */
    public function up(): void
    {
        Schema::create('changelogs', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->unique();
            $table->string('title', 150);
            $table->date('release_date')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('changelog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('changelog_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['feature', 'improvement', 'fix'])->default('feature');
            $table->string('description', 500);
            $table->unsignedInteger('sort_order')->default(0);

            $table->index(['changelog_id', 'sort_order']);
        });

        // The newest release each user has seen, so the dashboard pops up
        // a release once per person (the legacy kept this in the session).
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('last_seen_changelog_id')->nullable()->after('previous_login_at')
                ->constrained('changelogs')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_seen_changelog_id');
        });
        Schema::dropIfExists('changelog_items');
        Schema::dropIfExists('changelogs');
    }
};
