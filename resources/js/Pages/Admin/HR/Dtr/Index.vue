<script setup>
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';

defineOptions({ layout: AdminLayout });

/**
 * DTR management for HRD: everyone's month with what needs attention. The
 * counts above the table are filters; "Open DTR" goes to the employee's
 * sheet, where adjustments and raw punches are managed.
 */
const props = defineProps({
    month: { type: String, required: true },
    label: { type: String, required: true },
    filters: { type: Object, required: true },
    offices: { type: Array, required: true },
    filterLabels: { type: Object, required: true },
    counts: { type: Object, required: true },
    employees: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});

const go = (changes = {}) => {
    const query = { month: props.month, office: props.filters.office, search: props.filters.search, filter: props.filters.filter, ...changes };
    router.get('/admin/hr/dtr', Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== '')), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const hm = (minutes) => (minutes ? `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}` : '–');
const dtrUrl = (employee) => `/admin/hr/employees/${employee.id}/dtr?month=${props.month}`;

// The month's figures fold into one cell; hidden columns keep each in the export.
const columns = [
    { key: 'name', label: 'Employee', sortable: false },
    { key: 'setup', label: 'Biometric ID & schedule', sortable: false, export: false },
    { key: 'figures', label: 'Month', sortable: false, export: false },
    { key: 'notes', label: 'Notes', sortable: false, export: false },
    { key: 'actions', label: '', sortable: false, export: false, align: 'right' },
    { key: 'office', label: 'Office', hidden: true, value: (row) => row.office ?? 'Regional Office' },
    { key: 'biometric_id', label: 'Biometric ID', hidden: true, value: (row) => row.biometric_id ?? '' },
    { key: 'schedule', label: 'Schedule', hidden: true, value: (row) => row.schedule ?? '' },
    { key: 'hours', label: 'Hours', hidden: true, value: (row) => hm(row.hours_minutes) },
    { key: 'late', label: 'Late', hidden: true, value: (row) => hm(row.late_minutes) },
    { key: 'undertime', label: 'Undertime', hidden: true, value: (row) => hm(row.undertime_minutes) },
    { key: 'absences', label: 'Absent', hidden: true, value: (row) => String(row.absences) },
    { key: 'leave_days', label: 'Leave', hidden: true, value: (row) => String(row.leave_days) },
];
</script>

<template>
    <Head title="DTR Management" />

    <PageHeader
        title="DTR Management"
        :description="`Everyone's Daily Time Record for ${label}, with what needs attention.`"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'DTR Management' }]"
    >
        <template #actions>
            <Button variant="secondary" href="/admin/hr/dtr/import" icon="download">Biometric logs</Button>
            <Button variant="secondary" href="/admin/hr/dtr/schedules" icon="clock">Work schedules</Button>
            <Button variant="secondary" href="/admin/hr/leave/tardiness" icon="clipboard">Tardiness &amp; undertime</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card>
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-sm text-text">
                    <span class="mb-1 block text-xs text-text-muted">Month</span>
                    <input type="month" class="filter-control" :value="month" aria-label="Month" @change="(event) => event.target.value && go({ month: event.target.value, page: null })" />
                </label>
                <label class="text-sm text-text">
                    <span class="mb-1 block text-xs text-text-muted">Office</span>
                    <select class="filter-control" :value="filters.office ?? ''" aria-label="Office" @change="(event) => go({ office: event.target.value, page: null })">
                        <option value="">All offices</option>
                        <option v-for="office in offices" :key="office.value" :value="office.value">{{ office.label }}</option>
                    </select>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Needs attention">
                <button
                    type="button"
                    class="rounded-full border px-3 py-1 text-sm transition"
                    :class="!filters.filter ? 'border-primary bg-info-bg font-semibold text-link' : 'border-border text-text hover:bg-surface-muted'"
                    @click="go({ filter: null, page: null })"
                >
                    Everyone
                </button>
                <button
                    v-for="(text, key) in filterLabels"
                    :key="key"
                    type="button"
                    class="rounded-full border px-3 py-1 text-sm transition"
                    :class="filters.filter === key ? 'border-primary bg-info-bg font-semibold text-link' : 'border-border text-text hover:bg-surface-muted'"
                    :aria-pressed="filters.filter === key"
                    @click="go({ filter: filters.filter === key ? null : key, page: null })"
                >
                    {{ text }}
                    <span class="ml-1 tabular-nums" :class="counts[key] ? 'font-semibold' : 'text-text-muted'">{{ counts[key] }}</span>
                </button>
            </div>
        </Card>

        <Card :title="`${employees.total} ${employees.total === 1 ? 'employee' : 'employees'}`" icon="clock">
            <DataTable
                :columns="columns"
                :paginator="employees"
                :query="{ month, office: filters.office ?? undefined, filter: filters.filter ?? undefined, search: filters.search ?? undefined }"
                url="/admin/hr/dtr"
                :only="['employees', 'counts', 'filters']"
                :caption="`Employees and their DTR for ${label}`"
                :export-name="`imis-dtr-${month}`"
                :numbered="false"
                empty-icon="clock"
                empty-text="No employees match"
                empty-description="Change the office, the month or the filter above."
            >
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :href="dtrUrl(row)" :subtitle="row.office ?? 'Regional Office'" />
                </template>
                <template #cell-setup="{ row }">
                    <dl class="space-y-1.5 text-xs">
                        <div>
                            <dt class="text-2xs text-text-muted">Biometric ID:</dt>
                            <dd class="tabular-nums text-text"><span v-if="row.biometric_id">{{ row.biometric_id }}</span><Badge v-else tone="danger">None</Badge></dd>
                        </div>
                        <div>
                            <dt class="text-2xs text-text-muted">Schedule:</dt>
                            <dd class="text-text"><span v-if="row.schedule">{{ row.schedule }}</span><Badge v-else tone="warning">None</Badge></dd>
                        </div>
                    </dl>
                </template>
                <template #cell-figures="{ row }">
                    <CellFacts
                        class="tabular-nums"
                        :facts="[
                            { label: 'Hours', value: hm(row.hours_minutes) },
                            { label: 'Late / undertime', value: `${hm(row.late_minutes)} / ${hm(row.undertime_minutes)}` },
                            { label: 'Absent / leave', value: `${row.absences || '–'} / ${row.leave_days || '–'}` },
                        ]"
                    />
                </template>
                <template #cell-notes="{ row }">
                    <div class="flex flex-wrap gap-1.5">
                        <Badge v-if="row.unverified_days" tone="warning">{{ row.unverified_days }} to verify</Badge>
                        <Badge v-if="row.adjusted_days" tone="info">{{ row.adjusted_days }} adjusted</Badge>
                        <Badge v-if="row.charged" tone="success">Charged to leave</Badge>
                    </div>
                </template>
                <template #cell-actions="{ row }">
                    <ActionButton icon="eye" tone="neutral" :label="`Open the DTR of ${row.name}`" :href="dtrUrl(row)" />
                </template>
            </DataTable>
        </Card>
    </div>
</template>
