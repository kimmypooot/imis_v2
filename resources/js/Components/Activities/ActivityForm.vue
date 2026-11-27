<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import Textarea from '@cscro8/ui/Components/Forms/Textarea.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import Checkbox from '@cscro8/ui/Components/Forms/Checkbox.vue';
import Radio from '@cscro8/ui/Components/Forms/Radio.vue';
import FormGroup from '@cscro8/ui/Components/Forms/FormGroup.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * Calendar of Activities: add an activity, or edit one (`activity` is the
 * details from GET /activities/{id}). An activity has a date (with a time
 * or the whole day) or only a month ("dates to be announced"). A new one
 * may repeat daily (weekdays), weekly or monthly until a date. Editing an
 * occurrence of a series can change only it or it and the following ones;
 * the latter sends the whole repeat block, which replaces the pattern.
 * Changing a dated activity's date or time asks for the reason.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    // The activity being edited (details JSON), or null to add one.
    activity: { type: Object, default: null },
    // [{ id, name, short_name }] the offices this person may post for.
    offices: { type: Array, required: true },
    modeOptions: { type: Array, required: true },
    myOfficeId: { type: Number, default: null },
    // A new activity's starting date (YYYY-MM-DD) and month (YYYY-MM).
    defaultDate: { type: String, default: null },
    defaultMonth: { type: String, required: true },
});

const emit = defineEmits(['close']);

const WEEKDAYS = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];
const NTH = [
    { value: 1, label: '1st' },
    { value: 2, label: '2nd' },
    { value: 3, label: '3rd' },
    { value: 4, label: '4th' },
    { value: 5, label: '5th' },
];

const pad = (n) => String(n).padStart(2, '0');
const isoWeekday = (iso) => {
    const [year, month, day] = iso.split('-').map(Number);
    return new Date(year, month - 1, day).getDay() || 7;
};

const blankRepeat = () => ({ frequency: '', interval: 1, weekday: '', monthly_by: 'day', day_of_month: '', nth_weekday: '', until: '', skip_holidays: true });

const form = useForm({
    organizational_unit_id: '',
    title: '',
    responsible: '',
    participants: '',
    venue: '',
    mode: 'face_to_face',
    remarks: '',
    when: 'date',
    month_value: '',
    starts_on: '',
    ends_on: '',
    all_day: false,
    starts_at: '',
    ends_at: '',
    unplanned: false,
    move_reason: '',
    scope: 'this',
    repeat: blankRepeat(),
});

const editing = computed(() => props.activity !== null);
const wasDated = computed(() => editing.value && !!props.activity.starts_on);
const inSeries = computed(() => editing.value && !!props.activity.series_id && !!props.activity.starts_on);
const following = computed(() => inSeries.value && form.scope === 'following');
// A new activity may repeat; an occurrence edited "with the following" always does.
const showRepeat = computed(() => (!editing.value && form.when === 'date') || following.value);
const repeating = computed(() => following.value || (!editing.value && form.when === 'date' && !!form.repeat.frequency));
// The server keeps an activity unplanned once it is, so a saved unplanned activity can't be unticked.
const unplannedLocked = computed(() => editing.value && !!props.activity.unplanned);
// A cancelled or done activity keeps its date: it can't be moved (the server refuses it too).
const dateLocked = computed(() => editing.value && ['cancelled', 'done'].includes(props.activity.status));

const fill = () => {
    const activity = props.activity;
    const series = activity?.series;
    form.clearErrors();
    form.defaults({
        organizational_unit_id: activity?.office?.id ?? (props.offices.find((office) => office.id === props.myOfficeId)?.id ?? props.offices[0]?.id ?? ''),
        title: activity?.title ?? '',
        responsible: activity?.responsible ?? '',
        participants: activity?.participants ?? '',
        venue: activity?.venue ?? '',
        mode: activity?.mode ?? 'face_to_face',
        remarks: activity?.remarks ?? '',
        when: activity && !activity.starts_on ? 'month' : 'date',
        month_value: activity ? `${activity.year}-${pad(activity.month)}` : props.defaultMonth,
        starts_on: activity?.starts_on ?? props.defaultDate ?? '',
        ends_on: activity?.ends_on && activity.ends_on !== activity.starts_on ? activity.ends_on : '',
        all_day: activity?.all_day ?? false,
        starts_at: activity?.starts_at ?? '',
        ends_at: activity?.ends_at ?? '',
        unplanned: activity?.unplanned ?? false,
        move_reason: '',
        scope: 'this',
        repeat: series
            ? {
                  frequency: series.frequency,
                  interval: series.interval ?? 1,
                  weekday: series.weekday ?? '',
                  monthly_by: series.nth_weekday ? 'weekday' : 'day',
                  day_of_month: series.day_of_month ?? '',
                  nth_weekday: series.nth_weekday ?? '',
                  until: series.until ?? '',
                  skip_holidays: series.skip_holidays ?? true,
              }
            : blankRepeat(),
    });
    form.reset();
};
watch(() => [props.open, props.activity], ([open]) => open && fill(), { immediate: true });

