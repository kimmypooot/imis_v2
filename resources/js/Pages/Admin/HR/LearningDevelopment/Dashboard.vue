<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import StatTile from '../../../../Components/UI/StatTile.vue';
import SimpleChart from '../../../../Components/Charts/SimpleChart.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-3c: HRD's L&D dashboard (ldms's hrd dashboard) for one year —
 * approved trainings only. Figures come from LdReports.
 */
const props = defineProps({
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    stats: { type: Object, required: true },
    types: { type: Array, required: true },
    top: { type: Array, required: true },
    monthly: { type: Array, required: true },
    recent: { type: Array, required: true },
});

const changeYear = (value) => router.get('/admin/hr/learning-development/dashboard', { year: value }, { preserveScroll: true });

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const hours = (value) => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });

// ldms showed the change in trainings against the year before.
const change = computed(() => {
    const { trainings, trainings_previous: previous } = props.stats;
    if (!previous) return null;
    return Math.round(((trainings - previous) / previous) * 100);
});

const reached = computed(() => (props.stats.employees ? Math.round((props.stats.trained / props.stats.employees) * 100) : 0));

const dateRange = (row) =>
    row.date_to && row.date_to !== row.date_from
        ? `${formatCalendarDate(row.date_from)} – ${formatCalendarDate(row.date_to)}`
        : formatCalendarDate(row.date_from);
</script>

<template>
    <Head title="L&D Dashboard" />

    <PageHeader
        title="L&D Dashboard"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'L&D Dashboard' }]"
    >
        <template #actions>
            <label class="sr-only" for="ld-dashboard-year">Year</label>
            <select
                id="ld-dashboard-year"
                :value="year"
                class="form-control px-3 py-2"
                @change="changeYear($event.target.value)"
            >
                <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
            </select>
            <Link
                :href="`/admin/hr/learning-development/report?year=${year}`"
                class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            >
                <Icon name="printer" size="sm" />Training report
            </Link>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <p class="text-sm text-text-muted">Approved trainings that started in {{ year }}. Pending and rejected entries aren't counted.</p>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile label="Employees" :value="stats.employees" icon="users" tone="primary" :progress="reached">
                <span class="font-semibold text-success-fg">{{ stats.trained }}</span> with training this year ({{ reached }}%)
            </StatTile>
            <StatTile label="Trainings" :value="stats.trainings" icon="clipboard" tone="success">
                <template v-if="change !== null">
                    <span class="font-semibold" :class="change >= 0 ? 'text-success-fg' : 'text-danger-fg'">{{ change >= 0 ? '+' : '' }}{{ change }}%</span>
                    vs {{ year - 1 }} ({{ stats.trainings_previous }})
                </template>
                <template v-else>None recorded in {{ year - 1 }}</template>
            </StatTile>
            <StatTile label="Training hours" :value="hours(stats.hours)" icon="clock" tone="primary">
                {{ stats.trainings ? `${hours(stats.hours / stats.trainings)} hours per training` : 'No trainings yet' }}
            </StatTile>
            <StatTile label="Offices" :value="stats.offices" icon="building" tone="info">offices with employees</StatTile>
        </div>

        <div class="grid gap-4 xl:grid-cols-12">
            <Card title="Monthly activity" :description="`Trainings by start month, ${year}`" icon="activity" class="xl:col-span-8">
                <SimpleChart
                    type="line"
                    :labels="MONTHS"
                    :values="monthly"
                    label="Trainings"
                    :summary="`Approved trainings per month in ${year}; ${stats.trainings} in total.`"
                />
            </Card>
            <Card title="By type" description="PDS types of intervention" icon="chartBar" class="xl:col-span-4">
                <SimpleChart
                    type="bar"
                    :labels="types.map((type) => type.label)"
                    :values="types.map((type) => type.count)"
                    label="Trainings"
                    :height="Math.max(160, types.length * 38)"
                    :summary="`Approved trainings in ${year} by type of intervention.`"
                />
            </Card>
        </div>

        <div class="grid gap-4 xl:grid-cols-12">
            <Card title="Top employees" description="Most training hours" icon="users" class="xl:col-span-5">
                <p v-if="top.length === 0" class="text-sm text-text-muted">No approved trainings in {{ year }}.</p>
                <ol v-else class="divide-y divide-border">
                    <li v-for="(person, index) in top" :key="person.id" class="flex items-center gap-3 py-2.5">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-info-bg text-xs font-bold text-link">{{ index + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <Link :href="`/admin/hr/employees/${person.id}/learning-development?year=${year}`" class="font-medium text-link hover:underline">{{ person.name }}</Link>
                            <div class="text-xs text-text-muted">{{ person.office || '—' }} · {{ person.trainings }} {{ person.trainings === 1 ? 'training' : 'trainings' }}</div>
                        </div>
                        <span class="text-sm font-semibold text-text tabular-nums">{{ hours(person.hours) }} h</span>
                    </li>
                </ol>
            </Card>

            <Card title="Recent trainings" description="Last recorded" icon="clipboard" class="xl:col-span-7">
                <p v-if="recent.length === 0" class="text-sm text-text-muted">No approved trainings in {{ year }}.</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="row in recent" :key="row.id" class="py-2.5">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span class="font-medium text-text">{{ row.title }}</span>
                            <span class="text-xs text-text-muted tabular-nums">{{ hours(row.hours) }} h · {{ dateRange(row) }}</span>
                        </div>
                        <div class="text-xs text-text-muted">
                            <Link :href="`/admin/hr/employees/${row.employee_id}/learning-development?year=${year}`" class="text-link hover:underline">{{ row.name }}</Link>
                            · {{ row.office || '—' }} · {{ row.type_label }}<template v-if="row.sponsor"> · {{ row.sponsor }}</template>
                        </div>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
