<script setup>
import { computed } from 'vue';
import Tooltip from '@cscro8/ui/Components/UI/Tooltip.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * A month as a grid of weeks from Sunday, in the Leave Calendar's look:
 * weekends shaded, today ringed, the chosen day tinted, holidays as a chip
 * with their name in a tooltip. Each day's contents come from the `day`
 * slot ({ date, iso }); clicking the day number emits `select(iso)`.
 */
const props = defineProps({
    // YYYY-MM
    month: { type: String, required: true },
    // [{ date: 'YYYY-MM-DD', name, day_off }]
    holidays: { type: Array, default: () => [] },
    // The chosen day (YYYY-MM-DD), or null.
    selected: { type: String, default: null },
    // Today (YYYY-MM-DD).
    today: { type: String, default: null },
    // What a click on the day number does, for screen readers ("show its activities").
    selectLabel: { type: String, default: 'show this day' },
    // Describes the table for screen readers.
    caption: { type: String, default: null },
});

const emit = defineEmits(['select']);

const pad = (n) => String(n).padStart(2, '0');
const iso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

const first = computed(() => {
    const [year, month] = props.month.split('-').map(Number);
    return new Date(year, month - 1, 1);
});
const title = computed(() => first.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));

// Weeks from Sunday (Gregorian), with days outside the month left blank.
const weeks = computed(() => {
    const start = first.value;
    const cells = [];
    for (let i = 0; i < start.getDay(); i++) cells.push(null);
    const last = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
    for (let day = 1; day <= last; day++) cells.push(new Date(start.getFullYear(), start.getMonth(), day));
    while (cells.length % 7) cells.push(null);
    return Array.from({ length: cells.length / 7 }, (_, index) => cells.slice(index * 7, index * 7 + 7));
});

const holidaysByDate = computed(() => {
    const map = {};
    for (const holiday of props.holidays) (map[holiday.date] ??= []).push(holiday);
    return map;
});
const holidaysOn = (date) => holidaysByDate.value[iso(date)] ?? [];
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[42rem] table-fixed border-collapse text-sm">
            <caption class="sr-only">{{ caption ?? title }}</caption>
            <thead>
                <tr>
                    <th v-for="day in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="day" scope="col" class="border-b border-border py-2 text-center text-xs font-medium text-text-muted">{{ day }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(week, index) in weeks" :key="index">
                    <td
                        v-for="(date, column) in week"
                        :key="column"
                        class="h-24 border border-border p-1 align-top"
                        :class="[
                            date && (date.getDay() === 0 || date.getDay() === 6) ? 'bg-surface-muted' : '',
                            date && iso(date) === today ? 'ring-2 ring-inset ring-primary' : '',
                            date && iso(date) === selected ? 'bg-primary/5' : '',
                        ]"
                    >
                        <template v-if="date">
                            <button
                                type="button"
                                class="w-full rounded-sm px-0.5 text-left text-xs font-semibold text-text hover:bg-surface-muted focus:outline-none focus:ring-2 focus:ring-focus-ring"
                                :aria-label="`${formatCalendarDate(iso(date))}: ${selectLabel}`"
                                :aria-pressed="iso(date) === selected"
                                @click="emit('select', iso(date))"
                            >
                                {{ date.getDate() }}
                            </button>
                            <Tooltip v-for="holiday in holidaysOn(date)" :key="holiday.name" :label="holiday.name" class="mt-0.5 w-full">
                                <div class="min-w-0 flex-1 truncate rounded-sm bg-info-bg px-1 text-2xs text-info-fg">{{ holiday.name }}</div>
                            </Tooltip>
                            <slot name="day" :date="date" :iso="iso(date)" />
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
