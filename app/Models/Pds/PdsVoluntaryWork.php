<?php

namespace App\Models\Pds;

class PdsVoluntaryWork extends PdsEntry
{
    protected $table = 'pds_voluntary_works';

    protected function casts(): array
    {
        return ['date_from' => 'date:Y-m-d', 'date_to' => 'date:Y-m-d', 'hours' => 'decimal:2'];
    }
}
