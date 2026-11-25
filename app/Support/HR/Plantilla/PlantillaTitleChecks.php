<?php

namespace App\Support\HR\Plantilla;

use App\Enums\PermissionName;
use App\Models\PlantillaAppointment;
use App\Models\User;
use App\Notifications\PlantillaAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * HR-4: tells HR (plantilla.manage) under the bell when an item holder's
 * account title differs from the item they hold — usually a promotion
 * not yet recorded on the plantilla. Each difference (holder, item and
 * account title) is reported to a person once; a new title is new.
 */
class PlantillaTitleChecks
{
    public const URL = '/admin/hr/plantilla?view=title_differs';

    public function __construct(private readonly Plantilla $plantilla) {}

    /**
     * Current holders, with an active account, whose title differs.
     *
     * @return Collection<string, PlantillaAppointment> keyed by what is reported
     */
    public function mismatches(): Collection
    {
        return PlantillaAppointment::query()->whereNull('date_to')
            ->whereHas('employee.user', fn ($query) => $query->where('status', 'active'))
            ->with(['item', 'employee.user'])
            ->get()
            ->toBase()
            ->filter(fn (PlantillaAppointment $holder) => $this->plantilla->accountPositionDiffers($holder))
            ->keyBy(fn (PlantillaAppointment $holder) => $this->key($holder));
    }

    /**
     * Returns how many people were told.
     */
    public function notify(): int
    {
        $mismatches = $this->mismatches();
        if ($mismatches->isEmpty()) {
            return 0;
        }

        $told = 0;
        $recipients = User::query()->where('status', 'active')
            ->whereHas('activeRoles.permissions', fn ($query) => $query->where('name', PermissionName::PlantillaManage->value))
            ->get();

        foreach ($recipients as $recipient) {
            $new = $mismatches->except($this->reportedTo($recipient));
            if ($new->isEmpty()) {
                continue;
            }

            Notification::send($recipient, new PlantillaAlert(
                $new->count() === 1 ? 'A position title differs from the plantilla' : "{$new->count()} position titles differ from the plantilla",
                $this->message($new),
                self::URL,
                $new->keys()->all(),
            ));
            $told++;
        }

        return $told;
    }

    /**
     * @param  Collection<string, PlantillaAppointment>  $new
     */
    private function message(Collection $new): string
    {
        $lines = $new->take(3)->map(fn (PlantillaAppointment $holder) => "{$holder->employee->user->name}'s account says {$holder->employee->user->position}, "
            ."but they hold {$holder->item->position_title}".($holder->item->item_number ? " ({$holder->item->item_number})" : ''));
        $more = $new->count() > 3 ? ' and '.($new->count() - 3).' more' : '';

        return $lines->implode('; ').$more.'. Record the appointment on the right item, or correct the account if the plantilla is right.';
    }

    /**
     * @return array<int, string>
     */
    private function reportedTo(User $recipient): array
    {
        return $recipient->notifications()->where('data->kind', PlantillaAlert::KIND)->pluck('data')
            ->flatMap(fn (array $data) => $data['keys'] ?? [])
            ->unique()->values()->all();
    }

    private function key(PlantillaAppointment $holder): string
    {
        $title = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $holder->employee->user->position)));

        return "title:{$holder->id}:{$title}";
    }
}
