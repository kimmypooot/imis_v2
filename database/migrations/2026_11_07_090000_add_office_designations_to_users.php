<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Division Chief / Field Director on the user, and which system grants
 * that designation made, so removing it undoes only those: a grant it
 * created is revoked, a role it raised to admin goes back to what it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('office_designation', 30)->nullable()->after('organizational_unit_id');
            $table->index(['organizational_unit_id', 'office_designation']);
        });

        Schema::table('application_access', function (Blueprint $table) {
            $table->boolean('via_designation')->default(false)->after('application_role_id');
            // The role they held before the designation raised it (null: it granted the access).
            $table->foreignId('designation_previous_role_id')->nullable()->after('via_designation')
                ->constrained('application_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('application_access', function (Blueprint $table) {
            $table->dropConstrainedForeignId('designation_previous_role_id');
            $table->dropColumn('via_designation');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['organizational_unit_id', 'office_designation']);
            $table->dropColumn('office_designation');
        });
    }
};
