<script setup>
import InputLabel from '../../../../Components/Forms/InputLabel.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import Textarea from '../../../../Components/Forms/Textarea.vue';
import StatTile from '../../../../Components/UI/StatTile.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-8: monthly payrolls and monitoring — the latest payroll, payslip
 * requests waiting for the head of HRD, and loans nearly paid. A new
 * payroll is drafted from plantilla, pay profiles and loans; HR reviews
 * it, then finalizes it.
 */
const props = defineProps({
    runs: { type: Array, required: true },
    requests: { type: Object, required: true },
    loans: { type: Object, required: true },
    approvers: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    nextMonth: { type: String, required: true },
});

const latest = computed(() => props.runs[0] ?? null);

const creating = ref(false);
const form = useForm({ month: props.nextMonth, remarks: '' });
const openCreate = () => {
    form.reset();
    form.month = props.nextMonth;
    form.clearErrors();
    creating.value = true;
};
const create = () => form.post('/admin/hr/payroll/runs', { onSuccess: () => (creating.value = false) });

const open = (run) => router.visit(`/admin/hr/payroll/runs/${run.id}`);

const columns = [
    { key: 'period', label: 'Month' },
    { key: 'status', label: 'Status' },
    { key: 'people', label: 'People', align: 'right', value: (row) => row.totals.people },
    { key: 'gross', label: 'Gross', align: 'right', value: (row) => row.totals.gross },
    { key: 'deductions', label: 'Deductions', align: 'right', value: (row) => row.totals.deductions },
    { key: 'net', label: 'Net pay', align: 'right', value: (row) => row.totals.net },
    { key: 'releases', label: 'Releases', sortable: false },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head title="Payroll" />

    <PageHeader
        title="Payroll"
        description="Monthly payrolls from the plantilla, pay profiles and loans, released on the 15th and the 30th."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll' }]"
    >
        <template v-if="canManage" #actions>
            <Button variant="success" @click="openCreate"><Icon name="plus" size="sm" />New payroll</Button>
        </template>
    </PageHeader>

    <div class="stagger mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Latest payroll" :value="latest ? latest.period : '—'" icon="calendar" tone="primary">
            <template v-if="latest">{{ latest.status_label }} · {{ latest.totals.people }} people</template>
            <template v-else>none yet</template>
        </StatTile>
        <StatTile label="Net pay, latest" :value="latest ? formatPeso(latest.totals.net) : '—'" icon="database" tone="primary">
            <template v-if="latest">gross {{ formatPeso(latest.totals.gross) }}</template>
        </StatTile>
        <StatTile label="Payslip requests" :value="requests.pending ?? 0" icon="clipboard" :tone="requests.pending ? 'warning' : 'primary'">
            waiting for the head of HRD ·
            <Link href="/admin/hr/payroll/requests" class="font-semibold text-link hover:underline">see all</Link>
        </StatTile>
        <StatTile label="Active loans" :value="loans.active" icon="key" tone="primary">
            {{ formatPeso(loans.balance) }} outstanding ·
            <Link href="/admin/hr/payroll/loans" class="font-semibold text-link hover:underline">see all</Link>
        </StatTile>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <Card title="Payrolls" icon="database" class="xl:col-span-2">
            <DataTable :columns="columns" :rows="runs" caption="Monthly payrolls" export-name="imis-payrolls" :searchable="false" empty-text="No payrolls yet.">
                <template #cell-period="{ row }">
                    <Link :href="`/admin/hr/payroll/runs/${row.id}`" class="font-medium text-text hover:text-link">{{ row.period }}</Link>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status === 'finalized' ? 'active' : 'draft'" :label="row.status_label" /></template>
                <template #cell-people="{ row }"><span class="tabular-nums">{{ row.totals.people }}</span></template>
                <template #cell-gross="{ row }"><span class="tabular-nums">{{ formatPeso(row.totals.gross) }}</span></template>
                <template #cell-deductions="{ row }"><span class="tabular-nums">{{ formatPeso(row.totals.deductions) }}</span></template>
                <template #cell-net="{ row }"><span class="font-medium tabular-nums">{{ formatPeso(row.totals.net) }}</span></template>
                <template #cell-releases="{ row }">
                    <span class="text-xs whitespace-nowrap text-text-muted">{{ formatCalendarDate(row.first_release_on) }} · {{ formatCalendarDate(row.second_release_on) }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <Button variant="link" size="sm" @click="open(row)">Open<span class="sr-only"> {{ row.period }}</span></Button>
                </template>
            </DataTable>
        </Card>

        <div class="space-y-6">
            <Card title="Loans nearly paid" icon="key" :description="`Two amortizations or fewer left.`">
                <ul v-if="loans.nearlyPaid.length" class="divide-y divide-border text-sm">
                    <li v-for="loan in loans.nearlyPaid" :key="loan.id" class="flex items-start justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <Link :href="`/admin/hr/payroll/profiles/${loan.employee_id}`" class="font-medium text-text hover:text-link">{{ loan.employee }}</Link>
                            <div class="text-xs text-text-muted">{{ loan.type }}<template v-if="loan.reference"> · {{ loan.reference }}</template></div>
                        </div>
                        <div class="text-right text-xs whitespace-nowrap">
                            <div class="font-medium tabular-nums text-text">{{ formatPeso(loan.balance) }}</div>
                            <div class="text-text-muted">{{ loan.months_left }} month{{ loan.months_left === 1 ? '' : 's' }} left</div>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm text-text-muted">No loan is about to be fully paid.</p>
            </Card>

            <Card title="Payslip approval" icon="checkCircle">
                <p class="text-sm text-text">
                    Requests go to the head of the Human Resource Division:
                    <strong v-if="approvers.length">{{ approvers.join(', ') }}</strong>
                    <span v-else class="text-danger-fg">no one at the moment</span>.
                </p>
                <p class="mt-2 text-xs text-text-muted">
                    That is whoever holds the Chief HRS item at HRD, plus anyone designated head of HRD while the designation runs
                    (Employees → Reassignments & designations).
                </p>
            </Card>
        </div>
    </div>

    <Modal :open="creating" title="New payroll" icon="plus" tone="success" @close="creating = false">
        <form id="payroll-create-form" class="space-y-3" @submit.prevent="create">
            <p class="text-sm text-text">
                IMIS drafts everyone in service with a complete plantilla period: basic salary from the salary schedule, automatic items
                (PERA, GSIS, PhilHealth, Pag-IBIG), their pay profile, and loan amortizations. You can review and adjust it before finalizing.
            </p>
            <div>
                <InputLabel for="payroll-month">Month</InputLabel>
                <input
                    id="payroll-month"
                    v-model="form.month"
                    type="month"
                    required
                    class="block w-full mt-1 form-control px-3 py-2 text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                />
                <InputError :message="form.errors.month" />
            </div>
            <div>
                <InputLabel for="payroll-remarks">Remarks (optional)</InputLabel>
                <Textarea id="payroll-remarks" v-model="form.remarks" class="mt-1" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="creating = false" icon="close">Cancel</Button>
            <Button type="submit" form="payroll-create-form" variant="success" :loading="form.processing">Draft payroll</Button>
        </template>
    </Modal>
</template>
