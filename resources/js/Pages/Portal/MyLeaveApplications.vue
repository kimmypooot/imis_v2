<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Icon from '../../Components/UI/Icon.vue';
import { formatCalendarDate } from '../../Utils/datetime';
import { leaveDates, leaveStatusTones } from '../../Components/HR/leaveStatus';

defineOptions({ layout: PortalLayout });

/**
 * HR-6b self-service: your leave applications (legacy my-applications.php),
 * with summary tiles that double as a status filter.
 */
const props = defineProps({
    applications: { type: Array, required: true },
});

const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'type_name', label: 'Type of leave' },
    { key: 'date_from', label: 'Dates', value: (row) => leaveDates(row, formatCalendarDate) },
    { key: 'status_label', label: 'Status' },
];

const matches = {
    all: () => true,
    pending: (row) => row.status === 'for_review',
    returned: (row) => row.status === 'returned',
    approved: (row) => row.status === 'approved',
};

const filter = ref('all');
const count = (key) => props.applications.filter(matches[key]).length;

const stats = computed(() => [
    { key: 'all', label: 'Filed', icon: 'clipboard', tile: 'bg-info-bg text-primary' },
    { key: 'pending', label: 'For review', icon: 'clock', tile: 'bg-warning-bg text-warning' },
    { key: 'returned', label: 'Needs correction', icon: 'exclamation', tile: 'bg-danger-bg text-danger' },
    { key: 'approved', label: 'Approved', icon: 'check', tile: 'bg-success-bg text-success' },
].map((stat) => ({ ...stat, value: count(stat.key) })));

const rows = computed(() => props.applications.filter(matches[filter.value]));
</script>

<template>
    <Head title="My Leave Applications" />

    <PageHeader
        title="My Leave Applications"
        description="Everything you've filed, and where each application stands."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Leave', href: '/my/leave' }, { label: 'My Leave Applications' }]"
    >
        <template #actions>
            <Button variant="success" href="/my/leave/apply"><Icon name="plus" size="sm" />File leave</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" role="group" aria-label="Filter applications">
            <button
                v-for="stat in stats"
                :key="stat.key"
                type="button"
                class="group flex items-center gap-3.5 rounded-lg border bg-surface p-4 text-left transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="filter === stat.key ? 'border-primary ring-1 ring-primary' : 'border-border'"
                :aria-pressed="filter === stat.key"
                @click="filter = stat.key"
            >
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md transition-transform duration-200 group-hover:scale-105" :class="stat.tile" aria-hidden="true">
                    <Icon :name="stat.icon" />
                </span>
                <span class="min-w-0">
                    <span class="block text-xl leading-none font-semibold text-text tabular-nums">{{ stat.value }}</span>
                    <span class="mt-1 block text-xs text-text-muted">{{ stat.label }}</span>
                </span>
            </button>
        </div>

        <Card title="Applications" icon="clipboard">
            <DataTable :columns="columns" :rows="rows" caption="My leave applications" export-name="imis-my-leave-applications" :empty-text="applications.length ? 'None match this filter.' : 'You haven\'t filed any leave yet.'">
                <template #cell-reference_number="{ row }">
                    <Link :href="`/leave/applications/${row.id}`" class="font-medium text-primary hover:underline">{{ row.reference_number }}</Link>
                </template>
                <template #cell-date_from="{ row }">{{ leaveDates(row, formatCalendarDate) }}</template>
                <template #cell-status_label="{ row }"><StatusBadge :status="leaveStatusTones[row.status]" :label="row.status_label" /></template>
            </DataTable>
        </Card>
    </div>
</template>