// A "following" edit keeps the series' office, so a changed office goes back.
watch(following, (isFollowing) => {
    if (isFollowing) form.organizational_unit_id = props.activity.office?.id ?? form.organizational_unit_id;
});

// Start a new weekly or monthly repeat on the chosen date's weekday and day.
watch(
    () => form.repeat.frequency,
    (frequency) => {
        if (!form.starts_on || following.value) return;
        if (frequency === 'weekly' || frequency === 'monthly') form.repeat.weekday ||= isoWeekday(form.starts_on);
        if (frequency === 'monthly') form.repeat.day_of_month ||= Number(form.starts_on.slice(8, 10));
    },
);

// A dated activity whose date or time changes is a move: it needs a reason.
const moved = computed(() => {
    if (!wasDated.value || following.value) return false;
    const activity = props.activity;
    return (
        form.starts_on !== activity.starts_on ||
        (form.ends_on || form.starts_on) !== (activity.ends_on ?? activity.starts_on) ||
        form.all_day !== activity.all_day ||
        (!form.all_day && ((form.starts_at || null) !== activity.starts_at || (form.ends_at || null) !== activity.ends_at))
    );
});

const repeatBlock = () => {
    const repeat = form.repeat;
    const frequency = repeat.frequency;
    const byWeekday = frequency === 'monthly' && repeat.monthly_by === 'weekday';
    return {
        frequency,
        interval: frequency === 'daily' ? 1 : Number(repeat.interval) || 1,
        weekday: frequency === 'weekly' || byWeekday ? Number(repeat.weekday) || null : null,
        day_of_month: frequency === 'monthly' && !byWeekday ? Number(repeat.day_of_month) || null : null,
        nth_weekday: byWeekday ? Number(repeat.nth_weekday) || null : null,
        until: repeat.until || null,
        skip_holidays: !!repeat.skip_holidays,
    };
};

const payload = (data) => {
    const body = {
        organizational_unit_id: Number(data.organizational_unit_id) || null,
        title: data.title,
        responsible: data.responsible || null,
        participants: data.participants || null,
        venue: data.venue || null,
        mode: data.mode,
        remarks: data.remarks || null,
        unplanned: !!data.unplanned,
    };
    const times = { all_day: !!data.all_day, starts_at: data.all_day ? null : data.starts_at || null, ends_at: data.all_day ? null : data.ends_at || null };

    if (following.value) {
        return { ...body, ...times, scope: 'following', repeat: repeatBlock() };
    }
    if (data.when === 'month') {
        const [year, month] = data.month_value.split('-').map(Number);
        Object.assign(body, { year: year || null, month: month || null, starts_on: null, ends_on: null, starts_at: null, ends_at: null, all_day: false });
    } else {
        Object.assign(body, { starts_on: data.starts_on || null, ends_on: repeating.value ? null : data.ends_on || null, ...times });
    }
    if (editing.value) {
        body.scope = 'this';
        if (moved.value) body.move_reason = data.move_reason;
    } else if (repeating.value) {
        body.repeat = repeatBlock();
    }
    return body;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    form.transform(payload);
    if (editing.value) {
        form.put(`/activities/${props.activity.id}`, options);
    } else {
        form.post('/activities', options);
    }
};

const officeOptions = computed(() => props.offices.map((office) => ({ value: office.id, label: office.short_name ? `${office.short_name} — ${office.name}` : office.name })));
const generalErrors = computed(() => [form.errors.activity, form.errors.repeat, form.errors.scope].filter(Boolean));
const title = computed(() => (editing.value ? `Edit “${props.activity.title}”` : 'Add activity'));
</script>

