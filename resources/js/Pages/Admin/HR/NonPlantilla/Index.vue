<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import MetricCard from '../../../../Components/Admin/MetricCard.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import { contractDefaults, contractFields, contractNullable } from '../../../../Components/HR/nonPlantillaFields';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * Staff who hold no plantilla item — casual, contractual, Job Order and
 * Contract of Service — with their current contract, flagged when it ends
 * within `expiringDays` or has ended. Each person's contract history opens
 * from their row; renewing pre-fills the next period.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    statusOptions: { type: Array, required: true },
    expiringDays: { type: Number, required: true },
    canManage: { type: Boolean, required: true },
    contractForm: { type: Object, default: null },
});

const status = ref('all');
const statusTabs = computed(() => [
    { key: 'all', label: `All (${props.rows.length})` },
    ...props.statusOptions.map((option) => ({ key: option.value, label: `${option.label} (${props.rows.filter((row) => row.status === option.value).length})` })),
]);
const shown = computed(() => (status.value === 'all' ? props.rows : props.rows.filter((row) => row.status === status.value)));

const counts = computed(() => ({
    running: props.rows.filter((row) => ['current', 'expiring'].includes(row.state)).length,
    expiring: props.rows.filter((row) => row.state === 'expiring').length,
    attention: props.rows.filter((row) => ['ended', 'none'].includes(row.state)).length,
}));

const stateBadges = {
    current: { tone: 'active', label: 'Running' },
    expiring: { tone: 'warning', label: 'Ending soon' },
    ended: { tone: 'inactive', label: 'Ended' },
    upcoming: { tone: 'info', label: 'Starts later' },
    none: { tone: 'draft', label: 'No contract' },
};

const period = (contract) =>
    contract ? `${formatCalendarDate(contract.date_from)} – ${contract.date_to ? formatCalendarDate(contract.date_to) : 'open-ended'}` : '';
const rate = (contract) => (contract?.rate != null ? `${formatPeso(contract.rate)}${contract.rate_basis ? ` / ${contract.rate_basis === 'daily' ? 'day' : 'month'}` : ''}` : '');

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'designation', label: 'Designation', value: (row) => row.designation ?? '' },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
    { key: 'status_label', label: 'Status' },
    { key: 'period', label: 'Contract period', value: (row) => period(row.contract) },
    { key: 'rate', label: 'Rate', align: 'right', value: (row) => rate(row.contract) },
    { key: 'state', label: 'Contract', value: (row) => stateBadges[row.state].label },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center' },
];

// Add / edit / renew one contract.
const editing = ref(null);
const formOpen = ref(false);
const formDefaults = ref(contractDefaults);
const nextDay = (date) => {
    const [y, m, d] = date.split('-').map(Number);
    const next = new Date(y, m - 1, d + 1);
    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
};
const openNew = (row = null) => {
    const last = row?.contracts[0];
    editing.value = null;
    formDefaults.value = row
        ? {
              ...contractDefaults,
              ...(last ? { ...last, id: undefined, date_from: last.date_to ? nextDay(last.date_to) : '', date_to: '', reference: '', remarks: '' } : { designation: row.designation ?? '' }),
              employee_id: row.employee_id,
              employment_status: last?.employment_status ?? row.status,
          }
        : contractDefaults;
    formOpen.value = true;
};
const openEdit = (contract) => {
    editing.value = contract;
    formDefaults.value = contractDefaults;
    formOpen.value = true;
};
const fields = computed(() =>
    props.contractForm
        ? contractFields({ statusOptions: props.statusOptions, officeOptions: props.contractForm.officeOptions, employeeOptions: editing.value ? null : props.contractForm.employeeOptions })
        : [],
);

// One person's contract history.
const historyId = ref(null);
const history = computed(() => props.rows.find((row) => row.employee_id === historyId.value) ?? null);

