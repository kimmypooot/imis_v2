<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../../Components/UI/AdminCard.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import Button from '../../../../Components/UI/Button.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import UserAvatar from '../../../../Components/UI/UserAvatar.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import BulkSetupModal from '../../../../Components/HR/BulkSetupModal.vue';
import { employeeDefaults, employeeFields, employeeNullable } from '../../../../Components/HR/employeeFields';
import { useAuthorization } from '../../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

/**
 * HR-1: Human Resources → Employees. HR sees every record; a supervisor
 * (employees.view_unit) sees their own office's. Opening a record is
 * logged, so the list itself shows only what's needed to find someone.
 */
const props = defineProps({
    employees: { type: Array, required: true },
    employmentStatusOptions: { type: Array, required: true },
    // Active accounts without a record yet (only for those who can create).
    candidates: { type: Array, default: () => [] },
});

const { can } = useAuthorization();

const userOptions = computed(() => props.candidates.map((candidate) => ({ value: candidate.id, label: `${candidate.name} (${candidate.username})` })));
const bulkOpen = ref(false);

const columns = [
    { key: 'name', label: 'Employee', value: (employee) => employee.user.name },
    { key: 'employee_number', label: 'Employee no.' },
    { key: 'unit', label: 'Office', value: (employee) => employee.user.unit ?? '' },
    { key: 'employment_status_label', label: 'Employment status' },
    { key: 'service', label: 'Service', align: 'center', value: (employee) => (employee.separated ? 'Separated' : 'In service') },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '5rem' },
];

const modalOpen = ref(false);
</script>

<template>
    <Head title="Employees" />

    <PageHeader title="Employees" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Human Resources' }, { label: 'Employees' }]" />

    <AdminCard title="Employee Records" icon="users" subtitle="Personal data under the Data Privacy Act. Every record you open is logged.">
        <template v-if="can('employees.create')" #actions>
            <Button v-if="candidates.length > 1" variant="secondary" class="rounded-full" @click="bulkOpen = true">
                <Icon name="users" size="sm" />Set up records for staff ({{ candidates.length }})
            </Button>
            <Button variant="success" class="rounded-full" :disabled="userOptions.length === 0" @click="modalOpen = true">
                <Icon name="plus" size="sm" />Add Employee Record
            </Button>
        </template>

        <p v-if="employees.length === 0 && candidates.length > 0" class="mb-4 rounded-md bg-info-bg px-4 py-3 text-sm text-text">
            No employee records yet. {{ candidates.length }} active {{ candidates.length === 1 ? 'account has' : 'accounts have' }} none —
            use <strong>Set up records for staff</strong> to create them in one go.
        </p>

        <DataTable :columns="columns" :rows="employees" caption="Employee records" export-name="imis-employees">
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <UserAvatar :name="row.user.name" :photo-url="row.user.profile_photo_url" size="sm" />
                    <div class="min-w-0">
                        <div class="truncate font-medium text-text">{{ row.user.name }}</div>
                        <div v-if="row.user.position" class="truncate text-xs text-text-muted">{{ row.user.position }}</div>
                    </div>
                </div>
            </template>
            <template #cell-employee_number="{ row }">{{ row.employee_number || '—' }}</template>
            <template #cell-unit="{ row }">{{ row.user.unit || '—' }}</template>
            <template #cell-service="{ row }">
                <StatusBadge :status="row.separated ? 'inactive' : 'active'" :label="row.separated ? 'Separated' : 'In service'" />
            </template>
            <template #cell-actions="{ row }">
                <ActionButton icon="eye" tone="neutral" :label="`Open the employee record of ${row.user.name}`" :href="`/admin/hr/employees/${row.id}`" />
            </template>
        </DataTable>
    </AdminCard>

    <RecordModal
        :open="modalOpen"
        noun="Employee Record"
        :record="null"
        :fields="employeeFields(employmentStatusOptions, userOptions)"
        :defaults="{ user_id: '', ...employeeDefaults }"
        :nullable="employeeNullable"
        store-url="/admin/hr/employees"
        :update-url="(employee) => `/admin/hr/employees/${employee.id}`"
        @close="modalOpen = false"
    />

    <BulkSetupModal :open="bulkOpen" :candidates="candidates" @close="bulkOpen = false" />
</template>
