<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Badge from '../../../../Components/UI/Badge.vue';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-8 monitoring: every loan repaid through payroll, with what's paid and
 * what's left. Loans are added and changed on each person's pay profile.
 */
const props = defineProps({
    loans: { type: Array, required: true },
    nearlyPaidMonths: { type: Number, required: true },
});

const filters = [
    { value: 'active', label: 'Running' },
    { value: 'nearly', label: 'Nearly paid' },
    { value: 'paid', label: 'Paid' },
    { value: 'stopped', label: 'Stopped' },
    { value: 'all', label: 'All' },
];
const filter = ref('active');
const nearly = (loan) => loan.status === 'active' && loan.months_left !== null && loan.months_left <= props.nearlyPaidMonths;
const rows = computed(() =>
    props.loans.filter((loan) => (filter.value === 'all' ? true : filter.value === 'nearly' ? nearly(loan) : loan.status === filter.value)),
);
const count = (value) => props.loans.filter((loan) => (value === 'all' ? true : value === 'nearly' ? nearly(loan) : loan.status === value)).length;
const outstanding = computed(() => rows.value.reduce((sum, loan) => sum + loan.balance, 0));

const tones = { active: 'active', paid: 'info', stopped: 'logged_out' };
const columns = [
    { key: 'employee', label: 'Employee' },
    { key: 'type', label: 'Loan' },
    { key: 'monthly_amortization', label: 'Monthly', align: 'right' },
    { key: 'total_payable', label: 'Payable', align: 'right' },
    { key: 'paid', label: 'Paid', align: 'right' },
    { key: 'balance', label: 'Balance', align: 'right' },
    { key: 'months_left', label: 'Months left', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <Head title="Loans" />

    <PageHeader
        title="Loans"
        description="Every loan repaid through payroll. Balances count finalized payrolls only; add or change loans on each person's pay profile."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll', href: '/admin/hr/payroll' }, { label: 'Loans' }]"
    />

    <Card title="Loans" icon="key" :description="`${rows.length} shown · ${formatPeso(outstanding)} outstanding`">
        <template #actions>
            <div class="flex flex-wrap gap-1" role="group" aria-label="Show">
                <button
                    v-for="option in filters"
                    :key="option.value"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium"
                    :class="filter === option.value ? 'bg-primary text-white' : 'text-text hover:bg-surface-muted'"
                    :aria-pressed="filter === option.value"
                    @click="filter = option.value"
                >
                    {{ option.label }} ({{ count(option.value) }})
                </button>
            </div>
        </template>

        <DataTable :columns="columns" :rows="rows" caption="Loans" :export-name="`imis-loans-${filter}`" empty-text="No loans here.">
            <template #cell-employee="{ row }">
                <Link :href="`/admin/hr/payroll/profiles/${row.employee_id}`" class="font-medium text-text hover:text-primary">{{ row.employee }}</Link>
            </template>
            <template #cell-type="{ row }">
                {{ row.type }}
                <div class="text-xs text-text-muted"><template v-if="row.reference">{{ row.reference }} · </template>from {{ row.start_month }}</div>
            </template>
            <template #cell-monthly_amortization="{ row }"><span class="tabular-nums">{{ formatPeso(row.monthly_amortization) }}</span></template>
            <template #cell-total_payable="{ row }"><span class="tabular-nums">{{ formatPeso(row.total_payable) }}</span></template>
            <template #cell-paid="{ row }"><span class="tabular-nums">{{ formatPeso(row.paid) }}</span></template>
            <template #cell-balance="{ row }"><span class="font-medium tabular-nums">{{ formatPeso(row.balance) }}</span></template>
            <template #cell-months_left="{ row }">
                <span class="tabular-nums">{{ row.status === 'active' ? row.months_left : '—' }}</span>
                <Badge v-if="nearly(row)" tone="warning" class="ml-1">Nearly paid</Badge>
            </template>
            <template #cell-status="{ row }"><StatusBadge :status="tones[row.status]" :label="row.status" /></template>
        </DataTable>
    </Card>
</template>
