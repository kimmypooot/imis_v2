<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\DtrAdjustment;
use App\Models\DtrSignature;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An employee signs a finished DTR sheet; the signature image is served
 * only to the employee and to HR.
 */
class DtrSigningTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('signatures/mine.png', 'png');
        $this->employee = Employee::factory()->create();
        $this->employee->user->forceFill(['signature_path' => 'signatures/mine.png'])->save();
    }

    public function test_an_employee_signs_a_finished_sheet_with_their_password_and_it_is_audited(): void
    {
        $this->actingAs($this->employee->user)
            ->post('/my/dtr/sign', ['from' => '2026-10-01', 'to' => '2026-10-31', 'password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $signature = DtrSignature::query()->sole();
        $this->assertSame([$this->employee->id, '2026-10-01', '2026-10-31'], [$signature->employee_id, $signature->period_from->toDateString(), $signature->period_to->toDateString()]);
        $this->assertSame(1, AuditLog::query()->where('event', 'dtr.signed')->count());
    }

    public function test_a_wrong_password_signs_nothing(): void
    {
        $this->actingAs($this->employee->user)
            ->post('/my/dtr/sign', ['from' => '2026-10-01', 'to' => '2026-10-31', 'password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, DtrSignature::query()->count());
    }

    public function test_a_sheet_that_has_not_finished_cannot_be_signed(): void
    {
        $this->actingAs($this->employee->user)
            ->post('/my/dtr/sign', ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString(), 'password' => 'password'])
            ->assertSessionHasErrors('to');

        $this->assertSame(0, DtrSignature::query()->count());
    }

    public function test_without_a_saved_signature_nothing_is_signed(): void
    {
        $this->employee->user->forceFill(['signature_path' => null])->save();

        $this->actingAs($this->employee->user)
            ->post('/my/dtr/sign', ['from' => '2026-10-01', 'to' => '2026-10-31', 'password' => 'password'])
            ->assertSessionHasErrors('signature');
    }

    public function test_a_user_without_an_employee_record_cannot_sign(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/my/dtr/sign', ['from' => '2026-10-01', 'to' => '2026-10-31', 'password' => 'password'])
            ->assertNotFound();
    }

    public function test_sheets_carry_the_signature_and_its_status(): void
    {
        $this->actingAs($this->employee->user)->post('/my/dtr/sign', ['from' => '2026-10-01', 'to' => '2026-10-31', 'password' => 'password']);
        $code = DtrSignature::query()->sole()->code;

        $this->actingAs($this->employee->user)->get('/my/dtr?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sheets.0.signature.code', $code)
                ->where('sheets.0.signature.status', 'valid')
                ->has('sheets.0.signature.image_url')
                ->has('sheets.0.signature.signed_at'));

        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'Event']);

        $this->actingAs($this->employee->user)->get('/my/dtr?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page->where('sheets.0.signature.status', 'changed'));
    }

    public function test_an_unsigned_sheet_has_no_signature_and_the_page_knows_whether_you_have_one_saved(): void
    {
        $this->actingAs($this->employee->user)->get('/my/dtr?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page->where('sheets.0.signature', null)->where('hasSignature', true));

        $this->employee->user->forceFill(['signature_path' => null])->save();

        $this->actingAs($this->employee->user)->get('/my/dtr?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page->where('hasSignature', false));
    }

    public function test_the_print_carries_the_signature_and_the_validation_link(): void
    {
        config(['app.url' => 'https://imis.example.ph']);
        $this->actingAs($this->employee->user)->post('/my/dtr/sign', ['from' => '2026-10-01', 'to' => '2026-10-31', 'password' => 'password']);
        $signature = DtrSignature::query()->sole();

        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sheet.signature.code', $signature->code)
                ->where('sheet.signature.status', 'valid')
                ->where('sheet.signature.verify_url', 'https://imis.example.ph/dtr/verify/'.$signature->code)
                ->where('sheet.signature.image_url', url("/dtr/signatures/{$signature->id}/image")));

        $this->actingAs($this->userWith(['dtr.view']))->get("/admin/hr/employees/{$this->employee->id}/dtr/print?from=2026-10-01&to=2026-10-31")
            ->assertInertia(fn (Assert $page) => $page->where('sheet.signature.code', $signature->code));
    }

    public function test_a_print_after_an_adjustment_says_the_sheet_changed(): void
    {
        $this->actingAs($this->employee->user)->post('/my/dtr/sign', ['from' => '2026-10-01', 'to' => '2026-10-31', 'password' => 'password']);
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'Event']);

        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->where('sheet.signature.status', 'changed'));
    }

    public function test_an_unsigned_print_has_no_signature(): void
    {
        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->where('sheet.signature', null));
    }

    public function test_the_signature_image_goes_to_the_owner_and_to_hr_only(): void
    {
        $signature = DtrSignature::factory()->create(['employee_id' => $this->employee->id, 'signature_path' => 'signatures/mine.png']);
        $url = "/dtr/signatures/{$signature->id}/image";

        $this->actingAs($this->employee->user)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->userWith(['dtr.view']))->get($url)->assertOk();
        $this->actingAs(Employee::factory()->create()->user)->get($url)->assertForbidden();
    }

    public function test_a_missing_signature_file_is_a_404(): void
    {
        $signature = DtrSignature::factory()->create(['employee_id' => $this->employee->id, 'signature_path' => null]);

        $this->actingAs($this->employee->user)->get("/dtr/signatures/{$signature->id}/image")->assertNotFound();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }
        $user->roles()->attach($role);

        return $user;
    }
}
