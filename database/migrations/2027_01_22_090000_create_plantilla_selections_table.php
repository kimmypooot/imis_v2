<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantilla_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_item_id')->constrained()->cascadeOnDelete();
            // The Recruitment Portal's vacancy this selection came from.
            $table->unsignedBigInteger('recruitment_vacancy_id');
            $table->string('applicant_name');
            $table->string('applicant_email')->nullable();
            $table->date('selected_on');
            // pending until HR appoints the person on the item; cancelled if it falls through.
            $table->string('status', 20)->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['plantilla_item_id', 'recruitment_vacancy_id'], 'plantilla_selections_item_vacancy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_selections');
    }
};
