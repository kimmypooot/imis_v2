<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DTR: the person an employee picked to verify one sheet, printed under
 * "Office Head Name/Signature". One row per employee and period; without
 * one, the office's head applies. Written by App\Support\HR\Dtr\DtrSignatories.
 */
class DtrSignatory extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signatory_user_id');
    }

    /**
     * The sheet's chosen signatory, if the employee picked one.
     */
    public static function forSheet(int $employeeId, Carbon $from, Carbon $to): ?self
    {
        return self::query()
            ->where('employee_id', $employeeId)
            ->whereDate('period_from', $from->toDateString())
            ->whereDate('period_to', $to->toDateString())
            ->first();
    }
}
