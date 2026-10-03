<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets staff be linked to the plantilla before HR has the details: an
 * item may lack its item number and salary grade, and an appointment its
 * step, status, nature and start date. Such a link is "incomplete" and
 * stays off the Service Record until HR fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantilla_items', function (Blueprint $table) {
            $table->string('item_number', 100)->nullable()->change();
            $table->unsignedTinyInteger('salary_grade')->nullable()->change();
        });

        Schema::table('plantilla_appointments', function (Blueprint $table) {
            $table->unsignedTinyInteger('step')->nullable()->change();
            $table->string('status', 20)->nullable()->change();
            $table->string('nature', 30)->nullable()->change();
            $table->date('date_from')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_appointments', function (Blueprint $table) {
            $table->unsignedTinyInteger('step')->nullable(false)->change();
            $table->string('status', 20)->nullable(false)->change();
            $table->string('nature', 30)->nullable(false)->change();
            $table->date('date_from')->nullable(false)->change();
        });

        Schema::table('plantilla_items', function (Blueprint $table) {
            $table->string('item_number', 100)->nullable(false)->change();
            $table->unsignedTinyInteger('salary_grade')->nullable(false)->change();
        });
    }
};
