<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Badge from '../../Components/UI/Badge.vue';
import Alert from '../../Components/Feedback/Alert.vue';
import Icon from '../../Components/UI/Icon.vue';
import OrbitCircles from '../../Components/UI/OrbitCircles.vue';
import { formatCalendarDate } from '../../Utils/datetime';
import { formatPeso } from '../../Utils/money';

defineOptions({ layout: PortalLayout });

/**
 * HR-8 self-service: your latest finalized earnings and deductions, your
 * recurring deductions with their schedule, and your payslip history.
 * Only your own record; a month appears here once HR finalizes it. This is
 * an IMIS record, not the payroll office's official payslip.
 */
const props = defineProps({
    latest: { type: Object, default: null },
    loans: { type: Array, required: true },
    items: { type: Array, required: true },
    history: { type: Array, required: true },
});

const deductionGroups = computed(() => {
    if (!props.latest) return [];
    const { deductions, subtotals } = props.latest;

    return [
        { key: 'mandatory', title: 'Mandatory contributions', lines: deductions.mandatory, subtotal: subtotals.mandatory },
        { key: 'government_loans', title: 'Government loans', lines: deductions.government_loans, subtotal: subtotals.government_loans },
        { key: 'recurring', title: 'Recurring deductions', lines: deductions.recurring, subtotal: subtotals.recurring },
        { key: 'other', title: 'Other authorized deductions', lines: deductions.other, subtotal: subtotals.other },
    ].filter((group) => group.lines.length);
});

const loanTones = { active: 'active', paid: 'info', stopped: 'logged_out', cancelled: 'inactive' };
const scheduleTones = { scheduled: 'draft', reflected: 'info', confirmed: 'pending', paid: 'active', overdue: 'inactive' };
const activeLoans = computed(() => props.loans.filter((loan) => loan.status === 'active' || loan.status === 'stopped'));
const pastLoans = computed(() => props.loans.filter((loan) => loan.status === 'paid' || loan.status === 'cancelled'));

// Share of the loan already deducted; null when the schedule's length is unknown.
const loanProgress = (loan) => {
    if (loan.status === 'paid') return 100;
    if (loan.months_left === null || loan.months_left === undefined) return null;
    const total = loan.installments_paid + loan.months_left;

    return total > 0 ? Math.min(100, Math.round((loan.installments_paid / total) * 100)) : null;
};

// The last six finalized months, oldest first, scaled to the largest net pay.
const trend = computed(() => {
    const recent = props.history.slice(0, 6).reverse();
    const top = Math.max(0, ...recent.map((row) => row.net_pay));

    return recent.map((row) => ({ period: row.period, net_pay: row.net_pay, share: top ? Math.max(4, Math.round((row.net_pay / top) * 100)) : 0 }));
});

