<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-2a: the Personal Data Sheet, CS Form No. 212 (Revised 2026).
     *
     * One `pds_profiles` row per employee holds the single-value items
     * (I. Personal Information, II. family names, items 34–40, the
     * government-issued ID). Each repeating section is its own table, so
     * later phases reuse it: HR-3 builds on L&D, HR-5 reads work
     * experience. Name, sex at birth and date of birth stay on `users`.
     *
     * Identity numbers, the item 34–40 answers and the government ID are
     * encrypted at rest (Laravel `encrypted` casts, keyed by APP_KEY), so
     * those columns are `text`.
     */
    public function up(): void
    {
        Schema::create('pds_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->restrictOnDelete();

            // I. Personal information (items 4–21; 1–3, 5 are on users).
            $table->string('place_of_birth')->nullable();
            $table->string('civil_status', 20)->nullable();
            $table->string('civil_status_other', 50)->nullable();
            $table->decimal('height_m', 3, 2)->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('blood_type', 3)->nullable();
            $table->text('umid_no')->nullable();
            $table->text('pagibig_no')->nullable();
            $table->text('philhealth_no')->nullable();
            $table->text('philsys_no')->nullable();
            $table->text('tin_no')->nullable();
            $table->string('citizenship', 10)->nullable();
            $table->string('dual_citizenship_by', 15)->nullable();
            $table->string('dual_citizenship_country', 100)->nullable();
            foreach (['residential', 'permanent'] as $address) {
                $table->string("{$address}_house_no", 100)->nullable();
                $table->string("{$address}_street", 100)->nullable();
                $table->string("{$address}_subdivision", 100)->nullable();
                $table->string("{$address}_barangay", 100)->nullable();
                $table->string("{$address}_city", 100)->nullable();
                $table->string("{$address}_province", 100)->nullable();
                $table->string("{$address}_zip_code", 10)->nullable();
            }
            $table->boolean('permanent_same_as_residential')->default(false);
            $table->string('telephone_no', 50)->nullable();
            $table->string('mobile_no', 50)->nullable();
            $table->string('email')->nullable();

            // II. Family background (children are pds_children).
            $table->string('spouse_surname', 100)->nullable();
            $table->string('spouse_first_name', 100)->nullable();
            $table->string('spouse_middle_name', 100)->nullable();
            $table->string('spouse_name_extension', 10)->nullable();
            $table->string('spouse_occupation', 150)->nullable();
            $table->string('spouse_employer', 255)->nullable();
            $table->string('spouse_business_address', 255)->nullable();
            $table->string('spouse_telephone_no', 50)->nullable();
            $table->string('father_surname', 100)->nullable();
            $table->string('father_first_name', 100)->nullable();
            $table->string('father_middle_name', 100)->nullable();
            $table->string('father_name_extension', 10)->nullable();
            $table->string('mother_surname', 100)->nullable();
            $table->string('mother_first_name', 100)->nullable();
            $table->string('mother_middle_name', 100)->nullable();

            // Items 34–40, as one encrypted JSON document.
            $table->text('questions')->nullable();

            // Item 42's government-issued ID.
            $table->string('government_id_type', 100)->nullable();
            $table->text('government_id_number')->nullable();
            $table->text('government_id_issuance')->nullable();

            $table->timestamps();
        });

        Schema::create('pds_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('full_name');
            $table->date('date_of_birth')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pds_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('level', 20);
            $table->string('school');
            $table->string('degree')->nullable();
            $table->unsignedSmallInteger('attendance_from')->nullable();
            $table->unsignedSmallInteger('attendance_to')->nullable();
            $table->string('highest_level_units', 100)->nullable();
            $table->unsignedSmallInteger('year_graduated')->nullable();
            $table->string('honors')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pds_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('eligibility');
            $table->string('rating', 20)->nullable();
            $table->date('exam_date')->nullable();
            $table->string('exam_place')->nullable();
            $table->string('license_number', 50)->nullable();
            $table->date('license_valid_until')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pds_work_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date_from');
            $table->date('date_to')->nullable(); // null = present
            $table->string('position_title');
            $table->string('agency');
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->string('salary_grade', 10)->nullable(); // "00-0"
            $table->string('appointment_status', 50)->nullable();
            $table->boolean('is_government')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'date_from']);
        });

        // VI. Learning & Development — HR-3 adds its workflow on top.
        Schema::create('pds_learning_developments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('title', 512);
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('hours', 7, 2)->nullable();
            $table->string('ld_type', 50)->nullable();
            $table->string('sponsor', 512)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'date_from']);
        });

        Schema::create('pds_voluntary_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('organization', 512);
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->decimal('hours', 7, 2)->nullable();
            $table->string('position')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // VIII. Items 31–33, one row per entry.
        Schema::create('pds_other_information', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // skill | distinction | membership
            $table->string('detail', 512);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pds_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('contact')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'pds_references', 'pds_other_information', 'pds_voluntary_works', 'pds_learning_developments',
            'pds_work_experiences', 'pds_eligibilities', 'pds_educations', 'pds_children', 'pds_profiles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
