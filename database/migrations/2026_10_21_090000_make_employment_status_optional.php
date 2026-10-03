<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee records can now exist before HR knows the employment status
     * ("Not set"): HR sets them up in bulk for existing staff, and new
     * accounts get one automatically. The legacy IMIS never recorded the
     * status, so there is nothing to carry over.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_status', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_status', 30)->nullable(false)->change();
        });
    }
};
