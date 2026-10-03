<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../../Components/UI/AdminCard.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import LdRejectModal from '../../../../Components/HR/LdRejectModal.vue';
import { formatCalendarDate, formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-3b: employees' L&D entries awaiting HRD review, oldest first
 * (ldms's approve/reject). Your own entries are listed but not reviewable.
 */
defineProps({
    pending: { type: Array, required: true },
    canViewLogs: { type: Boolean, required: true },
});

const columns = [
    { key: 'employee', label: 'Employee', value: (row) => row.employee.name },
    { key: 'title', label: 'Training' },
    { key: 'dates', label: 'Dates', value: (row) => `${row.date_from} – ${row.date_to}` },
    { key: 'hours', label: 'Hours', align: 'right', value: (row) => Number(row.hours) },
    { key: 'type_label', label: 'Type' },
    { key: 'submitted_at', label: 'Submitted', value: (row) => formatDateTime(row.submitted_at) },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];

const approving = ref(null);
const rejecting = ref(null);

const approve = (row) => {
    approving.value = row.id;
    router.post(`/admin/hr/learning-development/reviews/${row.id}/approve`, {}, { preserveScroll: true, onFinish: () => (approving.value = null) });
};
</script>

<template>
    <Head title="L&D Review" />

    <PageHeader
        title="L&D Review"
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Human Resources' }, { label: 'L&D Review' }]"
    />

    <AdminCard title="Awaiting Review" icon="checkCircle" subtitle="Trainings employees added to their own logs. Check the certificate when there is one.">
        <DataTable :columns="columns" :rows="pending" caption="L&D entries awaiting review" export-name="imis-ld-reviews">
            <template #cell-employee="{ row }">
                <Link v-if="canViewLogs" :href="`/admin/hr/employees/${row.employee.id}/learning-development`" class="font-medium text-primary hover:underline">{{ row.employee.name }}</Link>
                <span v-else class="font-medium text-text">{{ row.employee.name }}</span>
                <div class="text-xs text-text-muted">{{ [row.employee.position, row.employee.unit].filter(Boolean).join(' · ') || '—' }}</div>
            </template>
            <template #cell-title="{ row }">
                <div class="font-medium text-text">{{ row.title }}</div>
                <div class="text-xs text-text-muted">{{ row.sponsor }} · {{ row.modality_label }}</div>
                <a
                    v-if="row.certificate"
                    :href="`/hr/trainings/${row.id}/certificate`"
                    target="_blank"
                    rel="noopener"
                    class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                    <Icon name="clipboard" size="sm" />Certificate<span class="sr-only"> (opens in a new tab)</span>
                </a>
                <div v-else class="mt-0.5 text-xs text-text-muted">No certificate</div>
            </template>
            <template #cell-dates="{ row }">
                <span class="whitespace-nowrap">{{ formatCalendarDate(row.date_from) }}</span>
                <template v-if="row.date_to !== row.date_from"><br /><span class="whitespace-nowrap text-text-muted">to {{ formatCalendarDate(row.date_to) }}</span></template>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="row.can_review" class="flex justify-center gap-1">
                    <ActionButton icon="check" tone="success" :label="`Approve ${row.title} of ${row.employee.name}`" :disabled="approving === row.id" @click="approve(row)" />
                    <ActionButton icon="xCircle" tone="danger" :label="`Reject ${row.title} of ${row.employee.name}`" @click="rejecting = row" />
                </div>
                <span v-else class="text-xs text-text-muted" title="Another HRD officer reviews your own entries">Your entry</span>
            </template>
        </DataTable>
    </AdminCard>

    <LdRejectModal :record="rejecting" @close="rejecting = null" />
</template>
