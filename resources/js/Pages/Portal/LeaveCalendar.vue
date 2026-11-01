<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import BusyRegion from '../../Components/UI/BusyRegion.vue';
import { useBusy } from '../../Composables/useBusy';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Checkbox from '../../Components/Forms/Checkbox.vue';
import { formatCalendarDate } from '../../Utils/datetime';
import { leaveDates, leaveStatusTones } from '../../Components/HR/leaveStatus';

defineOptions({ layout: PortalLayout });

/**
 * HR-6c: your leave and the holidays, a month at a time (legacy
 * leave/calendar.php), plus co-workers in your division who've filed
 * leave (who is out, not the type of leave). Yours is green, theirs blue;
 * leave still under review or returned is outlined. Pick a day to list
 * everyone out that day.
 */
const props = defineProps({
    month: { type: String, required: true },
    leave: { type: Array, required: true },
    colleagues: { type: Array, required: true },
    division: { type: String, default: null },
    // The head of HRD, a Director IV / III and a Super Administrator see every office.
    seesEveryone: { type: Boolean, default: false },
    holidays: { type: Array, required: true },
});

const MAX_COLLEAGUES_PER_DAY = 2;

const [year, monthNumber] = props.month.split('-').map(Number);
const first = new Date(year, monthNumber - 1, 1);
const title = first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
const pad = (n) => String(n).padStart(2, '0');
const iso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const { busy, handlers } = useBusy();
const shift = (by) => {
    const target = new Date(year, monthNumber - 1 + by, 1);
    router.get('/my/leave/calendar', { month: `${target.getFullYear()}-${pad(target.getMonth() + 1)}` }, { preserveScroll: true, ...handlers });
};

// Weeks from Sunday (Gregorian), with days outside the month left blank.
const weeks = computed(() => {
    const cells = [];
    const lead = first.getDay();
    for (let i = 0; i < lead; i++) cells.push(null);
    const last = new Date(year, monthNumber, 0).getDate();
    for (let day = 1; day <= last; day++) cells.push(new Date(year, monthNumber - 1, day));
    while (cells.length % 7) cells.push(null);
    return Array.from({ length: cells.length / 7 }, (_, index) => cells.slice(index * 7, index * 7 + 7));
});

const showDivision = ref(true);

const leaveOn = (date) => props.leave.filter((item) => item.date_from <= iso(date) && item.date_to >= iso(date));
// Co-workers' days already skip weekends and non-working holidays (server side).
const colleaguesByDate = computed(() => {
    const map = {};
    for (const item of props.colleagues) {
        for (const date of item.dates) (map[date] ??= []).push(item);
    }
    for (const list of Object.values(map)) list.sort((a, b) => a.name.localeCompare(b.name));
    return map;
});
const colleaguesOn = (date) => (showDivision.value ? colleaguesByDate.value[iso(date)] ?? [] : []);
const holidayOn = (date) => props.holidays.find((holiday) => holiday.date === iso(date));
const today = iso(new Date());

const selected = ref(props.month === today.slice(0, 7) ? today : null);
const selectedColleagues = computed(() => (selected.value ? colleaguesByDate.value[selected.value] ?? [] : []));

const pending = (item) => item.status !== 'approved';
</script>

<template>
    <Head title="Leave Calendar" />

    <PageHeader title="Leave Calendar" :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Leave', href: '/my/leave' }, { label: 'Leave Calendar' }]">
        <template #actions>
            <Checkbox v-if="division || seesEveryone" id="cal-division" v-model="showDivision" :label="seesEveryone ? 'Show everyone' : 'Show my division'" />
            <Button variant="secondary" @click="shift(-1)" aria-label="Previous month">‹</Button>
            <Button variant="secondary" @click="shift(1)" aria-label="Next month">›</Button>
            <Link href="/my/leave/apply" class="inline-flex items-center gap-2 rounded-md bg-success px-4 py-2 text-sm font-medium text-white hover:brightness-95">
                <Icon name="plus" size="sm" />File leave
            </Link>
        </template>
    </PageHeader>

    <BusyRegion :busy="busy">
        <Card :title="title" icon="calendar">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[42rem] table-fixed border-collapse text-sm">
                <caption class="sr-only">Your leave, your division's leave and holidays, {{ title }}</caption>
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
                                    class="w-full rounded px-0.5 text-left text-xs font-semibold text-text hover:bg-surface-muted focus:outline-none focus:ring-2 focus:ring-focus-ring"
                                    :aria-label="`${formatCalendarDate(iso(date))}: show who is on leave`"
                                    :aria-pressed="iso(date) === selected"
                                    @click="selected = iso(date)"
                                >
                                    {{ date.getDate() }}
                                </button>
                                <div v-if="holidayOn(date)" class="mt-0.5 truncate rounded bg-info-bg px-1 text-[0.7rem] text-info" :title="holidayOn(date).name">{{ holidayOn(date).name }}</div>
                                <Link
                                    v-for="item in leaveOn(date)"
                                    :key="item.id"
                                    :href="`/leave/applications/${item.id}`"
                                    class="mt-0.5 block truncate rounded px-1 text-[0.7rem]"
                                    :class="pending(item) ? 'border border-warning text-warning' : 'bg-success text-white'"
                                    :title="`${item.name} (${pending(item) ? 'not yet approved' : 'approved'})`"
                                >
                                    {{ item.code }}<template v-if="item.is_half_day"> ½ {{ item.half_day_period }}</template>
                                </Link>
                                <div
                                    v-for="item in colleaguesOn(date).slice(0, MAX_COLLEAGUES_PER_DAY)"
                                    :key="`c${item.id}`"
                                    class="mt-0.5 truncate rounded px-1 text-[0.7rem]"
                                    :class="pending(item) ? 'border border-primary text-primary' : 'bg-primary text-white'"
                                    :title="`${item.name} (${pending(item) ? 'filed, not yet approved' : 'approved'})`"
                                >
                                    {{ item.surname }}<template v-if="item.is_half_day"> ½</template>
                                </div>
                                <button
                                    v-if="colleaguesOn(date).length > MAX_COLLEAGUES_PER_DAY"
                                    type="button"
                                    class="mt-0.5 text-[0.7rem] font-medium text-primary hover:underline"
                                    @click="selected = iso(date)"
                                >
                                    +{{ colleaguesOn(date).length - MAX_COLLEAGUES_PER_DAY }} more
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-text-muted">
            Green: your leave. <template v-if="seesEveryone">Blue: colleagues in every office. </template><template v-else-if="division">Blue: co-workers in {{ division }}. </template>Outlined: under review or returned. Light blue: holiday.
        </p>
    </Card>
    </BusyRegion>

    <Card v-if="(division || seesEveryone) && showDivision && selected" class="mt-6" icon="users" :title="`${seesEveryone ? 'Everyone' : 'Your division'} on ${formatCalendarDate(selected)}`">
        <p v-if="!selectedColleagues.length" class="text-sm text-text-muted">No one else {{ seesEveryone ? 'in any office' : `in ${division}` }} has filed leave for this day.</p>
        <ul v-else class="divide-y divide-border">
            <li v-for="item in selectedColleagues" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                <div>
                    <div class="font-medium text-text">{{ item.name }}<span v-if="seesEveryone && item.office" class="ml-2 text-xs font-normal text-text-muted">{{ item.office }}</span></div>
                    <div class="text-xs text-text-muted">{{ leaveDates(item, formatCalendarDate) }}</div>
                </div>
                <StatusBadge :status="leaveStatusTones[item.status]" :label="item.status_label" />
            </li>
        </ul>
    </Card>
</template>
