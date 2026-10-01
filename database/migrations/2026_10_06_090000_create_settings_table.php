<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            // Every type is stored as text; App\Enums\SettingType casts it.
            $table->text('value')->nullable();
            // Strings rather than DB enums, so adding a type or group is a
            // code change only (validated by the model's enum casts).
            $table->string('type', 20);
            $table->string('group', 30);
            $table->string('label');
            $table->string('description', 500)->nullable();
            // false = protected: shown read-only, rejected server-side for
            // everyone (including Super Administrators).
            $table->boolean('editable')->default(true);
            // Validation bounds ({required, min, max}) from SettingRegistry.
            $table->json('constraints')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['group', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
