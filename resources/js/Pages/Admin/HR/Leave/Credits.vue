<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';

defineOptions({ layout: AdminLayout });

/**
 * HR-6a: Leave Cards — everyone's balances, each opening their leave card
 * (legacy hrd/leave-cards.php), and, folded away below, the runs HR can
 * trigger by hand (legacy hrd/accrual.php) — both also run on a schedule,
 * and re-running never posts twice.
 */
const props = defineProps({
    types: { type: Array, required: true },
    employees: { type: Array, required: true },
    defaults: { type: Object, required: true },
    canManage: { type: Boolean, required: true },
});

const showSeparated = ref(false);
const office = ref('');
const offices = computed(() => [...new Set(props.employees.map((employee) => employee.office).filter(Boolean))].sort((a, b) => a.localeCompare(b)));
const shown = computed(() =>
    props.employees.filter((employee) => (showSeparated.value || !employee.separated) && (!office.value || employee.office === office.value)),
);
const showRuns = ref(false);
const separatedCount = computed(() => props.employees.filter((employee) => employee.separated).length);
const noSupervisor = computed(() => props.employees.filter((employee) => !employee.separated && !employee.supervisor).length);
const pdsSoloParents = computed(() => props.employees.filter((employee) => !employee.separated && employee.pds_solo_parent).length);

const days = (value) => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const columns = computed(() => [
    { key: 'name', label: 'Employee' },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
    { key: 'status', label: 'Status' },
    ...props.types.map((type) => ({ key: `type_${type.id}`, label: type.code, align: 'right', value: (row) => row.balances[type.id] ?? 0 })),
    { key: 'supervisor', label: 'Supervisor', value: (row) => row.supervisor ?? '' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '4rem' },
]);

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const accrual = useForm({ year: props.defaults.year, month: props.defaults.month });
const runAccrual = async () => {
    if (await confirm({ title: `Post accrual for ${MONTHS[accrual.month - 1]} ${accrual.year}?`, text: 'Everyone in service and eligible is credited. Anyone already credited for that month is skipped.', confirmText: 'Post accrual' })) {
        accrual.post('/admin/hr/leave/accrual', { preserveScroll: true });
    }
};

const yearStart = useForm({ year: props.defaults.start_year });
const runYearStart = async () => {
    if (await confirm({
        title: `Start leave year ${yearStart.year}?`,
        text: `Unused non-cumulative credits of ${yearStart.year - 1} lapse, and the ${yearStart.year} grants (e.g. Special Privilege Leave) are posted. Anything already done is skipped.`,
        confirmText: 'Start year',
    })) {
        yearStart.post('/admin/hr/leave/year-start', { preserveScroll: true });
    }
};

</script>

