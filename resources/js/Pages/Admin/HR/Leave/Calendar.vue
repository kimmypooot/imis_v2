<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import BusyRegion from '../../../../Components/UI/BusyRegion.vue';
import { useBusy } from '../../../../Composables/useBusy';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import Button from '../../../../Components/UI/Button.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Checkbox from '../../../../Components/Forms/Checkbox.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { leaveDates, leaveStatusTones } from '../../../../Components/HR/leaveStatus';

defineOptions({ layout: AdminLayout });

/**
 * Everyone's leave on a month grid, so HR can see at a glance who is out
 * on which day. Filter by office and type of leave; pending leave (under
 * review or returned) is shown outlined when included. Pick a day to list
 * everyone out that day; each entry opens its application.
 */
const props = defineProps({
    month: { type: String, required: true },
    filters: { type: Object, required: true },
    officeOptions: { type: Array, required: true },
    // Only the few who see every office may choose one; the rest see their own division.
    seesEveryone: { type: Boolean, default: false },
    division: { type: String, default: null },
    typeOptions: { type: Array, required: true },
    leave: { type: Array, required: true },
    holidays: { type: Array, required: true },
});

const MAX_PER_DAY = 3;

const pad = (n) => String(n).padStart(2, '0');
const iso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const today = iso(new Date());

const parts = computed(() => props.month.split('-').map(Number));
const first = computed(() => new Date(parts.value[0], parts.value[1] - 1, 1));
const title = computed(() => first.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));

const { busy, handlers } = useBusy();
const visit = (changes) => {
    const query = { month: props.month, ...props.filters, ...changes };
    query.pending = query.pending ? 1 : null;
    router.get('/admin/hr/leave/calendar', Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== '')), { preserveScroll: true, preserveState: true, ...handlers });
};
const shift = (by) => {
    const target = new Date(parts.value[0], parts.value[1] - 1 + by, 1);
    visit({ month: `${target.getFullYear()}-${pad(target.getMonth() + 1)}` });
};
const thisMonth = today.slice(0, 7);

// Weeks from Sunday (Gregorian), with days outside the month left blank.
const weeks = computed(() => {
    const [year, month] = parts.value;
    const cells = [];
    const lead = first.value.getDay();
    for (let i = 0; i < lead; i++) cells.push(null);
    const last = new Date(year, month, 0).getDate();
    for (let day = 1; day <= last; day++) cells.push(new Date(year, month - 1, day));
    while (cells.length % 7) cells.push(null);
    return Array.from({ length: cells.length / 7 }, (_, index) => cells.slice(index * 7, index * 7 + 7));
});

// Who is out on each day (the server already skips weekends and day-off holidays).
const byDate = computed(() => {
    const map = {};
    for (const item of props.leave) {
        for (const date of item.dates) (map[date] ??= []).push(item);
    }
    for (const list of Object.values(map)) list.sort((a, b) => a.name.localeCompare(b.name));
    return map;
});
const leaveOn = (date) => byDate.value[iso(date)] ?? [];
const holidayOn = (date) => props.holidays.find((holiday) => holiday.date === iso(date));

const people = computed(() => new Set(props.leave.map((item) => item.name)).size);

// The day listed below the grid: today when it's in this month, else the first day with leave.
const defaultDay = () => (props.month === thisMonth ? today : Object.keys(byDate.value).sort()[0] ?? null);
const selected = ref(defaultDay());
watch(() => props.month, () => { selected.value = defaultDay(); });
const selectedLeave = computed(() => (selected.value ? byDate.value[selected.value] ?? [] : []));
const selectedHoliday = computed(() => props.holidays.find((holiday) => holiday.date === selected.value));

const chipClass = (item) => (item.status === 'approved' ? 'bg-success text-white' : 'border border-warning text-warning');

