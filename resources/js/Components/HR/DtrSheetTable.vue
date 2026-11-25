<script setup>
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * One DTR sheet: a row per day (time in, break, time out, worked hours,
 * remark) and the totals the CS Form No. 48 prints. Amounts arrive as whole
 * minutes and show as h:mm. HRD's version can add a correction to a day.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    totals: { type: Object, required: true },
    canAdjust: { type: Boolean, default: false },
    /** HRD can open a day's raw punches (reading needs `dtr.view`, changing them `canAdjust`). */
    canSeePunches: { type: Boolean, default: false },
    /** Employee self-entry: in and out times and a reason become inputs, kept in `drafts` by date. */
    editing: { type: Boolean, default: false },
    drafts: { type: Object, default: () => ({}) },
    earliest: { type: String, default: '' },
    /** Offer a link to file leave on days the employee was absent (their own DTR). */
    fileLeave: { type: Boolean, default: false },
    /** While editing: what each changed day would count as, by date (worked, late and undertime minutes), worked out by the server. */
    previews: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['adjust', 'punches']);

const hm = (minutes) => `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
// "13:05" reads as "1:05 PM", matching the confirmation dialog.
const time = (value) => {
    if (!value) return '';
    const [h, m] = value.split(':').map(Number);

    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
};
// A day being edited shows what it would count as; every other day shows the record.
const shown = (row) => (props.editing && props.previews[row.date] ? { ...row, ...props.previews[row.date] } : row);
const dayName = (date) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short' });

// A day can be entered by the employee when it has happened, is recent enough, and HRD hasn't adjusted it.
const lockedByHrd = (row) => !!row.adjustment && !row.adjustment.by_employee;
const editable = (row) => props.editing && row.date <= localToday && (!props.earliest || row.date >= props.earliest) && !lockedByHrd(row) && !!props.drafts[row.date];

const statuses = {
    present: 'bg-success-bg text-success-fg',
    credit: 'bg-success-bg text-success-fg',
    travel: 'bg-success-bg text-success-fg',
    holiday: 'bg-info-bg text-link',
    leave: 'bg-info-bg text-link',
    late: 'bg-warning-bg text-warning-fg',
    undertime: 'bg-warning-bg text-warning-fg',
    incomplete: 'bg-warning-bg text-warning-fg',
    absent: 'bg-danger-bg text-danger-fg',
    no_schedule: 'bg-surface-muted text-text-muted',
    upcoming: 'bg-surface-muted text-text-muted',
};
const quiet = (status) => ['weekend', 'rest_day'].includes(status);
const label = (row) => (row.remark ? row.remark : '');
const statusLabels = { no_schedule: 'No schedule', upcoming: 'Upcoming' };
const badge = (row) => label(row) || statusLabels[row.status] || row.status;
const localToday = (() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
})();

const summary = [
    ['Total hours worked', (t) => hm(t.minutes)],
    ['Total hours late', (t) => hm(t.late_minutes)],
    ['Total hours undertime', (t) => hm(t.undertime_minutes)],
    ['Total no. of absences', (t) => t.absences],
    ['Total no. of days travelled', (t) => t.travel_days],
    ['Total no. of days on leave', (t) => t.leave_days],
    ['Total no. of undertime', (t) => t.undertime_count],
    ['Total no. of late', (t) => t.late_count],
];
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="dtr-table min-w-full text-left text-sm">
                <caption class="sr-only">Daily time record</caption>
                <thead class="border-b border-border text-xs text-text-muted">
                    <tr>
                        <th scope="col" class="py-2 pr-3 font-medium">Day</th>
                        <th scope="col" class="px-3 py-2 font-medium">Time in</th>
                        <th scope="col" class="px-3 py-2 font-medium">Break out</th>
                        <th scope="col" class="px-3 py-2 font-medium">Break in</th>
                        <th scope="col" class="px-3 py-2 font-medium">Time out</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Worked hrs</th>
                        <th scope="col" class="px-3 py-2 font-medium">Remarks</th>
                        <th v-if="canAdjust || canSeePunches" scope="col" class="py-2 pl-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.date" :class="quiet(row.status) ? 'text-text-muted' : ''">
                        <td class="whitespace-nowrap py-1.5 pr-3 tabular-nums">
                            <span class="font-medium">{{ Number(row.date.slice(8)) }}</span>
                            <span class="ml-1 text-xs text-text-muted">{{ dayName(row.date) }}</span>
                        </td>
                        <td class="px-3 py-1.5 tabular-nums">
                            <input v-if="editable(row)" v-model="drafts[row.date].in" type="time" class="filter-control w-36" :aria-label="`Time in for ${formatCalendarDate(row.date)}`" />
                            <template v-else>{{ time(row.in) }}</template>
                        </td>
                        <td class="px-3 py-1.5 tabular-nums">{{ time(row.break_out) }}</td>
                        <td class="px-3 py-1.5 tabular-nums">{{ time(row.break_in) }}</td>
                        <td class="px-3 py-1.5 tabular-nums">
                            <input v-if="editable(row)" v-model="drafts[row.date].out" type="time" class="filter-control w-36" :aria-label="`Time out for ${formatCalendarDate(row.date)}`" />
                            <template v-else>{{ time(row.out) }}</template>
                        </td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ shown(row).minutes ? hm(shown(row).minutes) : '' }}</td>
                        <td class="px-3 py-1.5">
                            <input v-if="editable(row)" v-model="drafts[row.date].remark" type="text" maxlength="255" placeholder="Reason, if you change the times" class="filter-control mb-1 w-full min-w-48" :aria-label="`Reason for ${formatCalendarDate(row.date)}`" />
                            <span v-if="statuses[shown(row).status]" class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statuses[shown(row).status]">{{ badge(shown(row)) }}</span>
                            <template v-else>{{ label(shown(row)) }}</template>
                            <span v-if="row.flags.includes('adjusted') && row.adjustment?.by_employee" class="ml-2 text-xs font-medium text-link">Entered by the employee{{ row.adjustment.verified ? ', verified' : ', not verified yet' }}</span>
                            <span v-else-if="row.flags.includes('adjusted')" class="ml-2 text-xs font-medium text-link">Adjusted by HRD{{ editing ? ' (locked)' : '' }}</span>
                            <span v-if="row.flags.includes('worked_day_off')" class="ml-2 text-xs text-warning-fg">Punched on a day off</span>
                            <a v-if="fileLeave && !editing && row.status === 'absent'" :href="`/my/leave/apply?date_from=${row.date}`" class="ml-2 text-xs font-medium text-link hover:underline">File leave for this day</a>
                            <span v-if="shown(row).late_minutes" class="ml-2 text-xs text-text-muted">{{ shown(row).late_minutes }} min late</span>
                            <span v-if="shown(row).undertime_minutes" class="ml-2 text-xs text-text-muted">{{ shown(row).undertime_minutes }} min short</span>
                        </td>
                        <td v-if="canAdjust || canSeePunches" class="whitespace-nowrap py-1.5 pl-3 text-right">
                            <ActionButton v-if="canSeePunches && row.date <= localToday" icon="clock" :label="`Punches on ${formatCalendarDate(row.date)}`" @click="emit('punches', row)" />
                            <ActionButton v-if="canAdjust && row.date <= localToday" icon="pencil" :label="`Adjust ${formatCalendarDate(row.date)}`" @click="emit('adjust', row)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <dl class="mt-4 grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
            <div v-for="[name, value] in summary" :key="name" class="flex justify-between gap-4 border-b border-border py-1">
                <dt class="text-text-muted">{{ name }}</dt>
                <dd class="font-medium tabular-nums">{{ value(props.totals) }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-border py-1">
                <dt class="text-text-muted">Regular days · weekends · holidays</dt>
                <dd class="font-medium tabular-nums">{{ totals.regular_days }} · {{ totals.weekend_days }} · {{ totals.holiday_days }}</dd>
            </div>
        </dl>
    </div>
</template>

<style scoped>
/* A bordered table, so every time sits in its own cell. */
.dtr-table { border: 1px solid var(--color-border); border-collapse: collapse; }
.dtr-table th,
.dtr-table td { border: 1px solid var(--color-border); padding-left: 0.75rem; padding-right: 0.75rem; }
.dtr-table thead th { background: var(--color-surface-muted); color: var(--color-text); }
</style>
