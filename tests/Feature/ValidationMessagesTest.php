<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Support\HR\Saln\SalnStatement;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Validation messages name fields in words (lang/en/validation.php), never
 * by their request keys, and conditional rules say what to do rather than
 * "when x is y".
 */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_correction_request_names_the_item_to_correct(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)
            ->post('/my/corrections', [])
            ->assertSessionHasErrors(['field' => 'The item to correct field is required.']);
    }

    public function test_leave_without_dates_asks_for_a_start_date_or_picked_dates(): void
    {
        $this->seed(LeaveTypeSeeder::class);
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)
            ->post('/my/leave/applications', ['leave_type_id' => 1])
            ->assertSessionHasErrors([
                'date_from' => 'Enter the start date, or pick the dates.',
                'date_to' => 'Enter the end date, or pick the dates.',
            ]);
    }

    public function test_saln_rows_are_named_without_their_keys(): void
    {
        $errors = Validator::make(
            ['real_properties' => [['acquisition_cost' => null]], 'declarant' => ['family_name' => null], 'no_relatives' => false],
            ['real_properties.*.acquisition_cost' => 'required', 'declarant.family_name' => 'required', 'relatives' => SalnStatement::rules()['relatives']],
        )->errors();

        $this->assertSame('The acquisition cost field is required.', $errors->first('real_properties.0.acquisition_cost'));
        $this->assertSame('The family name field is required.', $errors->first('declarant.family_name'));
        $this->assertSame('List your relatives in government, or tick that you have none.', $errors->first('relatives'));
    }
}
