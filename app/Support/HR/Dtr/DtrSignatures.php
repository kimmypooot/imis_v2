<?php

namespace App\Support\HR\Dtr;

use App\Models\DtrSignature;
use App\Models\Employee;
use App\Models\User;
use App\Support\HR\Signatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DTR: an employee signing a finished sheet of their own DTR with their
 * saved e-signature. The sheet as signed is fingerprinted (a hash of its
 * days and totals), so anything that changes it afterwards (an HRD
 * adjustment, a corrected punch) shows as "changed since signed". Signing
 * again replaces the earlier signing, whose code then says it was replaced.
 * The signature is a snapshot copy, so changing your signature later never
 * alters a signed sheet.
 */
class DtrSignatures
{
    /** Letters and digits that can't be mistaken for each other when read from paper. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly DailyTimeRecord $record = new DailyTimeRecord,
        private readonly DtrPeriods $periods = new DtrPeriods,
    ) {}

    public function hashFor(Employee $employee, Carbon $from, Carbon $to): string
    {
        return $this->hashOf($employee, $from, $to, $this->record->build($employee, $from->copy()->startOfDay(), $to->copy()->startOfDay()));
    }

    /**
     * The fingerprint of a sheet already built, so showing it does not build
     * it twice. Only the facts count: the times, the credited minutes, the
     * status and HRD's reason for an adjustment. The wording of leave types
     * and holidays is not part of it, so renaming one later changes nothing.
     *
     * @param  array{days: Collection<int, array<string, mixed>>, totals: array<string, mixed>}  $record
     */
    public function hashOf(Employee $employee, Carbon $from, Carbon $to, array $record): string
    {
        $keys = ['date', 'in', 'break_out', 'break_in', 'out', 'minutes', 'late_minutes', 'undertime_minutes', 'absent_minutes', 'status'];

        return hash('sha256', json_encode([
            'employee' => $employee->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $record['days']->map(fn (array $day) => [...array_intersect_key($day, array_flip($keys)), 'adjustment' => $day['adjustment']['remark'] ?? null])->values()->all(),
            'totals' => $record['totals'],
        ], JSON_THROW_ON_ERROR));
    }

    public function current(Employee $employee, Carbon $from, Carbon $to): ?DtrSignature
    {
        return DtrSignature::query()->current()
            ->where('employee_id', $employee->id)
            ->whereDate('period_from', $from->toDateString())
            ->whereDate('period_to', $to->toDateString())
            ->latest('id')->first();
    }

    public function find(string $code): ?DtrSignature
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9]{12}$/', $code) ? DtrSignature::query()->where('code', $code)->first() : null;
    }

    public function sign(Employee $employee, User $user, Carbon $from, Carbon $to): DtrSignature
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        if ($employee->user_id !== $user->id) {
            throw ValidationException::withMessages(['from' => 'You can only sign your own DTR.']);
        }
        if ($to->lt($from) || ! $from->isSameMonth($to) || $this->periods->for($employee, $from, $to)->count() > 1) {
            throw ValidationException::withMessages(['to' => 'Sign one office\'s sheet at a time, within one month.']);
        }
        if (! $to->lt(today())) {
            throw ValidationException::withMessages(['to' => 'A sheet can be signed once its last day has passed.']);
        }
        if (! Signatures::exists($user->signature_path)) {
            throw ValidationException::withMessages(['signature' => 'Save your e-signature first (My Employee Record).']);
        }

        return DB::transaction(function () use ($employee, $user, $from, $to) {
            // One signing at a time per employee, so two tabs cannot both leave a valid copy.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            DtrSignature::query()->current()
                ->where('employee_id', $employee->id)
                ->whereDate('period_from', $from->toDateString())
                ->whereDate('period_to', $to->toDateString())
                ->update(['superseded_at' => now()]);

            $signature = DtrSignature::query()->create([
                'employee_id' => $employee->id,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'code' => $this->newCode(),
                'content_hash' => $this->hashFor($employee, $from, $to),
                'signed_by' => $user->id,
                'signed_at' => now(),
            ]);

            // The image copy comes last, so a failure above leaves no stray file behind.
            $path = app(Signatures::class)->snapshot($user->signature_path);
            try {
                $signature->update(['signature_path' => $path]);
            } catch (\Throwable $exception) {
                app(Signatures::class)->deleteSnapshot($path);

                throw $exception;
            }

            return $signature;
        });
    }

    /**
     * `valid` (still exactly what was signed), `changed` (the sheet is
     * different now) or `replaced` (signed again later).
     */
    public function status(DtrSignature $signature, ?string $currentHash = null): string
    {
        if ($signature->superseded_at !== null) {
            return 'replaced';
        }

        $currentHash ??= $this->hashFor($signature->employee, $signature->period_from, $signature->period_to);

        return hash_equals($signature->content_hash, $currentHash) ? 'valid' : 'changed';
    }

    private function newCode(): string
    {
        do {
            $code = collect(range(1, 12))->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
        } while (DtrSignature::query()->where('code', $code)->exists());

        return $code;
    }
}
