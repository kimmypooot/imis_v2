<?php

namespace App\Models\Pds;

class PdsWorkExperience extends PdsEntry
{
    protected $table = 'pds_work_experiences';

    protected function casts(): array
    {
        return ['date_from' => 'date:Y-m-d', 'date_to' => 'date:Y-m-d', 'monthly_salary' => 'decimal:2', 'is_government' => 'boolean'];
    }
}
