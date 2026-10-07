<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\PlantillaAppointment;
use App\Models\User;
use App\Support\HR\Payroll\PayProfiles;
use App\Support\HR\Payroll\Payrolls;
use App\Support\HR\Payroll\PayslipApprovers;
use App\Support\HR\Payroll\PayslipRequests;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * HR-8 sample data for trying payroll out locally — never on the live
 * server, and not part of DatabaseSeeder. Run it on its own:
 *
 *     php artisan db:seed --class=PayrollDemoSeeder
 *
 * It gives `kmbanoyo` and five other staff in service (with complete
 * plantilla periods; never the head of HRD, who approves) recurring items
 * and loans, drafts and finalizes the August and September 2026 payrolls
 * (which, like any payroll, cover everyone in service), adds one HR
 * adjustment, and files payslip requests in every state: approved,
 * pending, rejected and cancelled. The amounts are made up.
 *
 * It refuses to run if either month already has a payroll, and creates
 * pay items and loans only if they aren't there yet.
 */
class PayrollDemoSeeder extends Seeder
{
    private const USERNAME = 'kmbanoyo';

    private const MONTHS = ['2026-08', '2026-09'];

    public function run(PayProfiles $profiles, Payrolls $payrolls, PayslipRequests $requests, PayslipApprovers $approvers): void
    {
        if (! app()->environment('local')) {
            $this->command->error('PayrollDemoSeeder only runs locally (APP_ENV=local).');

            return;
        }

        $existing = PayrollRun::query()->get()->filter(fn (PayrollRun $run) => in_array($run->month()->format('Y-m'), self::MONTHS, true));
        if ($existing->isNotEmpty()) {
            $this->command->error('There are already payrolls for '.$existing->map->period->implode(' and ').'; delete them first (Payroll → open → Delete draft, or reopen then delete).');

            return;
        }

        $me = User::query()->where('username', self::USERNAME)->first();
        if (! $me?->employee) {
            $this->command->error('No employee record for '.self::USERNAME.'.');

            return;
        }

        $chief = $approvers->chief();
        $people = $this->people($me->employee, $chief);
        if ($people->count() < 6) {
            $this->command->error('Need '.self::USERNAME.' and five other staff with complete plantilla periods; found '.($people->count() - 1).' others.');

            return;
        }

        // [recurring items: code => amount], [loan: code, reference, principal, total payable, amortization, first month, paid before]
        $plans = [
            [['WTAX' => 850, 'HDMF-MP2' => 500], ['GSIS-MPL', 'MPL-2026-00412', 50000, 54000, 1500, '2026-03', 7500]],
            [['WTAX' => 2150], ['GSIS-CONSO', 'CONSO-2024-01187', 200000, 240000, 4000, '2024-01', 120000]],
            // Nearly paid: 1,250 left once August and September are finalized.
            [['WTAX' => 1200], ['HDMF-MPL', 'HQP-MPL-2025-3321', 25000, 30000, 1250, '2025-01', 26250]],
            [['WELFARE' => 300], ['COOP-LOAN', 'COOP-L-0098', 20000, 22000, 2000, '2026-06', 4000]],
            [['WTAX' => 600], ['LBP', 'LBP-SL-77120', 60000, 66000, 2750, '2026-01', 11000]],
            // A voluntary higher Pag-IBIG contribution overrides the automatic 2%.
            [['HDMF' => 400], ['GSIS-EML', 'EML-2026-0450', 20000, 21000, 1750, '2026-08', 0]],
        ];

        foreach ($people->values() as $index => $employee) {
            [$items, $loan] = $plans[$index];
            $this->items($profiles, $employee, $items);
            $this->loan($profiles, $employee, ...$loan);
        }

        // The two payrolls, oldest first so loans count August before September.
        $runs = [];
        foreach (self::MONTHS as $month) {
            $runs[$month] = $payrolls->create($month, 'Sample data (PayrollDemoSeeder).', $me);
        }

        // An HR adjustment on August: one day of absence for the sixth person.
        $absent = $runs['2026-08']->entries()->where('employee_id', $people->values()[5]->id)->sole();
        $payrolls->addLine($absent, ['kind' => 'deduction', 'label' => 'Absences (1 day, Aug 14)', 'amount' => round((float) $absent->basic_salary / 22, 2)]);

        foreach ($runs as $run) {
            $payrolls->finalize($run, $me);
        }

        // Payslip requests in every state.
        $entry = fn (int $index, string $month) => $runs[$month]->entries()->where('employee_id', $people->values()[$index]->id)->sole();
        $request = fn (int $index, string $month, ?string $purpose) => $requests->request($people->values()[$index], $entry($index, $month)->load('run'), $purpose);

        $mine = $request(0, '2026-09', 'Bank loan application');
        $request(0, '2026-08', 'Personal records');
        $request(1, '2026-09', 'Pag-IBIG housing loan');
        $approvedOther = $request(2, '2026-09', null);
        $rejected = $request(3, '2026-08', 'Personal records');
        $requests->cancel($request(4, '2026-09', null));
        $request(5, '2026-09', 'Visa application');

        if ($chief) {
            $requests->decide($mine, $chief, true, null);
            $requests->decide($approvedOther, $chief, true, null);
            $requests->decide($rejected, $chief, false, 'Please request September instead: August had an adjustment that is being reviewed.');
        } else {
            $this->command->warn('No head of HRD found, so every request was left pending.');
        }

        $this->command->info('Seeded: '.$people->map(fn (Employee $employee) => $employee->user->name)->implode(', ').'.');
        $this->command->info('Payrolls for August and September 2026 finalized; 7 payslip requests filed'.($chief ? ", decided by {$chief->name} where approved or rejected." : '.'));
    }

    /**
     * `kmbanoyo` first, then five others in service with a complete open
     * plantilla period — not the head of HRD.
     *
     * @return Collection<int, Employee>
     */
    private function people(Employee $me, ?User $chief): Collection
    {
        $others = PlantillaAppointment::query()->complete()->whereNull('date_to')
            ->whereHas('employee', fn ($query) => $query->whereNull('separation_date')
                ->whereKeyNot($me->id)
                ->when($chief, fn ($query) => $query->where('user_id', '!=', $chief->id))
                ->whereHas('user', fn ($query) => $query->where('status', 'active')))
            ->with('employee.user')->get()
            ->pluck('employee')
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->take(5);

        return collect([$me->load('user')])->merge($others)->values();
    }

    /**
     * @param  array<string, float>  $items
     */
    private function items(PayProfiles $profiles, Employee $employee, array $items): void
    {
        foreach ($items as $code => $amount) {
            $component = PayComponent::query()->where('code', $code)->firstOrFail();
            if (! $employee->payItems()->where('pay_component_id', $component->id)->exists()) {
                $profiles->saveItem($employee, null, ['pay_component_id' => $component->id, 'amount' => $amount, 'effective_from' => '2026-01', 'remarks' => 'Sample data.']);
            }
        }
    }

    private function loan(PayProfiles $profiles, Employee $employee, string $code, string $reference, float $principal, float $total, float $amortization, string $start, float $paidBefore): void
    {
        if ($employee->loans()->where('reference', $reference)->exists()) {
            return;
        }

        $profiles->saveLoan($employee, null, [
            'pay_component_id' => PayComponent::query()->where('code', $code)->firstOrFail()->id,
            'reference' => $reference,
            'principal' => $principal,
            'total_payable' => $total,
            'monthly_amortization' => $amortization,
            'start_month' => $start,
            'paid_before' => $paidBefore,
            'remarks' => 'Sample data.',
        ]);
    }
}