const selectClass = 'rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Leave Calendar" />

    <PageHeader title="Leave Calendar" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Calendar' }]">
        <template #actions>
            <span v-if="!seesEveryone && division" class="inline-flex items-center rounded-md bg-info-bg px-3 py-2 text-sm font-medium text-primary">{{ division }}</span>
            <label v-if="seesEveryone" class="sr-only" for="cal-office">Office</label>
            <select v-if="seesEveryone" id="cal-office" :value="filters.office ?? ''" :class="selectClass" @change="visit({ office: $event.target.value || null })">
                <option value="">All offices</option>
                <option v-for="option in officeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <label class="sr-only" for="cal-type">Type of leave</label>
            <select id="cal-type" :value="filters.type ?? ''" :class="selectClass" @change="visit({ type: $event.target.value || null })">
                <option value="">All types of leave</option>
                <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <Checkbox id="cal-pending" :model-value="filters.pending" label="Include pending" @update:model-value="visit({ pending: $event })" />
        </template>
    </PageHeader>

    <BusyRegion :busy="busy">
        <Card icon="calendar">
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="secondary" size="sm" aria-label="Previous month" @click="shift(-1)">‹</Button>
                <h2 class="min-w-[9rem] text-center text-base font-semibold text-text">{{ title }}</h2>
                <Button variant="secondary" size="sm" aria-label="Next month" @click="shift(1)">›</Button>
                <Button v-if="month !== thisMonth" variant="secondary" size="sm" @click="visit({ month: thisMonth })">Today</Button>
            </div>
            <p class="text-sm text-text-muted">{{ people }} {{ people === 1 ? 'employee' : 'employees' }} on leave this month</p>
        </template>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[48rem] table-fixed border-collapse text-sm">
                <caption class="sr-only">Employees on leave and holidays, {{ title }}</caption>
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
                            class="h-28 border border-border p-1 align-top"
                            :class="[
                                date && (date.getDay() === 0 || date.getDay() === 6) ? 'bg-surface-muted' : '',
                                date && iso(date) === today ? 'ring-2 ring-inset ring-primary' : '',
                                date && iso(date) === selected ? 'bg-primary/5' : '',
                            ]"
                        >
                            <template v-if="date">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between rounded px-0.5 text-xs font-semibold text-text hover:bg-surface-muted focus:outline-none focus:ring-2 focus:ring-focus-ring"
                                    :aria-label="`${formatCalendarDate(iso(date))}: ${leaveOn(date).length} on leave`"
                                    :aria-pressed="iso(date) === selected"
                                    @click="selected = iso(date)"
                                >
                                    <span>{{ date.getDate() }}</span>
                                    <span v-if="leaveOn(date).length" class="rounded-full bg-primary px-1.5 text-[0.65rem] font-medium text-white">{{ leaveOn(date).length }}</span>
                                </button>
                                <div v-if="holidayOn(date)" class="mt-0.5 truncate rounded bg-info-bg px-1 text-[0.7rem] text-info" :title="holidayOn(date).name">{{ holidayOn(date).name }}</div>
                                <Link
                                    v-for="item in leaveOn(date).slice(0, MAX_PER_DAY)"
                                    :key="item.id"
                                    :href="`/leave/applications/${item.id}`"
                                    class="mt-0.5 block truncate rounded px-1 text-[0.7rem]"
                                    :class="chipClass(item)"
                                    :title="`${item.name} — ${item.type_name} (${item.status_label})`"
                                >
                                    {{ item.surname }} · {{ item.type_code }}<template v-if="item.is_half_day"> ½</template>
                                </Link>
                                <button
                                    v-if="leaveOn(date).length > MAX_PER_DAY"
                                    type="button"
                                    class="mt-0.5 text-[0.7rem] font-medium text-primary hover:underline"
                                    @click="selected = iso(date)"
                                >
                                    +{{ leaveOn(date).length - MAX_PER_DAY }} more
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-text-muted">
            Solid: approved.<template v-if="filters.pending"> Outlined: under review or returned.</template> Blue: holiday.
            Weekends and non-working holidays aren't marked, except for leave counted in calendar days. Pick a day to see everyone out.
        </p>
    </Card>
    </BusyRegion>

    <Card v-if="selected" class="mt-6" icon="users" :title="`On leave — ${formatCalendarDate(selected)}`">
        <p v-if="selectedHoliday" class="mb-3 text-sm text-info">{{ selectedHoliday.name }}</p>
        <p v-if="!selectedLeave.length" class="text-sm text-text-muted">No one is on leave this day.</p>
        <ul v-else class="divide-y divide-border">
            <li v-for="item in selectedLeave" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                <div>
                    <Link :href="`/leave/applications/${item.id}`" class="font-medium text-primary hover:underline">{{ item.name }}</Link>
                    <span v-if="item.office" class="ml-2 text-xs text-text-muted">{{ item.office }}</span>
                    <div class="text-xs text-text-muted">{{ item.type_name }} · {{ leaveDates(item, formatCalendarDate) }}</div>
                </div>
                <StatusBadge :status="leaveStatusTones[item.status]" :label="item.status_label" />
            </li>
        </ul>
    </Card>
</template>
