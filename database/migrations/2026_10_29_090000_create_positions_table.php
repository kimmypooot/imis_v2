<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-4 positions: one master list of position titles with their salary
 * grade and level. Plantilla items with the same title link to it and
 * follow its grade and level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->unique();
            // Blank until HR enters it.
            $table->unsignedTinyInteger('salary_grade')->nullable();
            $table->string('level', 10)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::table('plantilla_items', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::dropIfExists('positions');
    }
};
