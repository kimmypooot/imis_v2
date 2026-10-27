<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaItem;
use App\Models\PlantillaSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Recruitment Portal's server-to-server view of the plantilla: which items
 * are vacant, and the selections it reports back.
 */
class RecruitmentApiTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $hrd;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.api_token' => 'secret-token']);
        $this->hrd = OrganizationalUnit::factory()->create(['code' => 'HRD', 'name' => 'Human Resource Division']);
    }

    private function item(string $number, array $extra = []): PlantillaItem
    {
        return PlantillaItem::query()->create([
            'item_number' => $number,
            'position_title' => 'Administrative Officer IV',
            'salary_grade' => 15,
            'organizational_unit_id' => $this->hrd->id,
            ...$extra,
        ]);
    }

    public function test_the_endpoints_need_the_shared_token(): void
    {
        $this->getJson('/api/v1/recruitment/vacant-positions')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/recruitment/vacant-positions')->assertUnauthorized();

        config(['recruitment.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/recruitment/vacant-positions')->assertUnauthorized();
    }

    public function test_it_lists_only_active_vacant_items_without_a_pending_selection(): void
    {
        $vacant = $this->item('OSEC-CSC8-1');
        $held = $this->item('OSEC-CSC8-2');
        $abolished = $this->item('OSEC-CSC8-3');
        $selected = $this->item('OSEC-CSC8-4');

        $abolished->forceFill(['abolished_at' => now()])->save();
        $selected->selections()->create(['recruitment_vacancy_id' => 9, 'applicant_name' => 'Ana', 'selected_on' => today(), 'status' => 'pending']);
        $employee = Employee::factory()->create();
        $held->appointments()->make(['date_from' => '2026-01-01'])->forceFill(['employee_id' => $employee->id])->save();

        $rows = $this->withToken('secret-token')->getJson('/api/v1/recruitment/vacant-positions')
            ->assertOk()->json('data');

        $this->assertSame([$vacant->id], array_column($rows, 'id'));
        $this->assertSame('HRD', $rows[0]['office_code']);
        $this->assertSame('OSEC-CSC8-1', $rows[0]['item_number']);
        $this->assertSame(15, $rows[0]['salary_grade']);
    }

    public function test_a_reported_selection_is_recorded_once_per_vacancy_and_hides_the_item(): void
    {
        $item = $this->item('OSEC-CSC8-1');
        $payload = ['recruitment_vacancy_id' => 5, 'applicant_name' => 'Juan Dela Cruz', 'applicant_email' => 'juan@example.com', 'selected_on' => '2026-10-27'];

        $this->withToken('secret-token')->postJson("/api/v1/recruitment/items/{$item->id}/selection", $payload)->assertCreated();
        $this->withToken('secret-token')->postJson("/api/v1/recruitment/items/{$item->id}/selection", [...$payload, 'applicant_name' => 'Juan D. Cruz'])->assertOk();

        $this->assertSame(1, PlantillaSelection::query()->count());
        $this->assertSame('Juan D. Cruz', PlantillaSelection::query()->first()->applicant_name);
        $this->withToken('secret-token')->getJson('/api/v1/recruitment/vacant-positions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_selection_for_an_abolished_item_is_refused(): void
    {
        $item = $this->item('OSEC-CSC8-1');
        $item->forceFill(['abolished_at' => now()])->save();

        $this->withToken('secret-token')->postJson("/api/v1/recruitment/items/{$item->id}/selection", [
            'recruitment_vacancy_id' => 5, 'applicant_name' => 'Juan', 'selected_on' => '2026-10-27',
        ])->assertUnprocessable();
    }
}
