<?php

namespace App\Models\Pds;

class PdsEducation extends PdsEntry
{
    protected $table = 'pds_educations';

    protected function casts(): array
    {
        return ['attendance_from' => 'integer', 'attendance_to' => 'integer', 'year_graduated' => 'integer'];
    }
}