<template>
    <Head title="Leave Cards" />

    <PageHeader title="Leave Cards" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Cards' }]">
        <template v-if="canManage" #actions>
            <Button variant="secondary" href="/admin/hr/leave/sync" icon="refresh">Sync with the leave card file</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <p v-if="noSupervisor" class="rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text">
            {{ noSupervisor }} {{ noSupervisor === 1 ? 'person has' : 'people have' }} no immediate supervisor. Supervisors follow position and office (the Chief Human Resource Specialist for Regional Support Unit staff, the Director II for Field Office staff, the Director IV for ORD staff, the Director III, the Chief Human Resource Specialists and the Attorney VI); set the rest on their leave page before leave filing opens.
        </p>
        <p v-if="pdsSoloParents" class="rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text">
            {{ pdsSoloParents }} {{ pdsSoloParents === 1 ? 'person says' : 'people say' }} on the PDS that they are a solo parent but {{ pdsSoloParents === 1 ? 'is' : 'are' }} not marked one in leave settings, so Solo Parent Leave is hidden from them.
            Check their Solo Parent ID, then mark them on their leave card (Leave settings). They're noted under their names below.
        </p>

        <Card title="Employees" icon="users" description="Balances today. Open a leave card to see it month by month, post adjustments or correct entries.">
            <template #actions>
                <label class="sr-only" for="leave-office">Office</label>
                <select id="leave-office" v-model="office" class="filter-control">
                    <option value="">All offices</option>
                    <option v-for="name in offices" :key="name" :value="name">{{ name }}</option>
                </select>
                <label v-if="separatedCount" class="flex items-center gap-2 text-sm text-text">
                    <input v-model="showSeparated" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                    Show separated ({{ separatedCount }})
                </label>
            </template>

            <DataTable :columns="columns" :rows="shown" caption="Leave balances" export-name="imis-leave-balances">
                <template #cell-name="{ row }">
                    <Link :href="`/admin/hr/employees/${row.id}/leave`" class="font-medium text-link hover:underline">{{ row.name }}</Link>
                    <StatusBadge v-if="row.separated" status="inactive" label="Separated" class="ml-2" />
                    <span v-if="row.pds_solo_parent" class="block text-xs text-warning-fg">Solo parent on the PDS — not marked in leave settings</span>
                </template>
                <template #cell-office="{ row }">{{ row.office || '—' }}</template>
                <template v-for="type in types" :key="type.id" #[`cell-type_${type.id}`]="{ row }">
                    <span class="tabular-nums">{{ days(row.balances[type.id]) }}</span>
                </template>
                <template #cell-supervisor="{ row }">
                    <template v-if="row.supervisor">
                        <span>{{ row.supervisor }}</span>
                        <span v-if="row.supervisor_position" class="block text-xs text-text-muted">
                            {{ row.supervisor_position }}<template v-if="row.supervisor_source === 'set'"> · set by HR</template>
                        </span>
                    </template>
                    <span v-else class="text-warning-fg">Not set</span>
                </template>
                <template #cell-actions="{ row }">
                    <ActionButton icon="clipboard" tone="neutral" :label="`Open the leave card of ${row.name}`" :href="`/admin/hr/employees/${row.id}/leave`" />
                </template>
            </DataTable>
        </Card>

        <Card v-if="canManage" title="Accrual and year-start runs" icon="calendar" description="Both run automatically; use these only to post a month or start a year by hand. Re-running never posts twice.">
            <template #actions>
                <Button variant="secondary" :aria-expanded="showRuns" aria-controls="leave-runs" @click="showRuns = !showRuns">{{ showRuns ? 'Hide' : 'Show' }} runs</Button>
            </template>
            <div v-show="showRuns" id="leave-runs" class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-md border border-border p-4">
                <h3 class="text-sm font-semibold text-text">Monthly accrual</h3>
                <p class="mb-3 text-xs text-text-muted">VL and SL earned for a month (runs automatically on the 1st for the month before).</p>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="runAccrual">
                    <div>
                        <label for="accrual-month" class="block text-xs font-medium text-text-muted">Month</label>
                        <select id="accrual-month" v-model.number="accrual.month" class="filter-control">
                            <option v-for="(name, index) in MONTHS" :key="name" :value="index + 1">{{ name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="accrual-year" class="block text-xs font-medium text-text-muted">Year</label>
                        <input id="accrual-year" v-model.number="accrual.year" type="number" min="2000" max="2100" class="filter-control w-28" />
                    </div>
                    <Button type="submit" :loading="accrual.processing">Post accrual</Button>
                </form>
                <p v-if="accrual.errors.month || accrual.errors.year" class="mt-2 text-sm text-danger-fg">{{ accrual.errors.month || accrual.errors.year }}</p>
            </section>

            <section class="rounded-md border border-border p-4">
                <h3 class="text-sm font-semibold text-text">Start of the year</h3>
                <p class="mb-3 text-xs text-text-muted">Lapses last year's unused non-cumulative credits and posts this year's grants (runs automatically in January).</p>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="runYearStart">
                    <div>
                        <label for="year-start" class="block text-xs font-medium text-text-muted">Year</label>
                        <input id="year-start" v-model.number="yearStart.year" type="number" min="2000" :max="defaults.start_year" class="filter-control w-28" />
                    </div>
                    <Button type="submit" :loading="yearStart.processing">Start year</Button>
                </form>
                <p v-if="yearStart.errors.year" class="mt-2 text-sm text-danger-fg">{{ yearStart.errors.year }}</p>
            </section>
            </div>
            <p class="text-xs text-text-muted" :class="{ 'mt-4': showRuns }">Accrual and grants need an employment status that the leave type allows (set on the employee record).</p>
        </Card>
    </div>
</template>
