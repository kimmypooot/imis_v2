<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import { formatCalendarDate } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * DTR: the days your people entered themselves on their Daily Time Record
 * (CS Form 48 asks the supervisor or office head to verify the office hours).
 * Verify the ones you can vouch for; leave the rest, and tell the employee.
 */
const props = defineProps({
    entries: { type: Array, required: true },
});

const picked = ref([]);
const form = useForm({ ids: [] });
const clock = (value) => {
    if (!value) return '—';
    const [h, m] = value.split(':').map(Number);
    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
};
const verify = (ids) => {
    form.ids = ids;
    form.post('/dtr/entries', { preserveScroll: true, onSuccess: () => (picked.value = []) });
};

const columns = [
    { key: 'employee', label: 'Employee' },
    { key: 'date', label: 'Day', value: (row) => row.date },
    { key: 'times', label: 'Entered', value: (row) => `${clock(row.time_in)} to ${clock(row.time_out)}` },
    { key: 'remark', label: 'Reason' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'right' },
];
</script>

<template>
    <Head title="DTR entries to verify" />

    <PageHeader title="DTR entries to verify" description="Times your people entered on their own DTR, for a day the fingerprint reader missed." :breadcrumbs="[{ label: 'Home', href: '/' }, { label: 'DTR entries to verify' }]" />

    <Card v-if="!entries.length">
        <EmptyState icon="check" title="Nothing to verify" description="When someone you supervise or head enters times on their DTR, they appear here and you are told under the bell." />
    </Card>

    <Card v-else :title="`${entries.length} ${entries.length === 1 ? 'day' : 'days'} waiting`" icon="clock">
        <p class="mb-4 text-sm text-text-muted">Verify only the days you can vouch for: you saw them at work, or you know of the failed reader, the event or the travel. The employee already certified the entry as true and correct.</p>
        <DataTable
            v-model:selected="picked"
            :columns="columns"
            :rows="entries"
            selectable
            caption="DTR entries waiting for your verification"
            export-name="imis-dtr-entries-to-verify"
            :numbered="false"
            :default-sort="{ key: 'employee', direction: 'asc' }"
        >
            <template #cell-employee="{ row }">
                <PersonCell :name="row.employee" :photo-url="row.photo_url" />
            </template>
            <template #cell-date="{ row }"><span class="whitespace-nowrap font-medium">{{ formatCalendarDate(row.date) }}</span></template>
            <template #cell-times="{ row }"><span class="whitespace-nowrap tabular-nums">{{ clock(row.time_in) }} to {{ clock(row.time_out) }}</span></template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="secondary" :loading="form.processing" @click="verify([row.id])">Verify</Button>
            </template>
            <template #bulk="{ selected }">
                <Button size="sm" icon="check" :loading="form.processing" @click="verify(selected)">Verify {{ selected.length }} selected</Button>
            </template>
        </DataTable>
    </Card>
</template>
