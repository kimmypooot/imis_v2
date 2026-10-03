<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import AdminCard from '../../Components/UI/AdminCard.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-6c: your leave and the holidays, a month at a time (legacy
 * leave/calendar.php). Approved leave is solid; leave still under review
 * or returned is outlined.
 */
const props = defineProps({
    month: { type: String, required: true },
    leave: { type: Array, required: true },
    holidays: { type: Array, required: true },
});

const [year, monthNumber] = props.month.split('-').map(Number);
const first = new Date(year, monthNumber - 1, 1);
const title = first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
const pad = (n) => String(n).padStart(2, '0');
const iso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const shift = (by) => {
    const target = new Date(year, monthNumber - 1 + by, 1);
    router.get('/my/leave/calendar', { month: `${target.getFullYear()}-${pad(target.getMonth() + 1)}` }, { preserveScroll: true });
};

// Weeks from Monday, with days outside the month left blank.
const weeks = computed(() => {
    const cells = [];
    const lead = (first.getDay() + 6) % 7;
    for (let i = 0; i < lead; i++) cells.push(null);
    const last = new Date(year, monthNumber, 0).getDate();
    for (let day = 1; day <= last; day++) cells.push(new Date(year, monthNumber - 1, day));
    while (cells.length % 7) cells.push(null);
    return Array.from({ length: cells.length / 7 }, (_, index) => cells.slice(index * 7, index * 7 + 7));
});

const leaveOn = (date) => props.leave.filter((item) => item.date_from <= iso(date) && item.date_to >= iso(date));
const holidayOn = (date) => props.holidays.find((holiday) => holiday.date === iso(date));
const today = iso(new Date());
</script>

<template>
    <Head title="Leave Calendar" />

    <PageHeader title="Leave Calendar" :breadcrumbs="[{ label: 'My Leave', href: '/my/leave' }, { label: 'Leave Calendar' }]">
        <template #actions>
            <Button variant="secondary" @click="shift(-1)" aria-label="Previous month">‹</Button>
            <Button variant="secondary" @click="shift(1)" aria-label="Next month">›</Button>
            <Link href="/my/leave/apply" class="inline-flex items-center gap-2 rounded-md bg-success px-4 py-2 text-sm font-medium text-white hover:brightness-95">
                <Icon name="plus" size="sm" />File leave
            </Link>
        </template>
    </PageHeader>

    <AdminCard :title="title" icon="calendar">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[42rem] table-fixed border-collapse text-sm">
                <caption class="sr-only">Your leave and holidays, {{ title }}</caption>
                <thead>
                    <tr>
                        <th v-for="day in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="day" scope="col" class="border-b border-border py-2 text-center text-xs font-medium text-text-muted">{{ day }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(week, index) in weeks" :key="index">
                        <td v-for="(date, column) in week" :key="column" class="h-24 border border-border p-1 align-top" :class="[date && column >= 5 ? 'bg-surface-muted' : '', date && iso(date) === today ? 'ring-2 ring-inset ring-primary' : '']">
                            <template v-if="date">
                                <div class="text-xs font-semibold text-text">{{ date.getDate() }}</div>
                                <div v-if="holidayOn(date)" class="mt-0.5 rounded bg-info-bg px-1 text-[0.7rem] text-info">{{ holidayOn(date).name }}</div>
                                <Link
                                    v-for="item in leaveOn(date)"
                                    :key="item.id"
                                    :href="`/leave/applications/${item.id}`"
                                    class="mt-0.5 block truncate rounded px-1 text-[0.7rem]"
                                    :class="item.status === 'approved' ? 'bg-success text-white' : 'border border-warning text-warning'"
                                    :title="`${item.name} (${item.status === 'approved' ? 'approved' : 'not yet approved'})`"
                                >
                                    {{ item.code }}<template v-if="item.is_half_day"> ½ {{ item.half_day_period }}</template>
                                </Link>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-text-muted">Solid: approved. Outlined: under review or returned. Blue: holiday.</p>
    </AdminCard>
</template>
