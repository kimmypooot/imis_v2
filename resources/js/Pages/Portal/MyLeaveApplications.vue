<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import FilterTiles from '@cscro8/ui/Components/UI/FilterTiles.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import { formatCalendarDate, formatDate } from '../../Utils/datetime';
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
    { key: 'waiting_for', label: 'Now with', value: (row) => nowWith(row) },
];

// Who has it now: the approver it waits for (and since when), or you when it was returned.
const nowWith = (row) =>
    row.waiting_for ? `${row.waiting_for.holder}, since ${formatDate(row.waiting_for.since)}` : row.status === 'returned' ? 'You, to correct' : '—';

const matches = {
    all: () => true,
    pending: (row) => row.status === 'for_review',
    returned: (row) => row.status === 'returned',
    approved: (row) => row.status === 'approved',
};

const filter = ref('all');
const count = (key) => props.applications.filter(matches[key]).length;

const stats = computed(() => [
    { key: 'all', label: 'Filed', icon: 'clipboard', tone: 'primary' },
    { key: 'pending', label: 'For review', icon: 'clock', tone: 'warning' },
    { key: 'returned', label: 'Needs correction', icon: 'exclamation', tone: 'danger' },
    { key: 'approved', label: 'Approved', icon: 'check', tone: 'success' },
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
        <FilterTiles v-model="filter" label="Filter applications" :options="stats.map((stat) => ({ value: stat.key, label: stat.label, count: stat.value, icon: stat.icon, tone: stat.tone }))" />

        <Card title="Applications" icon="clipboard">
            <DataTable :columns="columns" :rows="rows" caption="My leave applications" export-name="imis-my-leave-applications" :empty-text="applications.length ? 'None match this filter.' : 'You haven\'t filed any leave yet.'">
                <template #cell-reference_number="{ row }">
                    <Link :href="`/leave/applications/${row.id}`" class="font-medium text-link hover:underline">{{ row.reference_number }}</Link>
                </template>
                <template #cell-date_from="{ row }">{{ leaveDates(row, formatCalendarDate) }}</template>
                <template #cell-status_label="{ row }"><StatusBadge :status="leaveStatusTones[row.status]" :label="row.status_label" /></template>
                <template #cell-waiting_for="{ row }">
                    <span v-if="row.waiting_for" class="block">
                        <span class="block font-medium text-text">{{ row.waiting_for.holder }}</span>
                        <span class="block text-xs text-text-muted">since {{ formatDate(row.waiting_for.since) }}</span>
                    </span>
                    <span v-else-if="row.status === 'returned'" class="font-medium text-warning-fg">You, to correct</span>
                    <span v-else class="text-text-muted">—</span>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
