<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Support\HR\LoyaltyAwards;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Years in the CSC for loyalty awards: from the date someone joined the
 * CSC (if they came from another agency), else the original appointment;
 * 10 years, then every 5.
 */
class LoyaltyAwardsTest extends TestCase
{
    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = Carbon::parse('2026-10-05');
    }

    public function test_service_from_another_agency_does_not_count(): void
    {
        $summary = $this->summary(['original_appointment_date' => '2008-06-16', 'csc_entry_date' => '2019-01-02']);

        $this->assertSame('2019-01-02', $summary['since']);
        $this->assertTrue($summary['from_other_agency']);
        $this->assertSame([7, 9, '7 yrs 9 mos'], [$summary['years'], $summary['months'], $summary['label']]);
        $this->assertNull($summary['last_award']);
        $this->assertSame([10, '2029-01-02'], [$summary['next_award'], $summary['next_award_date']]);
        $this->assertNull($summary['award_this_year']);
    }

    public function test_without_a_csc_entry_date_it_counts_from_the_original_appointment(): void
    {
        $summary = $this->summary(['original_appointment_date' => '2004-11-01']);

        $this->assertFalse($summary['from_other_agency']);
        $this->assertSame(21, $summary['years']);
        $this->assertSame(20, $summary['last_award']);
        $this->assertSame([25, '2029-11-01'], [$summary['next_award'], $summary['next_award_date']]);
    }

    public function test_a_milestone_completed_this_year_is_flagged(): void
    {
        $this->assertSame(15, $this->summary(['original_appointment_date' => '2011-12-01'])['award_this_year']);
        $this->assertSame(10, $this->summary(['original_appointment_date' => '2016-02-01'])['award_this_year']);
        $this->assertNull($this->summary(['original_appointment_date' => '2018-02-01'])['award_this_year']);
    }

    public function test_service_stops_at_separation_and_no_award_is_due(): void
    {
        $summary = $this->summary(['original_appointment_date' => '2010-01-04', 'separation_date' => '2025-07-01']);

        $this->assertSame(15, $summary['years']);
        $this->assertNull($summary['next_award']);
        $this->assertNull($summary['award_this_year']);
    }

    public function test_no_dates_no_summary(): void
    {
        $this->assertNull($this->summary([]));
    }

    /**
     * @param  array<string, string>  $dates
     */
    private function summary(array $dates): ?array
    {
        $employee = new Employee;
        foreach (['original_appointment_date', 'csc_entry_date', 'separation_date'] as $field) {
            $employee->{$field} = $dates[$field] ?? null;
        }

        return (new LoyaltyAwards)->summary($employee, $this->today);
    }
}
