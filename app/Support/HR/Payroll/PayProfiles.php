<?php

namespace App\Support\HR\Payroll;

use App\Enums\PayCategory;
use App\Enums\PayComponentKind;
use App\Enums\PayComputation;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\PayComponent;
use App\Models\PayrollLine;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * HR-8: the single writer for pay components (allowance, deduction and
 * loan types), each person's recurring pay items, and their loans.
 *
 * - A loan type is always entered per loan (method "manual") and never
 *   automatic: the amounts live on each EmployeeLoan.
 * - A component used anywhere can't be deleted, only deactivated, so old
 *   payrolls and payslips keep their meaning.
 * - A loan already deducted on a payroll can't be deleted, only stopped.
 */
class PayProfiles
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function componentRules(?PayComponent $ignore = null): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('pay_components', 'code')->ignore($ignore)],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', new Enum(PayComponentKind::class)],
            'category' => ['nullable', new Enum(PayCategory::class)],
            'method' => ['required', new Enum(PayComputation::class)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'required_if:method,fixed'],
            'rate' => ['nullable', 'numeric', 'gt:0', 'max:100', 'required_if:method,percent'],
            'base_min' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'base_max' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'gte:base_min'],
            'automatic' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function saveComponent(?PayComponent $component, array $data): PayComponent
    {
        $kind = PayComponentKind::from($data['kind']);
        $method = $kind === PayComponentKind::Loan ? PayComputation::Manual : PayComputation::from($data['method']);

        $component ??= new PayComponent;
        $component->fill([
            'code' => mb_strtoupper($data['code']),
            'name' => $data['name'],
            'kind' => $kind,
            'category' => $data['category'] ?? PayCategory::defaultFor($kind)->value,
            'method' => $method,
            // Only what the method uses is kept.
            'amount' => $method === PayComputation::Fixed ? $data['amount'] : null,
            'rate' => $method === PayComputation::Percent ? $data['rate'] : null,
            'base_min' => $method === PayComputation::Percent ? ($data['base_min'] ?? null) : null,
            'base_max' => $method === PayComputation::Percent ? ($data['base_max'] ?? null) : null,
            'automatic' => $kind !== PayComponentKind::Loan && (bool) ($data['automatic'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => $data['sort_order'] ?? 0,
            'remarks' => $data['remarks'] ?? null,
        ])->save();

        return $component;
    }

    public function componentInUse(PayComponent $component): bool
    {
        return $component->payItems()->exists() || $component->loans()->exists()
            || PayrollLine::query()->where('pay_component_id', $component->id)->exists();
    }

    public function deleteComponent(PayComponent $component): void
    {
        if ($this->componentInUse($component)) {
            throw ValidationException::withMessages(['component' => 'It is on pay profiles, loans or payrolls; deactivate it instead.']);
        }

        $component->delete();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function itemRules(): array
    {
        return [
            'pay_component_id' => ['required', 'integer', Rule::exists('pay_components', 'id')->where('is_active', true)->whereNot('kind', PayComponentKind::Loan->value)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'effective_from' => ['required', 'date_format:Y-m'],
            'effective_to' => ['nullable', 'date_format:Y-m', 'after_or_equal:effective_from'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Months are entered as YYYY-MM: an item runs from the first of its
     * first month to the last day of its last.
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function saveItem(Employee $employee, ?EmployeePayItem $item, array $data): EmployeePayItem
    {
        $component = PayComponent::query()->findOrFail($data['pay_component_id']);
        if ($component->method === PayComputation::Manual && ($data['amount'] ?? null) === null) {
            throw ValidationException::withMessages(['amount' => "{$component->name} is entered per employee: give the amount."]);
        }

        $item ??= new EmployeePayItem;
        $item->fill([
            'pay_component_id' => $component->id,
            'amount' => $data['amount'] ?? null,
            'effective_from' => Carbon::createFromFormat('!Y-m', $data['effective_from'])->startOfMonth(),
            'effective_to' => isset($data['effective_to']) ? Carbon::createFromFormat('!Y-m', $data['effective_to'])->endOfMonth() : null,
            'remarks' => $data['remarks'] ?? null,
        ]);
        $item->employee()->associate($employee)->save();

        return $item;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function loanRules(): array
    {
        return [
            'pay_component_id' => ['required', 'integer', Rule::exists('pay_components', 'id')->where('kind', PayComponentKind::Loan->value)],
            'reference' => ['nullable', 'string', 'max:60'],
            'granted_on' => ['nullable', 'date', 'before_or_equal:today'],
            'principal' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'total_payable' => ['required', 'numeric', 'gte:principal', 'max:99999999'],
            'monthly_amortization' => ['required', 'numeric', 'gt:0', 'lte:total_payable'],
            'start_month' => ['required', 'date_format:Y-m'],
            'paid_before' => ['nullable', 'numeric', 'min:0', 'lte:total_payable'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function saveLoan(Employee $employee, ?EmployeeLoan $loan, array $data): EmployeeLoan
    {
        $loan ??= new EmployeeLoan;
        $loan->fill([
            'pay_component_id' => $data['pay_component_id'],
            'reference' => $data['reference'] ?? null,
            'granted_on' => $data['granted_on'] ?? null,
            'principal' => $data['principal'],
            'total_payable' => $data['total_payable'],
            'monthly_amortization' => $data['monthly_amortization'],
            'start_month' => Carbon::createFromFormat('!Y-m', $data['start_month'])->startOfMonth(),
            'paid_before' => $data['paid_before'] ?? 0,
            'remarks' => $data['remarks'] ?? null,
        ]);
        $loan->employee()->associate($employee)->save();

        return $loan;
    }

    /** No more deductions from the month after $date; the balance stays on record. */
    public function stopLoan(EmployeeLoan $loan, string $date): void
    {
        $loan->forceFill(['stopped_on' => $date])->save();
    }

    public function resumeLoan(EmployeeLoan $loan): void
    {
        if ($loan->cancelled_on) {
            throw ValidationException::withMessages(['loan' => 'A cancelled loan cannot be resumed; add a new one.']);
        }

        $loan->forceFill(['stopped_on' => null])->save();
    }

    /** Ends the loan for good: no more deductions, history kept. */
    public function cancelLoan(EmployeeLoan $loan, string $date): void
    {
        $loan->forceFill(['stopped_on' => $date, 'cancelled_on' => $date])->save();
    }

    public function deleteLoan(EmployeeLoan $loan): void
    {
        if ($loan->payrollLines()->exists()) {
            throw ValidationException::withMessages(['loan' => 'It was already deducted on a payroll; stop it instead.']);
        }

        $loan->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentComponent(PayComponent $component): array
    {
        return [
            'id' => $component->id,
            'code' => $component->code,
            'name' => $component->name,
            'kind' => $component->kind->value,
            'kind_label' => $component->kind->label(),
            'category' => $component->category?->value,
            'category_label' => $component->category?->label(),
            'method' => $component->method->value,
            'method_label' => $component->method->label(),
            'amount' => $component->amount !== null ? (float) $component->amount : null,
            'rate' => $component->rate !== null ? (float) $component->rate : null,
            'base_min' => $component->base_min !== null ? (float) $component->base_min : null,
            'base_max' => $component->base_max !== null ? (float) $component->base_max : null,
            'automatic' => $component->automatic,
            'is_active' => $component->is_active,
            'sort_order' => $component->sort_order,
            'remarks' => $component->remarks,
            'rule' => $this->describe($component),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentItem(EmployeePayItem $item): array
    {
        return [
            'id' => $item->id,
            'pay_component_id' => $item->pay_component_id,
            'component' => $item->component->name,
            'kind' => $item->component->kind->value,
            'amount' => $item->amount !== null ? (float) $item->amount : null,
            'rule' => $this->describe($item->component),
            'effective_from' => $item->effective_from->format('Y-m'),
            'effective_to' => $item->effective_to?->format('Y-m'),
            'current' => $item->effective_from->lte(today()) && (! $item->effective_to || $item->effective_to->gte(today())),
            'remarks' => $item->remarks,
        ];
    }

    /** "₱2,000.00", "9% of basic", "2.5% of basic (on 10,000–100,000)", "Per employee". */
    public function describe(PayComponent $component): string
    {
        $money = fn ($value) => '₱'.number_format((float) $value, 2);

        return match ($component->method) {
            PayComputation::Fixed => $money($component->amount),
            PayComputation::Percent => rtrim(rtrim(number_format((float) $component->rate, 3), '0'), '.').'% of basic'
                .($component->base_min !== null || $component->base_max !== null
                    ? ' (on '.($component->base_min !== null ? number_format((float) $component->base_min) : '0').'–'.($component->base_max !== null ? number_format((float) $component->base_max) : 'any').')'
                    : ''),
            PayComputation::Manual => 'Per employee',
        };
    }
}
