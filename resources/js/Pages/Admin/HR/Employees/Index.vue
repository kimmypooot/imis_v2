<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
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
    { key: 'name', label: 'Employee', value: (employee) => employee.user.list_name },
    { key: 'employee_number', label: 'Employee no.' },
    {
        key: 'unit',
        label: 'Office',
        value: (employee) => [employee.user.unit, ...employee.user.concurrent_units.map((unit) => `${unit.capacity} of ${unit.office}`)].filter(Boolean).join('; '),
    },
    { key: 'employment_status_label', label: 'Employment status' },
    { key: 'csc_years', label: 'Years in CSC', align: 'right', value: (employee) => employee.csc_service?.years ?? '' },
    { key: 'service', label: 'Service', align: 'center', value: (employee) => (employee.separated ? 'Separated' : 'In Service') },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '5rem' },
];

const modalOpen = ref(false);

/*
 * Tabs by status: in service (the default), each employment status that
 * has anyone in service, not set, separated, and all. Separated people
 * only show under Separated and All. The tab is kept in the URL (?status=)
 * so it survives a refresh and the back button.
 */
const counts = computed(() => {
    const counts = { in_service: 0, not_set: 0, separated: 0, all: props.employees.length };
    for (const employee of props.employees) {
        if (employee.separated) {
            counts.separated++;
            continue;
        }
        counts.in_service++;
        const key = employee.employment_status ?? 'not_set';
        counts[key] = (counts[key] ?? 0) + 1;
    }
    return counts;
});

const tabs = computed(() => [
    { key: 'in_service', label: 'In service' },
    ...props.employmentStatusOptions.map((option) => ({ key: option.value, label: option.label })).filter((tab) => counts.value[tab.key]),
    ...(counts.value.not_set ? [{ key: 'not_set', label: 'Not set' }] : []),
    ...(counts.value.separated ? [{ key: 'separated', label: 'Separated' }] : []),
    { key: 'all', label: 'All' },
]);

const status = ref(new URLSearchParams(window.location.search).get('status') ?? 'in_service');
// A tab with nobody left in it (e.g. after an edit) falls back to In service.
const activeStatus = computed(() => (tabs.value.some((tab) => tab.key === status.value) ? status.value : 'in_service'));
const activeLabel = computed(() => tabs.value.find((tab) => tab.key === activeStatus.value)?.label ?? '');

const selectStatus = (key) => {
    status.value = key;
    const url = new URL(window.location.href);
    if (key === 'in_service') url.searchParams.delete('status');
    else url.searchParams.set('status', key);
    window.history.replaceState(window.history.state, '', url);
};

const visible = computed(() =>
    props.employees.filter((employee) => {
        switch (activeStatus.value) {
            case 'all':
                return true;
            case 'separated':
                return employee.separated;
            case 'in_service':
                return !employee.separated;
            case 'not_set':
                return !employee.separated && !employee.employment_status;
            default:
                return !employee.separated && employee.employment_status === activeStatus.value;
        }
    }),
);
</script>

<template>
    <Head title="Employees" />

    <PageHeader title="Employees" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Employees' }]" />

    <Card title="Employee Records" icon="users" description="Personal data under the Data Privacy Act. Every record you open is logged.">
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

        <nav v-if="employees.length" aria-label="Filter by status" class="-mt-1 mb-4 overflow-x-auto border-b border-border">
            <ul class="flex min-w-max gap-1">
                <li v-for="tab in tabs" :key="tab.key">
                    <button
                        type="button"
                        class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-inset"
                        :class="activeStatus === tab.key ? 'border-primary text-link' : 'border-transparent text-text-muted hover:border-border-strong hover:text-text'"
                        :aria-pressed="activeStatus === tab.key"
                        @click="selectStatus(tab.key)"
                    >
                        {{ tab.label }}
                        <span
                            class="rounded-full px-2 py-px text-xs font-semibold tabular-nums"
                            :class="activeStatus === tab.key ? 'bg-primary text-white' : 'bg-surface-muted text-text-muted'"
                        >{{ counts[tab.key] ?? 0 }}</span>
                    </button>
                </li>
            </ul>
        </nav>

        <!-- Keyed by tab: each tab starts on page 1 with its own search. -->
        <DataTable
            :key="activeStatus"
            :columns="columns"
            :rows="visible"
            :caption="`Employee records — ${activeLabel}`"
            :export-name="`imis-employees-${activeStatus.replaceAll('_', '-')}`"
            :empty-text="`No employee records under “${activeLabel}”.`"
        >
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <UserAvatar :name="row.user.name" :photo-url="row.user.profile_photo_url" size="sm" />
                    <div class="min-w-0">
                        <div class="truncate font-medium text-text">{{ row.user.list_name }}</div>
                        <div v-if="row.user.position" class="truncate text-xs text-text-muted">{{ row.user.position }}</div>
                    </div>
                </div>
            </template>
            <template #cell-employee_number="{ row }">{{ row.employee_number || '—' }}</template>
            <template #cell-unit="{ row }">
                {{ row.user.unit || '—' }}
                <div v-if="row.user.concurrent_units.length" class="text-xs text-text-muted">{{ row.user.concurrent_units.map((unit) => `${unit.capacity} of ${unit.office}`).join(', ') }}</div>
            </template>
            <template #cell-csc_years="{ row }">
                <template v-if="row.csc_service">
                    <span class="tabular-nums" :title="`In the CSC since ${row.csc_service.since}`">{{ row.csc_service.label }}</span>
                    <span v-if="row.csc_service.from_other_agency" class="block text-xs text-text-muted">from another agency</span>
                    <StatusBadge v-if="row.csc_service.award_this_year" status="info" :label="`${row.csc_service.award_this_year}-yr loyalty award`" class="mt-1" />
                </template>
                <span v-else class="text-text-muted">—</span>
            </template>
            <template #cell-service="{ row }">
                <StatusBadge :status="row.separated ? 'inactive' : 'active'" :label="row.separated ? 'Separated' : 'In Service'" />
            </template>
            <template #cell-actions="{ row }">
                <ActionButton icon="eye" tone="neutral" :label="`Open the employee record of ${row.user.name}`" :href="`/admin/hr/employees/${row.id}`" />
            </template>
        </DataTable>
    </Card>

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
