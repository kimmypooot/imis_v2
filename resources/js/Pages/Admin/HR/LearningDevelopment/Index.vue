<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import LdBulkAddModal from '../../../../Components/HR/LdBulkAddModal.vue';

defineOptions({ layout: AdminLayout });

/**
 * HR-3b: HRD's L&D overview (ldms's ld_manage.php) — every employee's
 * approved trainings and hours for a year, each person's log, and bulk
 * add. Totals count approved records only.
 */
const props = defineProps({
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    employees: { type: Array, required: true },
    pendingTotal: { type: Number, default: null },
    canBulkAdd: { type: Boolean, required: true },
    typeOptions: { type: Array, required: true },
    modalityOptions: { type: Array, required: true },
});

const changeYear = (value) => router.get('/admin/hr/learning-development', { year: value }, { preserveScroll: true });

const showSeparated = ref(false);
const shown = computed(() => (showSeparated.value ? props.employees : props.employees.filter((employee) => !employee.separated)));
const separatedCount = computed(() => props.employees.filter((employee) => employee.separated).length);

const hours = (value) => value.toLocaleString(undefined, { maximumFractionDigits: 2 });

const totals = computed(() => {
    const active = props.employees.filter((employee) => !employee.separated);
    return [
        { label: 'Employees', value: active.length, icon: 'users' },
        { label: 'With training', value: active.filter((employee) => employee.trainings > 0).length, icon: 'checkCircle' },
        { label: 'Trainings', value: props.employees.reduce((sum, employee) => sum + employee.trainings, 0), icon: 'clipboard' },
        { label: 'Total hours', value: hours(props.employees.reduce((sum, employee) => sum + employee.hours, 0)), icon: 'clock' },
    ];
});

// Bulk add: people this officer may add to (never themself), still in service.
const bulkCandidates = computed(() => props.employees.filter((employee) => employee.can_manage && !employee.separated));
const bulkOpen = ref(false);

const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'unit', label: 'Office', value: (row) => row.unit ?? '' },
    { key: 'trainings', label: 'Trainings', align: 'right' },
    { key: 'hours', label: 'Hours', align: 'right' },
    { key: 'pending', label: 'Pending', align: 'right' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '5rem' },
];
</script>

<template>
    <Head title="Learning & Development" />

    <PageHeader
        title="Learning & Development"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Learning & Development' }]"
    >
        <template #actions>
            <label class="sr-only" for="ld-admin-year">Year</label>
            <select
                id="ld-admin-year"
                :value="year"
                class="form-control px-3 py-2"
                @change="changeYear($event.target.value)"
            >
                <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
            </select>
            <Button v-if="canBulkAdd" variant="success" :disabled="bulkCandidates.length === 0" @click="bulkOpen = true">
                <Icon name="plus" size="sm" />Add for several employees
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div v-for="card in totals" :key="card.label" class="rounded-lg bg-surface p-4 shadow-sm ring-1 ring-border/60">
                <dt class="flex items-center gap-1.5 text-xs font-medium text-text-muted"><Icon :name="card.icon" size="sm" />{{ card.label }}</dt>
                <dd class="mt-1 text-2xl font-bold text-text tabular-nums">{{ card.value }}</dd>
            </div>
        </dl>

        <p v-if="pendingTotal" class="rounded-md border border-info-border bg-info-bg px-4 py-3 text-sm text-text">
            {{ pendingTotal }} {{ pendingTotal === 1 ? 'training is' : 'trainings are' }} waiting for review.
            <Link href="/admin/hr/learning-development/reviews" class="font-medium text-link hover:underline">Open the review queue</Link>
        </p>

        <Card :title="`Employees — ${year}`" icon="users" description="Approved trainings only; pending ones are counted separately.">
            <template v-if="separatedCount" #actions>
                <label class="flex items-center gap-2 text-sm text-text">
                    <input v-model="showSeparated" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                    Show separated ({{ separatedCount }})
                </label>
            </template>

            <DataTable :columns="columns" :rows="shown" :caption="`L&D per employee, ${year}`" :export-name="`imis-ld-employees-${year}`">
                <template #cell-name="{ row }">
                    <Link :href="`/admin/hr/employees/${row.id}/learning-development?year=${year}`" class="font-medium text-link hover:underline">{{ row.name }}</Link>
                    <StatusBadge v-if="row.separated" status="inactive" label="Separated" class="ml-2" />
                    <div v-if="row.position" class="text-xs text-text-muted">{{ row.position }}</div>
                </template>
                <template #cell-unit="{ row }">{{ row.unit || '—' }}</template>
                <template #cell-trainings="{ row }"><span class="tabular-nums" :class="row.trainings ? '' : 'text-text-muted'">{{ row.trainings }}</span></template>
                <template #cell-hours="{ row }"><span class="tabular-nums" :class="row.hours ? '' : 'text-text-muted'">{{ hours(row.hours) }}</span></template>
                <template #cell-pending="{ row }"><span class="tabular-nums" :class="row.pending ? 'font-medium text-info-fg' : 'text-text-muted'">{{ row.pending }}</span></template>
                <template #cell-actions="{ row }">
                    <ActionButton icon="eye" tone="neutral" :label="`L&D log of ${row.name}`" :href="`/admin/hr/employees/${row.id}/learning-development?year=${year}`" />
                </template>
            </DataTable>
        </Card>
    </div>

    <LdBulkAddModal
        v-if="canBulkAdd"
        :open="bulkOpen"
        :employees="bulkCandidates"
        :type-options="typeOptions"
        :modality-options="modalityOptions"
        @close="bulkOpen = false"
    />
</template>
