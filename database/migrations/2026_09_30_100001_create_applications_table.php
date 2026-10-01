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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('url')->nullable();
            $table->string('category')->nullable();
            $table->boolean('external')->default(true);
            $table->boolean('featured')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('visibility', ['public', 'authenticated', 'administrative'])->default('authenticated');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('organizational_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'visibility', 'sort_order']);
            $table->index('featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
