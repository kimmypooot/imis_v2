<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Badge from '../../../../Components/UI/Badge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import { confirm } from '../../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * HR-8: allowance, deduction and loan types. Automatic ones go on
 * everyone's pay; the rest only on pay profiles that list them. Rates are
 * HR's to keep current with the issuances.
 */
const props = defineProps({
    components: { type: Array, required: true },
    kinds: { type: Array, required: true },
    methods: { type: Array, required: true },
    categories: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const open = ref(false);
const editing = ref(null);
const edit = (component = null) => {
    editing.value = component;
    open.value = true;
};
const remove = async (component) => {
    if (await confirm({ title: `Delete ${component.name}?`, tone: 'danger', confirmText: 'Delete' })) {
        router.delete(`/admin/hr/payroll/components/${component.id}`, { preserveScroll: true });
    }
};

const fields = [
    { key: 'code', label: 'Code', required: true, help: 'Short and unique, e.g. PERA, GSIS-PS.' },
    { key: 'name', label: 'Name', required: true },
    { key: 'kind', label: 'Kind', type: 'select', required: true, options: props.kinds },
    { key: 'category', label: 'Group on the employee’s page', type: 'select', options: props.categories, help: 'Where it appears on My Earnings & Deductions. Blank picks one from the kind.' },
    { key: 'method', label: 'Computed as', type: 'select', required: true, options: props.methods, help: 'Loans are always entered per loan.' },
    { key: 'amount', label: 'Amount (fixed)', type: 'number', help: 'For "Fixed amount".' },
    { key: 'rate', label: 'Rate, % of basic', type: 'number', help: 'For "% of basic salary".' },
    { key: 'base_min', label: 'Salary floor (optional)', type: 'number', help: 'The rate applies to at least this salary.' },
    { key: 'base_max', label: 'Salary ceiling (optional)', type: 'number', help: 'The rate applies to at most this salary.' },
    { key: 'sort_order', label: 'Order on the payslip', type: 'number' },
    { key: 'automatic', label: 'Automatic: on everyone’s pay (a pay profile can override or exempt)', type: 'checkbox' },
    { key: 'is_active', label: 'Active', type: 'checkbox' },
    { key: 'remarks', label: 'Remarks (e.g. the issuance)', type: 'textarea' },
];

const defaults = { code: '', name: '', kind: 'deduction', category: '', method: 'manual', amount: '', rate: '', base_min: '', base_max: '', sort_order: 0, automatic: false, is_active: true, remarks: '' };

const groups = computed(() =>
    props.kinds.map((kind) => ({ ...kind, rows: props.components.filter((component) => component.kind === kind.value) })),
);

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'rule', label: 'Computed as' },
    { key: 'category_label', label: 'Group', value: (row) => row.category_label ?? '' },
    { key: 'automatic', label: 'Applies to', value: (row) => (row.automatic ? 'Everyone' : 'Pay profiles') },
    { key: 'is_active', label: 'Status', value: (row) => (row.is_active ? 'Active' : 'Inactive') },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head title="Pay Components" />

    <PageHeader
        title="Pay Components"
        description="Allowances, deductions and loan types, and how each month's amount is worked out. Check the rates against current issuances."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll', href: '/admin/hr/payroll' }, { label: 'Pay Components' }]"
    >
        <template v-if="canManage" #actions>
            <Button variant="success" @click="edit()"><Icon name="plus" size="sm" />Add component</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card v-for="group in groups" :key="group.value" :title="group.label" icon="database">
            <DataTable :columns="columns" :rows="group.rows" :caption="group.label" :export-name="`imis-pay-components-${group.value}`" :searchable="false" :empty-text="`No ${group.label.toLowerCase()} types yet.`">
                <template #cell-code="{ row }"><span class="font-mono text-xs">{{ row.code }}</span></template>
                <template #cell-name="{ row }">
                    <span class="font-medium text-text">{{ row.name }}</span>
                    <div v-if="row.remarks" class="text-xs text-text-muted">{{ row.remarks }}</div>
                </template>
                <template #cell-automatic="{ row }"><Badge :tone="row.automatic ? 'info' : 'neutral'">{{ row.automatic ? 'Everyone' : 'Pay profiles' }}</Badge></template>
                <template #cell-is_active="{ row }"><StatusBadge :status="row.is_active ? 'active' : 'inactive'" /></template>
                <template #cell-actions="{ row }">
                    <div v-if="canManage" class="flex justify-end gap-3 text-sm">
                        <button type="button" class="text-primary hover:underline" @click="edit(row)">Edit<span class="sr-only"> {{ row.name }}</span></button>
                        <button v-if="!row.in_use" type="button" class="text-danger hover:underline" @click="remove(row)">Delete<span class="sr-only"> {{ row.name }}</span></button>
                    </div>
                </template>
            </DataTable>
        </Card>
    </div>

    <RecordModal
        v-if="canManage"
        :open="open"
        noun="Pay Component"
        :record="editing"
        :fields="fields"
        :defaults="defaults"
        :nullable="['category', 'amount', 'rate', 'base_min', 'base_max', 'remarks']"
        store-url="/admin/hr/payroll/components"
        :update-url="(record) => `/admin/hr/payroll/components/${record.id}`"
        @close="open = false"
    />
</template>