const remove = async (contract) => {
    if (await confirm({ title: 'Remove this contract?', text: `${contract.designation}, ${period(contract)}.`, confirmText: 'Remove', tone: 'danger' })) {
        router.delete(`/admin/hr/non-plantilla/contracts/${contract.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Non-Plantilla Personnel" />

    <PageHeader title="Non-Plantilla Personnel" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Non-Plantilla' }]">
        <template v-if="canManage" #actions>
            <Button variant="success" @click="openNew()"><Icon name="plus" size="sm" />Record contract</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <p class="text-sm text-text-muted">
            Casual and contractual appointees, Job Order and Contract of Service workers. They hold no plantilla item, so they're kept here instead of on the
            <Link href="/admin/hr/plantilla" class="font-medium text-primary hover:underline">Plantilla</Link>.
        </p>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard label="Personnel" :value="rows.length" icon="users" tone="primary">in service</MetricCard>
            <MetricCard label="Running contracts" :value="counts.running" icon="clipboard" tone="success">as of today</MetricCard>
            <MetricCard label="Ending soon" :value="counts.expiring" icon="clock" tone="warning">within {{ expiringDays }} days</MetricCard>
            <MetricCard label="Ended or none" :value="counts.attention" icon="exclamation" tone="info">needs a contract</MetricCard>
        </div>

        <Card title="Personnel" icon="users">
            <template #actions>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Status">
                    <button
                        v-for="tab in statusTabs"
                        :key="tab.key"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="status === tab.key ? 'bg-primary text-white' : 'text-text hover:bg-surface-muted'"
                        :aria-pressed="status === tab.key"
                        @click="status = tab.key"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </template>

            <DataTable :columns="columns" :rows="shown" caption="Non-plantilla personnel" :export-name="`imis-non-plantilla-${status}`" empty-text="No one here.">
                <template #cell-name="{ row }">
                    <Link :href="`/admin/hr/employees/${row.employee_id}`" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                </template>
                <template #cell-designation="{ row }">{{ row.designation || '—' }}</template>
                <template #cell-office="{ row }">{{ row.office || '—' }}</template>
                <template #cell-period="{ row }">
                    <template v-if="row.contract">
                        {{ period(row.contract) }}
                        <div v-if="row.state === 'expiring'" class="text-xs text-warning">{{ row.days_left }} {{ row.days_left === 1 ? 'day' : 'days' }} left</div>
                    </template>
                    <span v-else class="text-text-muted">—</span>
                </template>
                <template #cell-rate="{ row }"><span class="tabular-nums">{{ rate(row.contract) || '—' }}</span></template>
                <template #cell-state="{ row }"><StatusBadge :status="stateBadges[row.state].tone" :label="stateBadges[row.state].label" /></template>
                <template #cell-actions="{ row }">
                    <div class="flex justify-center gap-1">
                        <Button variant="secondary" size="sm" @click="historyId = row.employee_id">Contracts ({{ row.contracts.length }})</Button>
                        <Button v-if="row.can_manage" variant="outline" size="sm" @click="openNew(row)">{{ row.contracts.length ? 'Renew' : 'Add' }}</Button>
                    </div>
                </template>
            </DataTable>
        </Card>
    </div>

    <Modal :open="history !== null" :title="history ? `Contracts — ${history.name}` : 'Contracts'" size="lg" @close="historyId = null">
        <template v-if="history">
            <p v-if="!history.contracts.length" class="text-sm text-text-muted">No contract recorded yet.</p>
            <ul v-else class="divide-y divide-border">
                <li v-for="contract in history.contracts" :key="contract.id" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                    <div>
                        <div class="font-medium text-text">{{ contract.designation }} <span class="text-text-muted">· {{ contract.status_label }}</span></div>
                        <div class="text-text-muted">{{ period(contract) }}<template v-if="contract.office"> · {{ contract.office }}</template></div>
                        <div v-if="rate(contract) || contract.funding_source || contract.reference" class="text-xs text-text-muted">
                            {{ [rate(contract), contract.funding_source, contract.reference && `No. ${contract.reference}`].filter(Boolean).join(' · ') }}
                        </div>
                        <div v-if="contract.remarks" class="text-xs text-text-muted">{{ contract.remarks }}</div>
                    </div>
                    <div v-if="history.can_manage" class="flex gap-1">
                        <Button variant="secondary" size="sm" @click="openEdit(contract)">Edit</Button>
                        <Button variant="outline" size="sm" @click="remove(contract)">Remove</Button>
                    </div>
                </li>
            </ul>
        </template>
        <template v-if="history?.can_manage" #footer>
            <Button variant="success" @click="openNew(history)"><Icon name="plus" size="sm" />{{ history.contracts.length ? 'Renew' : 'Add contract' }}</Button>
        </template>
    </Modal>

    <RecordModal
        v-if="canManage"
        :open="formOpen"
        noun="Contract"
        :record="editing"
        :fields="fields"
        :defaults="formDefaults"
        :nullable="contractNullable"
        store-url="/admin/hr/non-plantilla/contracts"
        :update-url="(record) => `/admin/hr/non-plantilla/contracts/${record.id}`"
        @close="formOpen = false"
    />
</template>
