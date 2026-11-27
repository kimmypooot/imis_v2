<?php

namespace App\Support\HR;

use App\Enums\DataCorrectionStatus;
use App\Enums\PermissionName;
use App\Models\DataCorrectionRequest;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\HrUpdate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The single writer for data correction requests. A staff member asks to
 * change one field of their own employee record, and says why; HR approves
 * (the change is applied here, in the same transaction) or rejects it with
 * a reason. Both sides are told under the bell. Only the fields in
 * fields() can be asked for: where someone works and the position they
 * hold change through reassignments and plantilla appointments instead.
 * One pending request per field.
 */
class DataCorrections
{
    /**
     * The correctable fields: where each lives, its label, and how a new
     * value is validated.
     *
     * @return array<string, array{label: string, on: string, type: string}>
     */
    public static function fields(): array
    {
        return [
            'first_name' => ['label' => 'First name', 'on' => 'user', 'type' => 'text'],
            'middle_name' => ['label' => 'Middle name', 'on' => 'user', 'type' => 'text'],
            'last_name' => ['label' => 'Last name', 'on' => 'user', 'type' => 'text'],
            'name_extension' => ['label' => 'Name extension (Jr., III)', 'on' => 'user', 'type' => 'text'],
            'sex' => ['label' => 'Sex', 'on' => 'user', 'type' => 'sex'],
            'birthday' => ['label' => 'Birthday', 'on' => 'user', 'type' => 'date'],
            'email' => ['label' => 'Email address', 'on' => 'user', 'type' => 'email'],
            'employee_number' => ['label' => 'Employee number', 'on' => 'employee', 'type' => 'text'],
            'original_appointment_date' => ['label' => 'Date of original appointment', 'on' => 'employee', 'type' => 'date'],
            'csc_entry_date' => ['label' => 'Date of entry to CSC', 'on' => 'employee', 'type' => 'date'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, type: string}>
     */
    public static function fieldOptions(): array
    {
        return collect(self::fields())->map(fn (array $field, string $key) => ['value' => $key, 'label' => $field['label'], 'type' => $field['type']])->values()->all();
    }

    /**
     * What the record holds now for each correctable field, as text.
     *
     * @return array<string, string|null>
     */
    public function currentValues(Employee $employee): array
    {
        return collect(self::fields())->mapWithKeys(fn (array $field, string $key) => [$key => $this->read($employee, $key)])->all();
    }

    public function request(Employee $employee, string $field, ?string $proposed, string $reason): DataCorrectionRequest
    {
        $definition = self::fields()[$field] ?? throw ValidationException::withMessages(['field' => 'That field cannot be corrected here.']);
        $proposed = $this->normalize($employee, $field, $proposed);
        $current = $this->read($employee, $field);

        if ($proposed === ($current ?? '')) {
            throw ValidationException::withMessages(['proposed_value' => 'That is what your record already says.']);
        }
        if (DataCorrectionRequest::query()->where('employee_id', $employee->id)->where('field', $field)->where('status', DataCorrectionStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['field' => "You already have a pending request for {$definition['label']}."]);
        }

        $request = DB::transaction(function () use ($employee, $field, $current, $proposed, $reason) {
            $request = DataCorrectionRequest::query()->create([
                'employee_id' => $employee->id,
                'field' => $field,
                'current_value' => $current,
                'proposed_value' => $proposed,
                'reason' => $reason,
                'status' => DataCorrectionStatus::Pending,
            ]);
            $request->forceFill(['reference_number' => sprintf('DC-%d-%05d', today()->year, $request->id)])->save();

            return $request;
        });

        Notification::send(
            $this->reviewers()->reject(fn (User $user) => $user->id === $employee->user_id),
            new HrUpdate(
                'Record correction for review',
                "{$employee->user->name} asks to change {$definition['label']} ({$request->reference_number}).",
                '/admin/hr/corrections',
            ),
        );

        return $request;
    }

    public function cancel(DataCorrectionRequest $request): void
    {
        if ($request->status !== DataCorrectionStatus::Pending) {
            throw ValidationException::withMessages(['request' => 'Only a pending request can be cancelled.']);
        }

        $request->forceFill(['status' => DataCorrectionStatus::Cancelled])->save();
    }

    /**
     * HR may decide a pending request, never their own.
     */
    public function canDecide(User $user, DataCorrectionRequest $request): bool
    {
        return $request->status === DataCorrectionStatus::Pending
            && $request->employee->user_id !== $user->id
            && $user->hasPermission(PermissionName::DataCorrectionsReview->value);
    }

    public function decide(DataCorrectionRequest $request, User $by, bool $approve, ?string $remarks): void
    {
        if (! $this->canDecide($by, $request)) {
            throw ValidationException::withMessages(['request' => 'This request is not yours to decide.']);
        }

        DB::transaction(function () use ($request, $by, $approve, $remarks) {
            if ($approve) {
                $this->apply($request);
            }

            $request->forceFill([
                'status' => $approve ? DataCorrectionStatus::Approved : DataCorrectionStatus::Rejected,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'remarks' => $remarks,
            ])->save();
        });

        $label = self::fields()[$request->field]['label'];
        $request->employee->user->notify(new HrUpdate(
            $approve ? 'Record correction approved' : 'Record correction rejected',
            $approve
                ? "Your {$label} was updated ({$request->reference_number})."
                : "Your request to change {$label} ({$request->reference_number}) was rejected.".($remarks ? " Remarks: {$remarks}" : ''),
            '/my/corrections',
        ));
    }

    /**
     * Every request, newest first, for HR.
     *
     * @return Collection<int, DataCorrectionRequest>
     */
    public function all(): Collection
    {
        return DataCorrectionRequest::query()->with(['employee.user', 'decider'])->latest()->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(DataCorrectionRequest $request, ?User $viewer = null): array
    {
        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'employee' => $request->employee->user->name,
            'employee_id' => $request->employee_id,
            'photo_url' => $request->employee->user->profile_photo_url,
            'field' => $request->field,
            'field_label' => self::fields()[$request->field]['label'] ?? $request->field,
            'current_value' => $request->current_value,
            'proposed_value' => $request->proposed_value,
            'reason' => $request->reason,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'requested_at' => $request->created_at?->toIso8601String(),
            'decided_by' => $request->decider?->name,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'remarks' => $request->remarks,
            'can_decide' => $viewer ? $this->canDecide($viewer, $request) : false,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function reviewers(): Collection
    {
        return User::query()->where('status', 'active')->get()
            ->filter(fn (User $user) => $user->hasPermission(PermissionName::DataCorrectionsReview->value))->values();
    }

    private function read(Employee $employee, string $field): ?string
    {
        $value = self::fields()[$field]['on'] === 'user' ? $employee->user->{$field} : $employee->{$field};

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return filled($value) ? (string) $value : null;
    }

    /**
     * Validates and tidies the proposed value for its field.
     */
    private function normalize(Employee $employee, string $field, ?string $value): string
    {
        $value = trim((string) $value);
        $type = self::fields()[$field]['type'];
        $optional = in_array($field, ['middle_name', 'name_extension', 'csc_entry_date'], true);

        if ($value === '' && ! $optional) {
            throw ValidationException::withMessages(['proposed_value' => 'Enter the corrected value.']);
        }

        $rules = match ($type) {
            'sex' => ['in:male,female'],
            'date' => ['date', 'before_or_equal:today'],
            'email' => ['email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($employee->user_id)],
            default => ['string', 'max:'.($field === 'employee_number' ? 50 : 100)],
        };

        if ($value !== '') {
            $validator = validator([$field => $value], [$field => $rules], [], [$field => 'value']);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['proposed_value' => $validator->errors()->first($field)]);
            }
        }

        return $type === 'date' && $value !== '' ? date('Y-m-d', strtotime($value)) : $value;
    }

    /**
     * Writes an approved change to the record. The value is checked again
     * because the record may have changed since the request was made.
     */
    private function apply(DataCorrectionRequest $request): void
    {
        $employee = $request->employee()->with('user')->firstOrFail();
        $field = $request->field;
        $value = $this->normalize($employee, $field, $request->proposed_value);
        $value = $value === '' ? null : $value;

        if (self::fields()[$field]['on'] === 'user') {
            $employee->user->forceFill([$field => $value])->save();
        } else {
            $employee->forceFill([$field => $value])->save();
        }
    }
}