const historyColumns = [
    { key: 'period', label: 'Compensation period' },
    { key: 'gross_pay', label: 'Total earnings', align: 'right' },
    { key: 'total_deductions', label: 'Total deductions', align: 'right' },
    { key: 'net_pay', label: 'Net pay', align: 'right' },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head title="My Earnings & Deductions" />

    <PageHeader
        title="My Earnings & Deductions"
        description="What your latest finalized payroll gave and took, your recurring deductions, and your payslip history."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Earnings & Deductions' }]"
    />

    <Alert tone="info" class="mb-6">
        This is an IMIS record of your compensation, shown once HR finalizes a month. It is not the official payslip issued by the payroll office.
        A scheduled deduction is not proof it was collected or remitted.
    </Alert>

    <Card v-if="!latest" title="Nothing to show yet" icon="database">
        <p class="text-sm text-text-muted">No payroll has been finalized for you yet. Your figures appear here once HR finalizes a month.</p>
    </Card>

    <template v-else>
        <!-- The latest finalized payroll at a glance. -->
        <section class="relative mb-6 overflow-hidden rounded-lg bg-linear-to-br from-csc-blue via-csc-blue to-csc-blue-dark p-6 text-white md:p-8" aria-label="Latest payroll summary">
            <OrbitCircles />
            <div class="relative z-10">
                <p class="flex items-center gap-2 text-xs font-semibold tracking-wide text-white/75 uppercase">
                    <Icon name="database" size="sm" />Latest finalized payroll · {{ latest.period }}
                </p>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white/10 p-4 ring-1 ring-white/15">
                        <p class="text-xs text-white/75">Total earnings</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">{{ formatPeso(latest.total_earnings) }}</p>
                    </div>
                    <div class="rounded-lg bg-white/10 p-4 ring-1 ring-white/15">
                        <p class="text-xs text-white/75">Total deductions</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">{{ formatPeso(latest.total_deductions) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-text">
                        <p class="text-xs text-text-muted">Net pay</p>
                        <p class="mt-1 text-2xl font-bold text-primary tabular-nums">{{ formatPeso(latest.net_pay) }}</p>
                    </div>
                </div>
            </div>
        </section>

        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            <Card :title="`Earnings — ${latest.period}`" icon="chartBar">
                <table class="w-full text-sm">
                    <caption class="sr-only">Earnings for {{ latest.period }}</caption>
                    <tbody>
                        <tr class="border-b border-border"><th scope="row" class="py-2 text-left font-normal text-text">Basic salary</th><td class="py-2 text-right tabular-nums">{{ formatPeso(latest.basic_salary) }}</td></tr>
                        <tr v-for="line in latest.earnings.pera" :key="`pera-${line.label}`" class="border-b border-border"><th scope="row" class="py-2 text-left font-normal text-text">{{ line.label }}</th><td class="py-2 text-right tabular-nums">{{ formatPeso(line.amount) }}</td></tr>
                        <tr v-for="(line, index) in latest.earnings.other" :key="`other-${index}`" class="border-b border-border">
                            <th scope="row" class="py-2 text-left font-normal text-text">{{ line.label }}</th>
                            <td class="py-2 text-right tabular-nums">{{ formatPeso(line.amount) }}</td>
                        </tr>
                    </tbody>
                    <tfoot><tr><th scope="row" class="pt-2 text-left font-semibold text-text">Total earnings</th><td class="pt-2 text-right font-semibold tabular-nums">{{ formatPeso(latest.total_earnings) }}</td></tr></tfoot>
                </table>
            </Card>

            <Card :title="`Deductions — ${latest.period}`" icon="clipboard">
                <p v-if="!deductionGroups.length" class="text-sm text-text-muted">No deductions.</p>
                <table v-else class="w-full text-sm">
                    <caption class="sr-only">Deductions for {{ latest.period }}</caption>
                    <tbody>
                        <template v-for="group in deductionGroups" :key="group.key">
                            <tr><th colspan="2" scope="rowgroup" class="pt-3 pb-1 text-left text-xs font-semibold tracking-wide text-text-muted uppercase first:pt-0">{{ group.title }}</th></tr>
                            <tr v-for="(line, index) in group.lines" :key="`${group.key}-${index}`" class="border-b border-border">
                                <td class="py-1.5 text-text">{{ line.label }}<Badge v-if="line.adjusted" tone="info" class="ml-1">Adjusted by HR</Badge></td>
                                <td class="py-1.5 text-right tabular-nums">{{ formatPeso(line.amount) }}</td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot><tr><th scope="row" class="pt-2 text-left font-semibold text-text">Total deductions</th><td class="pt-2 text-right font-semibold tabular-nums">{{ formatPeso(latest.total_deductions) }}</td></tr></tfoot>
                </table>
                <p class="mt-4 flex justify-between border-t-2 border-border pt-2 text-base font-semibold text-text"><span>Net pay</span><span class="tabular-nums">{{ formatPeso(latest.net_pay) }}</span></p>
            </Card>
        </div>
    </template>

    <Card title="Recurring deductions" icon="key" class="mb-6" description="Loans and other deductions taken every month until they are settled.">
        <p v-if="!loans.length" class="text-sm text-text-muted">You have no recurring deductions.</p>
        <ul v-else class="space-y-5">
            <li v-for="loan in [...activeLoans, ...pastLoans]" :key="loan.id" class="rounded-md border border-border p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium text-text">{{ loan.type }}<span v-if="loan.reference" class="font-normal text-text-muted"> · {{ loan.reference }}</span></p>
                        <p class="text-xs text-text-muted">From {{ loan.start_month }}<template v-if="loan.expected_end"> · expected last deduction {{ loan.expected_end }}</template></p>
                    </div>
                    <StatusBadge :status="loanTones[loan.status]" :label="loan.status_label" />
                </div>
                <!-- How much of the loan has been deducted. -->
                <div v-if="loanProgress(loan) !== null" class="mt-3">
                    <div class="flex justify-between text-xs text-text-muted">
                        <span>{{ loanProgress(loan) }}% deducted</span>
                        <span>{{ loan.installments_paid }} of {{ loan.installments_paid + (loan.status === 'active' || loan.status === 'stopped' ? loan.months_left : 0) }} installments</span>
                    </div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-muted" role="progressbar" :aria-valuenow="loanProgress(loan)" aria-valuemin="0" aria-valuemax="100" :aria-label="`${loan.type} progress`">
                        <div class="h-full rounded-full bg-success transition-[width] duration-500" :style="{ width: `${loanProgress(loan)}%` }" />
                    </div>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-text-muted">Monthly deduction</dt><dd class="font-medium tabular-nums text-text">{{ formatPeso(loan.monthly_amortization) }}</dd></div>
                    <div><dt class="text-text-muted">Deducted so far</dt><dd class="tabular-nums text-text">{{ loan.installments_paid }} time{{ loan.installments_paid === 1 ? '' : 's' }}</dd></div>
                    <div><dt class="text-text-muted">Remaining installments</dt><dd class="tabular-nums text-text">{{ loan.status === 'paid' || loan.status === 'cancelled' ? 0 : loan.months_left }}</dd></div>
                    <div><dt class="text-text-muted">Outstanding balance</dt><dd class="font-medium tabular-nums text-text">{{ formatPeso(loan.balance) }}</dd></div>
                </dl>
                <details v-if="loan.schedule.rows.length" class="mt-3 text-sm">
                    <summary class="cursor-pointer text-primary hover:underline">Schedule</summary>
                    <table class="mt-2 w-full">
                        <caption class="sr-only">Installments of {{ loan.type }}</caption>
                        <tbody>
                            <tr v-for="row in loan.schedule.rows" :key="row.month" class="border-t border-border">
                                <td class="py-1 text-text">{{ row.month }}</td>
                                <td class="py-1 text-right tabular-nums">{{ formatPeso(row.amount) }}</td>
                                <td class="py-1 pl-4"><StatusBadge :status="scheduleTones[row.status]" :label="row.status_label" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="loan.schedule.more" class="mt-1 text-xs text-text-muted">…and {{ loan.schedule.more }} more after these.</p>
                </details>
            </li>
        </ul>
    </Card>

    <Card v-if="items.length" title="Allowances and deductions on your profile" icon="clipboard" class="mb-6" description="Set by HR for you, on top of the items everyone gets.">
        <ul class="divide-y divide-border text-sm">
            <li v-for="(item, index) in items" :key="index" class="flex flex-wrap justify-between gap-2 py-2">
                <span class="text-text">{{ item.component }} <span class="text-xs text-text-muted">· {{ item.kind === 'earning' ? 'Earning' : 'Deduction' }}</span></span>
                <span class="tabular-nums text-text-muted">{{ item.amount === null ? item.rule : formatPeso(item.amount) }}</span>
            </li>
        </ul>
    </Card>

    <!-- Net pay over the last few finalized months, oldest to newest. -->
    <Card v-if="trend.length > 1" title="Net pay, recent months" icon="chartBar" class="mb-6">
        <ul class="space-y-2.5" aria-label="Net pay by month">
            <li v-for="point in trend" :key="point.period" class="flex items-center gap-3 text-sm">
                <span class="w-32 shrink-0 text-text-muted">{{ point.period }}</span>
                <span class="h-3 flex-1 overflow-hidden rounded-full bg-surface-muted">
                    <span class="block h-full rounded-full bg-chart-1" :style="{ width: `${point.share}%` }" />
                </span>
                <span class="w-28 shrink-0 text-right font-medium tabular-nums text-text">{{ formatPeso(point.net_pay) }}</span>
            </li>
        </ul>
    </Card>

    <Card title="Payslip history" icon="database">
        <DataTable :columns="historyColumns" :rows="history" caption="Your payslip history" export-name="imis-my-payslips" :searchable="false" empty-text="No finalized payslips yet.">
            <template #cell-gross_pay="{ row }"><span class="tabular-nums">{{ formatPeso(row.gross_pay) }}</span></template>
            <template #cell-total_deductions="{ row }"><span class="tabular-nums">{{ formatPeso(row.total_deductions) }}</span></template>
            <template #cell-net_pay="{ row }"><span class="font-semibold tabular-nums">{{ formatPeso(row.net_pay) }}</span></template>
            <template #cell-actions="{ row }">
                <div class="text-right text-sm whitespace-nowrap">
                    <a v-if="row.payslip_ready" :href="`/payslips/${row.id}`" target="_blank" rel="noopener" class="text-primary hover:underline">View / print<span class="sr-only"> {{ row.period }} payslip</span></a>
                    <Link v-else-if="row.request_status === 'pending'" href="/my/payslips" class="text-text-muted hover:underline">Request pending</Link>
                    <Link v-else href="/my/payslips" class="text-primary hover:underline">Request payslip<span class="sr-only"> for {{ row.period }}</span></Link>
                </div>
            </template>
        </DataTable>
    </Card>
</template>
