<?php

namespace App\Models\Pds;

class PdsEligibility extends PdsEntry
{
    protected $table = 'pds_eligibilities';

    protected function casts(): array
    {
        return ['exam_date' => 'date:Y-m-d', 'license_valid_until' => 'date:Y-m-d'];
    }
}
