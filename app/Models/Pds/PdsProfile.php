<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single-value items of an employee's PDS (CS Form No. 212, Revised
 * 2026). Identity numbers, the item 34–40 answers and the government ID
 * are encrypted at rest. Written only through PdsWriter.
 */
class PdsProfile extends Model
{
    protected $guarded = ['id', 'employee_id'];

    protected function casts(): array
    {
        return [
            'height_m' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'umid_no' => 'encrypted',
            'pagibig_no' => 'encrypted',
            'philhealth_no' => 'encrypted',
            'philsys_no' => 'encrypted',
            'tin_no' => 'encrypted',
            'permanent_same_as_residential' => 'boolean',
            'questions' => 'encrypted:array',
            'government_id_number' => 'encrypted',
            'government_id_issuance' => 'encrypted',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
