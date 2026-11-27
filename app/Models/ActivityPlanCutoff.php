<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Calendar of Activities: PALD's plan cut-off for a year. Activities added
 * after it are flagged unplanned. A year without a row cuts off on
 * 31 January.
 */
class ActivityPlanCutoff extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['year', 'cuts_off_on', 'updated_by'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'cuts_off_on' => 'date:Y-m-d',
        ];
    }

    /**
     * The plan cut-off date of the year: the one PALD set, or 31 January.
     */
    public static function for(int $year): Carbon
    {
        return static::query()->find($year)?->cuts_off_on
            ?? Carbon::create($year, 1, 31)->startOfDay();
    }
}
