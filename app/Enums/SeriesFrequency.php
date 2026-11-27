<?php

namespace App\Enums;

/**
 * Calendar of Activities: how often a repeating activity recurs. `daily`
 * means weekdays only. There is no yearly repeat: a once-a-year activity
 * is part of each year's plan.
 */
enum SeriesFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
