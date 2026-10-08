<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The app now runs on Asia/Manila (config/app.php) and pins MySQL's
     * session to +08:00 (config/database.php). Until now it ran on UTC over
     * the server's own session time zone, so every stored TIMESTAMP is moved
     * by that old session's offset to keep meaning the same instant: +8
     * hours on a server whose clock is on Manila time, nothing on a UTC
     * server. Only MySQL/MariaDB holds data that needs it.
     */
    public function up(): void
    {
        $this->shift(1);
    }

    public function down(): void
    {
        $this->shift(-1);
    }

    private function shift(int $direction): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $minutes = $this->serverOffsetMinutes() * $direction;

        if ($minutes === 0) {
            return;
        }

        $columns = DB::select(
            'select table_name as t, column_name as c from information_schema.columns where table_schema = ? and data_type = ?',
            [DB::getDatabaseName(), 'timestamp'],
        );

        foreach ($columns as $column) {
            DB::update(sprintf(
                'update `%1$s` set `%2$s` = `%2$s` + interval %3$d minute where `%2$s` is not null',
                $column->t,
                $column->c,
                $minutes,
            ));
        }
    }

    /** The offset of the session time zone the app used before: the server's own. */
    private function serverOffsetMinutes(): int
    {
        $pinned = DB::selectOne('select @@session.time_zone as tz')->tz;

        DB::statement('set time_zone = @@global.time_zone');
        $offset = (int) DB::selectOne('select timestampdiff(minute, utc_timestamp(), now()) as m')->m;
        DB::statement('set time_zone = ?', [$pinned]);

        return $offset;
    }
};
