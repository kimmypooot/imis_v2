<?php

namespace App\Models\Pds;

class PdsChild extends PdsEntry
{
    protected $table = 'pds_children';

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d'];
    }
}
