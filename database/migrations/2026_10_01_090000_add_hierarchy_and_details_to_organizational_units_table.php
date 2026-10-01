<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizational_units', function (Blueprint $table) {
            $table->renameColumn('type', 'unit_type');
        });

        Schema::table('organizational_units', function (Blueprint $table) {
            $table->string('code')->nullable()->after('id');
            $table->string('slug')->nullable()->after('code');
            $table->foreignId('parent_id')->nullable()->after('slug')
                ->constrained('organizational_units')->restrictOnDelete();
            $table->string('email')->nullable()->after('description');
            $table->string('telephone')->nullable()->after('email');
            $table->string('address')->nullable()->after('telephone');
            $table->string('website_url')->nullable()->after('address');

            $table->unique('code');
            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizational_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropUnique(['code']);
            $table->dropUnique(['slug']);
            $table->dropColumn(['code', 'slug', 'email', 'telephone', 'address', 'website_url']);
        });

        Schema::table('organizational_units', function (Blueprint $table) {
            $table->renameColumn('unit_type', 'type');
        });
    }
};
