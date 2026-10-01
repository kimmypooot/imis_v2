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
        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('url', 'external_url');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('short_name')->nullable()->after('name');
            $table->string('slug')->nullable()->unique()->after('short_name');
            $table->text('purpose')->nullable()->after('description');
            $table->text('requirements')->nullable()->after('purpose');
            $table->text('procedure')->nullable()->after('requirements');
            $table->string('fees')->nullable()->after('procedure');
            $table->string('processing_time')->nullable()->after('fees');
            $table->text('contact_information')->nullable()->after('processing_time');
            $table->boolean('featured')->default(false)->after('status');
            $table->foreignId('category_id')->nullable()->after('organizational_unit_id')
                ->constrained('service_categories')->nullOnDelete();

            $table->index('category_id');
        });

        // Free-text category is superseded by the category_id relationship.
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('category')->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropUnique(['slug']);
            $table->dropColumn([
                'short_name', 'slug', 'purpose', 'requirements', 'procedure',
                'fees', 'processing_time', 'contact_information', 'featured',
            ]);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('external_url', 'url');
        });
    }
};
