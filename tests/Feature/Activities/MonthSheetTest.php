<?php

namespace Tests\Feature\Activities;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityMove;
use App\Models\Holiday;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Activities\MonthSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Calendar of Activities: the monthly sheet in the reference layout (title,
 * date rows, holiday rows, time and remarks text, dates to be announced),
 * its print page and its Excel download.
 */
class MonthSheetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The migrations seed the official holidays; each test enters its own.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Holiday::query()->delete();
    }

    public function test_title_and_day_labels(): void
    {
        Activity::factory()->dated('2025-12-03')->create(['title' => 'Staff meeting']);

        $sheet = MonthSheet::build(2025, 12);

        $this->assertSame('CSC RO VIII CALENDAR OF ACTIVITIES for DECEMBER 2025', $sheet['title']);
        $this->assertCount(1, $sheet['days']);
        $this->assertSame('3 December 2025 (Wednesday)', $sheet['days'][0]['label']);
        $this->assertSame('Staff meeting', $sheet['days'][0]['rows'][0]['activity']);
        $this->assertSame('Face-to-face', $sheet['days'][0]['rows'][0]['mode']);
    }

    public function test_activity_across_a_month_boundary_appears_in_both_months(): void
    {
        Activity::factory()->dated('2025-11-29', '2025-12-02')->create(['title' => 'Planning workshop']);

        $november = MonthSheet::build(2025, 11);
        $december = MonthSheet::build(2025, 12);

        $this->assertSame(['29 November 2025 (Saturday)', '30 November 2025 (Sunday)'], array_column($november['days'], 'label'));
        $this->assertSame(['1 December 2025 (Monday)', '2 December 2025 (Tuesday)'], array_column($december['days'], 'label'));
        $this->assertSame('Planning workshop', $december['days'][1]['rows'][0]['activity']);
    }

    public function test_holiday_rows_come_from_holiday_calendar(): void
    {
        Holiday::query()->create(['date' => '2000-12-25', 'name' => 'Christmas Day', 'type' => 'regular', 'repeats_annually' => true]);
        Holiday::query()->create(['date' => '2025-12-27', 'name' => 'A working holiday', 'type' => 'special_working']);
        Activity::factory()->dated('2025-12-25')->create(['title' => 'Skeleton crew', 'starts_at' => '08:00:00']);

        $days = MonthSheet::build(2025, 12)['days'];

        $this->assertSame(['25 December 2025 (Thursday)'], array_column($days, 'label'));
        $this->assertSame(
            ['time' => 'HOLIDAY', 'mode' => '', 'venue' => '', 'participants' => '', 'activity' => 'Christmas Day', 'responsible' => '', 'remarks' => '', 'cancelled' => false],
            $days[0]['rows'][0],
        );
        $this->assertSame('Skeleton crew', $days[0]['rows'][1]['activity']);
        $this->assertSame('Christmas Day', $days[0]['holiday']);
    }

    public function test_time_text_formats(): void
    {
        $this->assertSame('8:00 AM - 10:00 AM', MonthSheet::timeText(new Activity(['starts_at' => '08:00:00', 'ends_at' => '10:00:00', 'all_day' => false])));
        $this->assertSame('12:00 NN - 1:00 PM', MonthSheet::timeText(new Activity(['starts_at' => '12:00:00', 'ends_at' => '13:00:00', 'all_day' => false])));
        $this->assertSame('1:30 PM - onwards', MonthSheet::timeText(new Activity(['starts_at' => '13:30:00', 'ends_at' => null, 'all_day' => false])));
        $this->assertSame('Whole day', MonthSheet::timeText(new Activity(['starts_at' => null, 'ends_at' => null, 'all_day' => true])));
    }

    public function test_remarks_text_combines_moved_cancelled_unplanned(): void
    {
        $activity = Activity::factory()->dated('2025-12-05')->create([
            'remarks' => 'Bring laptops',
            'status' => ActivityStatus::Cancelled,
            'cancel_reason' => 'Typhoon',
            'unplanned' => true,
        ]);
        ActivityMove::query()->create(['activity_id' => $activity->id, 'from_starts_on' => '2025-12-01', 'from_ends_on' => '2025-12-01', 'to_starts_on' => '2025-12-02', 'to_ends_on' => '2025-12-02', 'reason' => 'First']);
        $latest = ActivityMove::query()->create(['activity_id' => $activity->id, 'from_starts_on' => '2025-12-02', 'from_ends_on' => '2025-12-02', 'to_starts_on' => '2025-12-05', 'to_ends_on' => '2025-12-05', 'reason' => 'Second']);
        $latest->forceFill(['created_at' => now()->addMinute()])->save();

        $this->assertSame(
            'Bring laptops; Moved from 2 December; Cancelled: Typhoon; Unplanned',
            MonthSheet::remarksText($activity->fresh('moves')),
        );

        $row = MonthSheet::build(2025, 12)['days'][0]['rows'][0];
        $this->assertTrue($row['cancelled']);
        $this->assertSame('Bring laptops; Moved from 2 December; Cancelled: Typhoon; Unplanned', $row['remarks']);
        $this->assertSame('', MonthSheet::remarksText(Activity::factory()->dated('2025-12-06')->create()));
    }

    public function test_month_only_activities_are_listed_as_dates_to_be_announced(): void
    {
        $office = OrganizationalUnit::factory()->create(['short_name' => 'PALD']);
        Activity::factory()->monthOnly(2025, 12)->create(['title' => 'Team building', 'organizational_unit_id' => $office->id]);
        Activity::factory()->monthOnly(2026, 1)->create(['title' => 'Next month']);

        $sheet = MonthSheet::build(2025, 12);

        $this->assertSame([], $sheet['days']);
        $this->assertCount(1, $sheet['tba']);
        $this->assertSame('Team building', $sheet['tba'][0]['activity']);
        $this->assertSame('PALD', $sheet['tba'][0]['responsible']);
    }

    public function test_office_filter_limits_rows(): void
    {
        $mine = OrganizationalUnit::factory()->create();
        $other = OrganizationalUnit::factory()->create();
        Activity::factory()->dated('2025-12-03')->create(['title' => 'Mine', 'organizational_unit_id' => $mine->id, 'responsible' => 'BAC']);
        Activity::factory()->dated('2025-12-04')->create(['title' => 'Theirs', 'organizational_unit_id' => $other->id]);

        $days = MonthSheet::build(2025, 12, $mine->id)['days'];

        $this->assertCount(1, $days);
        $this->assertSame('Mine', $days[0]['rows'][0]['activity']);
        $this->assertSame('BAC', $days[0]['rows'][0]['responsible']);
    }

    public function test_rows_are_sorted_with_whole_day_first(): void
    {
        Activity::factory()->dated('2025-12-03')->create(['title' => 'Afternoon', 'starts_at' => '13:00:00', 'ends_at' => '15:00:00']);
        Activity::factory()->dated('2025-12-03')->create(['title' => 'Morning', 'starts_at' => '08:00:00', 'ends_at' => '10:00:00']);
        Activity::factory()->dated('2025-12-03')->create(['title' => 'All day', 'starts_at' => null, 'ends_at' => null, 'all_day' => true]);

        $rows = MonthSheet::build(2025, 12)['days'][0]['rows'];

        $this->assertSame(['All day', 'Morning', 'Afternoon'], array_column($rows, 'activity'));
    }

    public function test_excel_download_is_an_xlsx_with_the_title(): void
    {
        Activity::factory()->dated('2025-12-03')->create(['title' => 'Staff meeting']);
        $user = $this->viewer();

        $response = $this->actingAs($user)->get('/activities/sheet/excel?month=2025-12')->assertOk();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('CSC RO VIII Calendar of Activities - December 2025.xlsx', $response->headers->get('Content-Disposition'));

        $files = $this->unzip($response->getContent());
        $this->assertStringContainsString('CSC RO VIII CALENDAR OF ACTIVITIES for DECEMBER 2025', $files['xl/sharedStrings.xml']);
        $this->assertStringContainsString('Staff meeting', $files['xl/sharedStrings.xml']);
        $this->assertStringContainsString('Poppins', $files['xl/styles.xml']);
        $this->assertStringContainsString('name="December"', $files['xl/workbook.xml']);
    }

    public function test_excel_for_a_whole_year_has_a_tab_per_month(): void
    {
        $response = $this->actingAs($this->viewer())->get('/activities/sheet/excel?year=2025')->assertOk();

        $this->assertStringContainsString('CSC RO VIII Calendar of Activities - 2025.xlsx', $response->headers->get('Content-Disposition'));
        $workbook = $this->unzip($response->getContent())['xl/workbook.xml'];
        $this->assertStringContainsString('name="January"', $workbook);
        $this->assertStringContainsString('name="December"', $workbook);
    }

    public function test_print_page_renders_the_sheet(): void
    {
        Activity::factory()->dated('2025-12-03')->create();

        $this->actingAs($this->viewer())->get('/activities/sheet/print?month=2025-12')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Activities/SheetPrint')
                ->where('sheet.title', 'CSC RO VIII CALENDAR OF ACTIVITIES for DECEMBER 2025')
                ->has('sheet.days', 1));
    }

    public function test_print_and_excel_follow_the_mode_filter(): void
    {
        Activity::factory()->dated('2025-12-03')->create(['title' => 'In the board room', 'mode' => 'face_to_face']);
        Activity::factory()->dated('2025-12-04')->create(['title' => 'Over Zoom', 'mode' => 'online']);
        $viewer = $this->viewer();

        $this->actingAs($viewer)->get('/activities/sheet/print?month=2025-12&mode=online')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('sheet.days', 1)->where('sheet.days.0.rows.0.activity', 'Over Zoom'));

        $strings = $this->unzip($this->actingAs($viewer)->get('/activities/sheet/excel?month=2025-12&mode=online')->assertOk()->getContent())['xl/sharedStrings.xml'];
        $this->assertStringContainsString('Over Zoom', $strings);
        $this->assertStringNotContainsString('In the board room', $strings);

        $this->actingAs($viewer)->get('/activities/sheet/print?month=2025-12&mode=carrier_pigeon')->assertSessionHasErrors('mode');
    }

    public function test_sheet_needs_activities_view_and_valid_filters(): void
    {
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get('/activities/sheet/print?month=2025-12')->assertForbidden();
        $this->actingAs($outsider)->get('/activities/sheet/excel?month=2025-12')->assertForbidden();

        $viewer = $this->viewer();
        $this->actingAs($viewer)->get('/activities/sheet/print?month=december')->assertSessionHasErrors('month');
        $this->actingAs($viewer)->get('/activities/sheet/excel?month=2025-12&office=999999')->assertSessionHasErrors('office');
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'activities.view'], ['display_name' => 'activities.view']));
        $user->roles()->attach($role);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $files;
    }
}
