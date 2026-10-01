<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra places a system is listed in the dashboard directory, beyond
     * the organizational unit that manages it — the legacy
     * imis_dash_division_systems mapping, where one system could sit under
     * several divisions. A listing target is an organizational unit or a
     * committee (morph map aliases), so committee-run systems can sit under
     * their committee.
     */
    public function up(): void
    {
        Schema::create('application_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->morphs('listable');
            $table->timestamps();

            // Named explicitly: the generated name exceeds MySQL's 64-char limit.
            $table->unique(['application_id', 'listable_type', 'listable_id'], 'application_listings_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_listings');
    }
};
