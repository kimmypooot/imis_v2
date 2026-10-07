<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import { confirm } from '../../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * HR-6a: everyone's leave credits (legacy hrd/leave-cards.php), and the
 * runs HR can trigger by hand (legacy hrd/accrual.php) — both also run on
 * a schedule, and re-running never posts twice.
 */
const props = defineProps({
    types: { type: Array, required: true },
    employees: { type: Array, required: true },
    defaults: { type: Object, required: true },
    canManage: { type: Boolean, required: true },
});

const showSeparated = ref(false);
const shown = computed(() => (showSeparated.value ? props.employees : props.employees.filter((employee) => !employee.separated)));
const separatedCount = computed(() => props.employees.filter((employee) => employee.separated).length);
const noSupervisor = computed(() => props.employees.filter((employee) => !employee.separated && !employee.supervisor).length);

const days = (value) => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const columns = computed(() => [
    { key: 'name', label: 'Employee' },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
    { key: 'status', label: 'Status' },
    ...props.types.map((type) => ({ key: `type_${type.id}`, label: type.code, align: 'right', value: (row) => row.balances[type.id] ?? 0 })),
    { key: 'supervisor', label: 'Supervisor', value: (row) => row.supervisor ?? '' },
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

const selectClass = 'rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Leave Credits" />

    <PageHeader title="Leave Credits" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Credits' }]" />

    <div class="space-y-6">
        <div v-if="canManage" class="grid gap-4 lg:grid-cols-2">
            <Card title="Monthly accrual" icon="calendar" description="VL and SL earned for a month (runs automatically on the 1st for the month before).">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="runAccrual">
                    <div>
                        <label for="accrual-month" class="block text-xs font-medium text-text-muted">Month</label>
                        <select id="accrual-month" v-model.number="accrual.month" :class="selectClass">
                            <option v-for="(name, index) in MONTHS" :key="name" :value="index + 1">{{ name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="accrual-year" class="block text-xs font-medium text-text-muted">Year</label>
                        <input id="accrual-year" v-model.number="accrual.year" type="number" min="2000" max="2100" :class="[selectClass, 'w-28']" />
                    </div>
                    <Button type="submit" :loading="accrual.processing">Post accrual</Button>
                </form>
                <p v-if="accrual.errors.month || accrual.errors.year" class="mt-2 text-sm text-danger">{{ accrual.errors.month || accrual.errors.year }}</p>
            </Card>

            <Card title="Start of the year" icon="calendar" description="Lapses last year's unused non-cumulative credits and posts this year's grants (runs automatically in January).">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="runYearStart">
                    <div>
                        <label for="year-start" class="block text-xs font-medium text-text-muted">Year</label>
                        <input id="year-start" v-model.number="yearStart.year" type="number" min="2000" :max="defaults.start_year" :class="[selectClass, 'w-28']" />
                    </div>
                    <Button type="submit" :loading="yearStart.processing">Start year</Button>
                </form>
                <p v-if="yearStart.errors.year" class="mt-2 text-sm text-danger">{{ yearStart.errors.year }}</p>
            </Card>
        </div>

        <p v-if="noSupervisor" class="rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text">
            {{ noSupervisor }} {{ noSupervisor === 1 ? 'person has' : 'people have' }} no immediate supervisor. Supervisors follow position and office (the Chief Human Resource Specialist for Regional Support Unit staff, the Director II for Field Office staff, the Director IV for ORD staff, the Director III, the Chief Human Resource Specialists and the Attorney VI); set the rest on their leave page before leave filing opens.
        </p>

        <Card title="Balances today" icon="users" description="Accrual and grants need an employment status that the leave type allows (set on the employee record).">
            <template v-if="separatedCount" #actions>
                <label class="flex items-center gap-2 text-sm text-text">
                    <input v-model="showSeparated" type="checkbox" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                    Show separated ({{ separatedCount }})
                </label>
            </template>

            <DataTable :columns="columns" :rows="shown" caption="Leave balances" export-name="imis-leave-balances">
                <template #cell-name="{ row }">
                    <Link :href="`/admin/hr/employees/${row.id}/leave`" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                    <StatusBadge v-if="row.separated" status="inactive" label="Separated" class="ml-2" />
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
                    <span v-else class="text-warning">Not set</span>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
