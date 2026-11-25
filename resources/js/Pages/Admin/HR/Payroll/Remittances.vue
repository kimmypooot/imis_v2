<script setup>
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-8: what a month's payroll owes GSIS, PhilHealth, Pag-IBIG, the Welfare
 * Fund and the BIR. Each list exports to CSV for the payment and the
 * agency's own forms (the lists are schedules, not the agencies' upload
 * files). The server works out the rows and the employer shares.
 */
const props = defineProps({
    run: { type: Object, required: true },
    sections: { type: Object, required: true },
});

const tableColumns = (section) => section.columns.map(({ key, label, money }) => ({ key, label, align: money ? 'right' : undefined }));
const moneyColumns = (section) => section.columns.filter((column) => column.money);
const total = (section) => {
    const key = section.columns.some((column) => column.key === 'total') ? 'total' : 'amount';

    return section.rows.reduce((sum, row) => sum + (row[key] ?? 0), 0);
};
const slug = (key) => `imis-${key.replace('_', '-')}-${props.run.month}`;
</script>

<template>
    <Head :title="`Remittances — ${run.period}`" />

    <PageHeader
        :title="`Remittances — ${run.period}`"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll', href: '/admin/hr/payroll' }, { label: run.period, href: `/admin/hr/payroll/runs/${run.id}` }, { label: 'Remittances' }]"
    />

    <Alert v-if="run.status !== 'finalized'" tone="warning" title="This payroll is not final yet" class="mb-6">
        The lists below follow the payroll as it stands (withholding tax comes from Accounting) and will change if it does. Finalize the payroll before paying anyone.
    </Alert>

    <div class="space-y-6">
        <Card
            v-for="(section, key) in sections"
            :key="key"
            :title="section.title"
            icon="database"
            :description="section.rows.length ? `${section.rows.length} lines · ${formatPeso(total(section))} to remit` : 'Nothing to remit this month.'"
        >
            <p v-if="section.note" class="mb-3 text-sm text-text-muted">{{ section.note }}</p>
            <DataTable v-if="section.rows.length" :columns="tableColumns(section)" :rows="section.rows" :caption="`${section.title}, ${run.period}`" :export-name="slug(key)" numbered>
                <template v-for="column in moneyColumns(section)" :key="column.key" #[`cell-${column.key}`]="{ row }">
                    <span class="tabular-nums">{{ formatPeso(row[column.key]) }}</span>
                </template>
                <template #cell-reference="{ row }">{{ row.reference || '—' }}</template>
            </DataTable>
        </Card>
    </div>
</template>