<template>
    <Modal :open="open" :title="title" tone="primary" icon="calendar" size="lg" @close="emit('close')">
        <form id="activity-form" class="space-y-5" novalidate @submit.prevent="submit">
            <Alert v-if="generalErrors.length" tone="danger">
                <p v-for="message in generalErrors" :key="message">{{ message }}</p>
            </Alert>

            <fieldset v-if="inSeries" class="space-y-2">
                <legend class="text-sm font-medium text-text">This activity repeats. Change</legend>
                <div class="flex flex-wrap gap-4">
                    <Radio id="scope-this" v-model="form.scope" name="scope" value="this" label="Only this date" />
                    <Radio id="scope-following" v-model="form.scope" name="scope" value="following" :label="`This and the following dates (from ${formatCalendarDate(activity.starts_on)})`" />
                </div>
            </fieldset>

            <FormGroup id="activity-office" label="Office" required :helper-text="following ? 'The office of a repeating activity is changed one date at a time.' : null" :error="form.errors.organizational_unit_id">
                <Select id="activity-office" v-model="form.organizational_unit_id" :options="officeOptions" :disabled="offices.length < 2 || following" required :invalid="!!form.errors.organizational_unit_id" />
            </FormGroup>

            <TextInput id="activity-title" v-model="form.title" label="Activity" required :maxlength="255" :error="form.errors.title" />

            <fieldset :disabled="dateLocked" :aria-describedby="dateLocked ? 'activity-date-locked-hint' : undefined" class="space-y-4" :class="{ 'opacity-60': dateLocked }">
            <p v-if="dateLocked" id="activity-date-locked-hint" class="text-xs text-text-muted">This activity was {{ activity.status === 'done' ? 'held' : 'cancelled' }}, so its date and time can't change.</p>
            <template v-if="!following">
                <!-- A dated activity can't go back to month only (move it instead). -->
                <fieldset v-if="!wasDated" class="space-y-2">
                    <legend class="text-sm font-medium text-text">When</legend>
                    <div class="flex flex-wrap gap-4">
                        <Radio id="when-date" v-model="form.when" name="when" value="date" label="On a date" />
                        <Radio id="when-month" v-model="form.when" name="when" value="month" label="Month only (dates to be announced)" />
                    </div>
                </fieldset>

                <FormGroup v-if="form.when === 'month'" id="activity-month" label="Month" required :error="form.errors.month || form.errors.year">
                    <input id="activity-month" v-model="form.month_value" type="month" required class="block w-full form-control px-3 py-2" :aria-invalid="!!(form.errors.month || form.errors.year)" />
                </FormGroup>

                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <FormGroup id="activity-starts-on" :label="repeating ? 'First date' : 'Date'" required :error="form.errors.starts_on">
                        <input id="activity-starts-on" v-model="form.starts_on" type="date" required class="block w-full form-control px-3 py-2" :aria-invalid="!!form.errors.starts_on" />
                    </FormGroup>
                    <FormGroup v-if="!repeating" id="activity-ends-on" label="Last date" helper-text="For an activity over several days." :error="form.errors.ends_on">
                        <input id="activity-ends-on" v-model="form.ends_on" type="date" :min="form.starts_on || undefined" class="block w-full form-control px-3 py-2" :aria-invalid="!!form.errors.ends_on" />
                    </FormGroup>
                </div>
            </template>

            <div v-if="form.when === 'date' || following" class="space-y-3">
                <Checkbox id="activity-all-day" v-model="form.all_day" label="Whole day" />
                <div v-if="!form.all_day" class="grid gap-4 sm:grid-cols-2">
                    <FormGroup id="activity-starts-at" label="Start time" :error="form.errors.starts_at">
                        <input id="activity-starts-at" v-model="form.starts_at" type="time" class="block w-full form-control px-3 py-2" :aria-invalid="!!form.errors.starts_at" />
                    </FormGroup>
                    <FormGroup id="activity-ends-at" label="End time" helper-text="Leave empty for “onwards”." :error="form.errors.ends_at">
                        <input id="activity-ends-at" v-model="form.ends_at" type="time" class="block w-full form-control px-3 py-2" :aria-invalid="!!form.errors.ends_at" />
                    </FormGroup>
                </div>
            </div>
            </fieldset>

            <TextInput
                v-if="moved"
                id="activity-move-reason"
                v-model="form.move_reason"
                label="Why is it moving?"
                helper-text="The date or time changed, so the calendar records it as moved."
                :maxlength="255"
                required
                :error="form.errors.move_reason"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <FormGroup id="activity-mode" label="Mode" required :error="form.errors.mode">
                    <Select id="activity-mode" v-model="form.mode" :options="modeOptions" required :invalid="!!form.errors.mode" />
                </FormGroup>
                <TextInput id="activity-venue" v-model="form.venue" label="Venue" :maxlength="255" :error="form.errors.venue" />
                <TextInput id="activity-participants" v-model="form.participants" label="Participants" :maxlength="255" :error="form.errors.participants" />
                <TextInput id="activity-responsible" v-model="form.responsible" label="Responsible" helper-text="Defaults to the office." :maxlength="255" :error="form.errors.responsible" />
            </div>

            <FormGroup id="activity-remarks" label="Remarks" :error="form.errors.remarks">
                <Textarea id="activity-remarks" v-model="form.remarks" :rows="2" :maxlength="2000" :invalid="!!form.errors.remarks" />
            </FormGroup>

            <!-- Once unplanned, always unplanned: the server never clears the flag, so the box can't be unticked. -->
            <fieldset :disabled="unplannedLocked" :aria-describedby="unplannedLocked ? 'activity-unplanned-hint' : undefined" :class="{ 'opacity-60': unplannedLocked }">
                <Checkbox id="activity-unplanned" v-model="form.unplanned" label="Unplanned (not in the office's plan for the year)" />
                <p v-if="unplannedLocked" id="activity-unplanned-hint" class="mt-1 text-xs text-text-muted">Set automatically after the plan cut-off, or when ticked; it can't be cleared.</p>
            </fieldset>

            <fieldset v-if="showRepeat" class="space-y-4 rounded-md border border-border p-4">
                <legend class="px-1 text-sm font-medium text-text">Repeat</legend>
                <FormGroup id="repeat-frequency" label="Repeats" :error="form.errors['repeat.frequency']">
                    <Select
                        id="repeat-frequency"
                        v-model="form.repeat.frequency"
                        :options="[
                            ...(following ? [] : [{ value: '', label: 'Does not repeat' }]),
                            { value: 'daily', label: 'Daily (weekdays)' },
                            { value: 'weekly', label: 'Weekly' },
                            { value: 'monthly', label: 'Monthly' },
                        ]"
                    />
                </FormGroup>

                <template v-if="form.repeat.frequency">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TextInput
                            v-if="form.repeat.frequency !== 'daily'"
                            id="repeat-interval"
                            v-model="form.repeat.interval"
                            type="number"
                            :min="1"
                            :max="255"
                            :label="form.repeat.frequency === 'weekly' ? 'Every how many weeks' : 'Every how many months'"
                            :error="form.errors['repeat.interval']"
                        />
                        <FormGroup v-if="form.repeat.frequency === 'weekly'" id="repeat-weekday" label="On" required :error="form.errors['repeat.weekday']">
                            <Select id="repeat-weekday" v-model="form.repeat.weekday" :options="WEEKDAYS" placeholder="Choose a day" required />
                        </FormGroup>
                    </div>

                    <div v-if="form.repeat.frequency === 'monthly'" class="space-y-3">
                        <div class="flex flex-wrap gap-4">
                            <Radio id="monthly-day" v-model="form.repeat.monthly_by" name="monthly_by" value="day" label="On a day of the month" />
                            <Radio id="monthly-weekday" v-model="form.repeat.monthly_by" name="monthly_by" value="weekday" label="On a weekday of the month" />
                        </div>
                        <TextInput v-if="form.repeat.monthly_by === 'day'" id="repeat-day" v-model="form.repeat.day_of_month" type="number" :min="1" :max="31" label="Day" helper-text="A month without that day is skipped." :error="form.errors['repeat.day_of_month']" />
                        <div v-else class="grid gap-4 sm:grid-cols-2">
                            <FormGroup id="repeat-nth" label="Which" :error="form.errors['repeat.nth_weekday']">
                                <Select id="repeat-nth" v-model="form.repeat.nth_weekday" :options="NTH" placeholder="Choose" />
                            </FormGroup>
                            <FormGroup id="repeat-month-weekday" label="Weekday" :error="form.errors['repeat.weekday']">
                                <Select id="repeat-month-weekday" v-model="form.repeat.weekday" :options="WEEKDAYS" placeholder="Choose a day" />
                            </FormGroup>
                        </div>
                    </div>

                    <FormGroup id="repeat-until" label="Until" helper-text="Up to 31 December; the end of the year when left empty." :error="form.errors['repeat.until']">
                        <input id="repeat-until" v-model="form.repeat.until" type="date" :min="form.starts_on || undefined" class="block w-full form-control px-3 py-2" />
                    </FormGroup>
                    <Checkbox id="repeat-skip-holidays" v-model="form.repeat.skip_holidays" label="Skip holidays that are days off" />
                </template>
            </fieldset>
        </form>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="activity-form" :loading="form.processing">{{ editing ? 'Save' : 'Add activity' }}</Button>
        </template>
    </Modal>
</template>
