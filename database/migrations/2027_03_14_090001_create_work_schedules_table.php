<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: work schedules as data. `latest_arrival` is the time after which
     * an arrival is late; time before `start_time` never counts. The two
     * schedules in use at the Regional Office are added here.
     */
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->time('start_time');
            $table->time('latest_arrival');
            $table->unsignedSmallInteger('required_minutes');
            $table->time('break_start')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            // ISO weekdays (1 = Monday to 7 = Sunday) that are rest days besides the weekend.
            $table->json('rest_weekdays');
            $table->timestamps();
        });

        $now = now();
        DB::table('work_schedules')->insert([
            [
                'name' => 'Regular (8:00 to 5:00, noon break)',
                'start_time' => '08:00:00',
                'latest_arrival' => '08:00:00',
                'required_minutes' => 480,
                'break_start' => '12:00:00',
                'break_minutes' => 60,
                'rest_weekdays' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Compressed Work Week (7:00 to 6:00, core time 8:00 to 7:00, no noon break)',
                'start_time' => '07:00:00',
                'latest_arrival' => '08:00:00',
                'required_minutes' => 600,
                'break_start' => null,
                'break_minutes' => 0,
                'rest_weekdays' => json_encode([5]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
