<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../../Components/UI/AdminCard.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-6b: officers-in-charge for the Head of Agency (legacy
 * hrd/designations.php). While a designation runs, the designee can act
 * on the Head's step of leave applications.
 */
const props = defineProps({
    designations: { type: Array, required: true },
    userOptions: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const open = ref(false);
const editing = ref(null);
const edit = (row = null) => {
    editing.value = row;
    open.value = true;
};
const remove = async (row) => {
    if (await confirm({ title: `Remove ${row.name}'s designation?`, text: 'Steps they already acted on stay as recorded.', tone: 'danger', confirmText: 'Remove' })) {
        router.delete(`/admin/hr/leave/designations/${row.id}`, { preserveScroll: true });
    }
};

const fields = [
    { key: 'designee_id', label: 'Officer-in-charge', type: 'select', required: true, span: 'full', options: props.userOptions },
    { key: 'acting_for_id', label: 'Acting for (the Head of Agency)', type: 'select', span: 'full', options: [{ value: '', label: '—' }, ...props.userOptions] },
    { key: 'date_from', label: 'From', type: 'date', required: true },
    { key: 'date_to', label: 'To', type: 'date', required: true },
    { key: 'order_number', label: 'Office/Special Order No.' },
    { key: 'remarks', label: 'Remarks', type: 'textarea' },
];

const columns = [
    { key: 'name', label: 'Officer-in-charge' },
    { key: 'acting_for', label: 'Acting for', value: (row) => row.acting_for ?? '' },
    { key: 'date_from', label: 'Period' },
    { key: 'order_number', label: 'Order No.', value: (row) => row.order_number ?? '' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center' },
];
</script>

<template>
    <Head title="OIC Designations" />

    <PageHeader title="OIC Designations" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Human Resources' }, { label: 'OIC Designations' }]">
        <template v-if="canManage" #actions>
            <Button variant="success" @click="edit()"><Icon name="plus" size="sm" />Add designation</Button>
        </template>
    </PageHeader>

    <AdminCard title="Officers-in-charge" icon="users" subtitle="Who acts for the Head of Agency on leave applications, and when.">
        <DataTable :columns="columns" :rows="designations" caption="OIC designations" export-name="imis-oic-designations" empty-text="No designations.">
            <template #cell-name="{ row }">
                {{ row.name }}
                <StatusBadge v-if="row.active" status="active" label="On duty" class="ml-2" />
            </template>
            <template #cell-acting_for="{ row }">{{ row.acting_for || '—' }}</template>
            <template #cell-date_from="{ row }"><span class="whitespace-nowrap">{{ formatCalendarDate(row.date_from) }} – {{ formatCalendarDate(row.date_to) }}</span></template>
            <template #cell-order_number="{ row }">{{ row.order_number || '—' }}</template>
            <template #cell-actions="{ row }">
                <div v-if="canManage" class="flex justify-center gap-3 text-sm">
                    <button type="button" class="text-primary hover:underline" @click="edit(row)">Edit</button>
                    <button type="button" class="text-danger hover:underline" @click="remove(row)">Remove</button>
                </div>
            </template>
        </DataTable>
    </AdminCard>

    <RecordModal
        v-if="canManage"
        :open="open"
        noun="Designation"
        :record="editing"
        :fields="fields"
        :defaults="{ designee_id: '', acting_for_id: '', date_from: '', date_to: '', order_number: '', remarks: '' }"
        :nullable="['acting_for_id', 'order_number', 'remarks']"
        store-url="/admin/hr/leave/designations"
        :update-url="(record) => `/admin/hr/leave/designations/${record.id}`"
        @close="open = false"
    />
</template>
