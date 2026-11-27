<?php

namespace App\Http\Requests\Activities;

use App\Enums\ActivityMode;
use App\Enums\SeriesFrequency;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Calendar of Activities: adding an activity (with an optional `repeat`
 * block, which makes it a series) and editing one (`move_reason` when its
 * date or time changes; `scope=following` edits the series from this
 * occurrence on). Which office a poster may post for is checked by the
 * controller (ActivityPolicy::postFor), after this.
 */
class SaveActivityRequest extends FormRequest
{
    /** The fields of the activity itself, as Activities::save() takes them. */
    private const ACTIVITY_FIELDS = [
        'organizational_unit_id', 'title', 'responsible', 'participants', 'venue', 'mode', 'remarks',
        'year', 'month', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'all_day', 'unplanned',
    ];

    public function authorize(): bool
    {
        $activity = $this->activity();

        return $activity === null
            ? $this->user()->can('create', Activity::class)
            : $this->user()->can('update', $activity);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->activity() === null ? 'required' : 'sometimes';
        $daily = $this->input('repeat.frequency') === SeriesFrequency::Daily->value;

        return [
            'organizational_unit_id' => [$required, 'integer', Rule::exists('organizational_units', 'id')->where('plantilla_only', 0)],
            'title' => [$required, 'string', 'max:255'],
            'responsible' => ['nullable', 'string', 'max:255'],
            'participants' => ['nullable', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'mode' => [$required, Rule::enum(ActivityMode::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', ...($this->filled('starts_on') ? ['after_or_equal:starts_on'] : [])],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
            'all_day' => ['boolean'],
            'unplanned' => ['boolean'],
            'move_reason' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', Rule::in(['this', 'following'])],
            'repeat' => ['nullable', 'array'],
            'repeat.frequency' => ['required_with:repeat', Rule::enum(SeriesFrequency::class)],
            'repeat.interval' => ['nullable', 'integer', 'min:1', 'max:255', ...($daily ? [Rule::in([1])] : [])],
            'repeat.weekday' => ['nullable', 'integer', 'between:1,7', Rule::requiredIf($this->input('repeat.frequency') === SeriesFrequency::Weekly->value)],
            'repeat.day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'repeat.nth_weekday' => ['nullable', 'integer', 'between:1,5'],
            'repeat.until' => ['nullable', 'date_format:Y-m-d'],
            'repeat.skip_holidays' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'repeat.interval.in' => 'A daily repeat runs every weekday.',
            'repeat.weekday.required' => 'Choose the day of the week the activity repeats on.',
            'move_reason.max' => 'Keep the reason to 255 characters.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkOffice($validator);
                $this->checkRepeat($validator);
            },
        ];
    }

    /**
     * The activity's own fields, as given.
     *
     * @return array<string, mixed>
     */
    public function activityData(): array
    {
        return Arr::only($this->validated(), self::ACTIVITY_FIELDS);
    }

    /**
     * The repeat block, or null when the activity doesn't repeat.
     *
     * @return array<string, mixed>|null
     */
    public function repeat(): ?array
    {
        $repeat = $this->validated('repeat');

        return is_array($repeat) && $repeat !== [] ? $repeat : null;
    }

    private function activity(): ?Activity
    {
        $activity = $this->route('activity');

        return $activity instanceof Activity ? $activity : null;
    }

    /**
     * A poster's account must have an office to post for.
     */
    private function checkOffice(Validator $validator): void
    {
        if ($validator->errors()->has('organizational_unit_id') || ! $this->has('organizational_unit_id')) {
            return;
        }

        if ($this->user()->organizational_unit_id === null && ! $this->user()->can('manage', Activity::class)) {
            $validator->errors()->add('organizational_unit_id', 'Your account has no office, so you can\'t post activities. Ask an administrator to set your office.');
        }
    }

    /**
     * A repeat starts on the activity's date; a monthly one falls on a day
     * of the month or on the nth weekday, not both.
     */
    private function checkRepeat(Validator $validator): void
    {
        $repeat = $this->input('repeat');

        if (! is_array($repeat) || $repeat === [] || $validator->errors()->has('repeat.*')) {
            return;
        }

        if ($this->activity() === null && blank($this->input('starts_on'))) {
            $validator->errors()->add('starts_on', 'Give the date the activity starts repeating.');
        }

        if (($repeat['frequency'] ?? null) !== SeriesFrequency::Monthly->value) {
            return;
        }

        $byDay = filled($repeat['day_of_month'] ?? null);
        $byWeekday = filled($repeat['nth_weekday'] ?? null) && filled($repeat['weekday'] ?? null);

        if ($byDay && (filled($repeat['nth_weekday'] ?? null) || filled($repeat['weekday'] ?? null))) {
            $validator->errors()->add('repeat', 'Repeat on a day of the month or on a weekday of the month, not both.');
        } elseif (! $byDay && ! $byWeekday) {
            $validator->errors()->add('repeat', 'Choose the day of the month, or which weekday of the month, the activity repeats on.');
        }
    }
}
