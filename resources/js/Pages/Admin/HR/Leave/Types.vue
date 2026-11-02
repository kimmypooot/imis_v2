<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import Select from '../../../../Components/Forms/Select.vue';
import TextInput from '../../../../Components/Forms/TextInput.vue';
import InputError from '../../../../Components/Forms/InputError.vue';

defineOptions({ layout: AdminLayout });

/**
 * HR-6a: leave types (CS Form No. 6) with their policy as data. The seeded
 * entitlements come from the statutes in each type's policy reference —
 * HR confirms them against current CSC issuances and edits them here.
 */
const props = defineProps({
    types: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    statusOptions: { type: Array, required: true },
});

const blank = {
    code: '', name: '', description: '', workflow: 'standard', requires_balance: false, charged_to_id: '', monthly_accrual: '', annual_grant: '',
    lapses_yearly: false, max_days_per_year: '', max_days_per_filing: '', max_consecutive_days: '', sex: '', eligible_statuses: [], min_service_months: 0,
    requires_solo_parent_id: false, advance_filing_days: 0, allows_emergency_filing: false, allows_half_day: true, counts_calendar_days: false, medical_certificate_after_days: '',
    supporting_documents: '', monetizable: false, policy_reference: '', sort_order: 0, is_active: true,
};

const editing = ref(null);
const open = ref(false);
const form = useForm({ ...blank });

const edit = (type = null) => {
    editing.value = type;
    form.defaults(Object.fromEntries(Object.keys(blank).map((key) => [key, type ? (type[key] ?? blank[key]) : blank[key]])));
    form.reset();
    form.clearErrors();
    open.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    form.transform((data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value])));
    if (editing.value) form.put(`/admin/hr/leave/types/${editing.value.id}`, options);
    else form.post('/admin/hr/leave/types', options);
};

const chargeOptions = computed(() => props.types.filter((type) => type.has_credits && type.id !== editing.value?.id).map((type) => ({ value: type.id, label: `${type.code} · ${type.name}` })));
const statusLabel = (value) => props.statusOptions.find((option) => option.value === value)?.label ?? value;

const rules = (row) =>
    [
        row.monthly_accrual ? `earns ${Number(row.monthly_accrual)}/month` : null,
        row.annual_grant ? `${Number(row.annual_grant)} days a year` : null,
        row.charged_to ? `uses ${row.charged_to} credits` : null,
        row.max_days_per_year ? `max ${Number(row.max_days_per_year)}/year` : null,
        row.max_days_per_filing ? `max ${Number(row.max_days_per_filing)} per filing` : null,
        row.max_consecutive_days ? `max ${row.max_consecutive_days} in a row` : null,
        row.counts_calendar_days ? 'calendar days' : null,
        row.sex_label ? `${row.sex_label} only` : null,
        row.requires_solo_parent_id ? 'Solo Parent ID' : null,
        row.advance_filing_days ? `file ${row.advance_filing_days} days ahead` + (row.allows_emergency_filing ? ' (or on return, in an emergency)' : '') : null,
        row.medical_certificate_after_days ? `med. cert. after ${row.medical_certificate_after_days} days` : null,
        row.lapses_yearly ? 'lapses yearly' : null,
    ].filter(Boolean).join(' · ');

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Leave type' },
    { key: 'workflow', label: 'Approval' },
    { key: 'rules', label: 'Rules', sortable: false, value: rules },
    { key: 'eligible_statuses', label: 'Who', sortable: false, value: (row) => (row.eligible_statuses?.length ? row.eligible_statuses.map(statusLabel).join(', ') : 'Everyone') },
    { key: 'is_active', label: 'Status', value: (row) => (row.is_active ? 'Active' : 'Inactive') },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center' },
];

const field = 'mt-1 block w-full';
const number = 'mt-1 block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
const checks = [
    ['requires_balance', 'Needs a balance (credits) to file'],
    ['lapses_yearly', 'Unused credits lapse at year end'],
    ['requires_solo_parent_id', 'Needs a valid Solo Parent ID'],
    ['allows_emergency_filing', 'In an emergency, can be filed on return instead of in advance'],
    ['allows_half_day', 'Half days allowed'],
    ['counts_calendar_days', 'Counted in calendar days (not working days)'],
    ['monetizable', 'Can be monetized'],
    ['is_active', 'Active (can be filed)'],
];
</script>

<template>
    <Head title="Leave Types" />

    <PageHeader title="Leave Types" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Types' }]">
        <template v-if="canManage" #actions>
            <Button variant="success" @click="edit()"><Icon name="plus" size="sm" />Add leave type</Button>
        </template>
    </PageHeader>

    <Card title="Leave types" icon="clipboard" description="Seeded from CS Form No. 6 and the statutes in each policy reference. Confirm the entitlements against current CSC issuances.">
        <DataTable :columns="columns" :rows="types" caption="Leave types" export-name="imis-leave-types" :searchable="false">
            <template #cell-name="{ row }">
                <span class="font-medium text-text">{{ row.name }}</span>
                <div v-if="row.policy_reference" class="text-xs text-text-muted">{{ row.policy_reference }}</div>
            </template>
            <template #cell-workflow="{ row }">{{ row.workflow === 'special' ? 'HR → Head' : 'Supervisor → HR → Head' }}</template>
            <template #cell-rules="{ row }"><span class="text-xs text-text">{{ rules(row) || '—' }}</span></template>
            <template #cell-eligible_statuses="{ row }">
                <span class="text-xs">{{ row.eligible_statuses?.length ? row.eligible_statuses.map(statusLabel).join(', ') : 'Everyone' }}</span>
            </template>
            <template #cell-is_active="{ row }"><StatusBadge :status="row.is_active ? 'active' : 'inactive'" :label="row.is_active ? 'Active' : 'Inactive'" /></template>
            <template #cell-actions="{ row }">
                <ActionButton v-if="canManage" icon="pencil" :label="`Edit ${row.name}`" @click="edit(row)" />
            </template>
        </DataTable>
    </Card>

    <Modal :open="open" :title="editing ? `Edit ${editing.name}` : 'Add leave type'" :tone="editing ? 'primary' : 'success'" size="xl" @close="open = false">
        <form id="leave-type-form" class="grid gap-4 sm:grid-cols-4" @submit.prevent="submit">
            <div>
                <TextInput id="type-code" v-model="form.code" label="Code" required :invalid="!!form.errors.code" />
                <InputError :message="form.errors.code" />
            </div>
            <div class="sm:col-span-3">
                <TextInput id="type-name" v-model="form.name" label="Name" required :invalid="!!form.errors.name" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="sm:col-span-2">
                <label for="type-workflow" class="block text-sm font-medium text-text">Approval route</label>
                <Select id="type-workflow" v-model="form.workflow" :class="field" :options="[{ value: 'standard', label: 'Supervisor → HR → Head of Agency' }, { value: 'special', label: 'HR → Head of Agency' }]" />
            </div>
            <div class="sm:col-span-2">
                <label for="type-charged" class="block text-sm font-medium text-text">Uses the credits of</label>
                <Select id="type-charged" v-model="form.charged_to_id" :class="field" :options="chargeOptions" placeholder="— Its own —" :invalid="!!form.errors.charged_to_id" />
                <InputError :message="form.errors.charged_to_id" />
            </div>

            <div v-for="[key, label] in [['monthly_accrual', 'Earned per month'], ['annual_grant', 'Granted each year'], ['max_days_per_year', 'Max days a year'], ['max_days_per_filing', 'Max days per filing']]" :key="key">
                <label :for="`type-${key}`" class="block text-sm font-medium text-text">{{ label }}</label>
                <input :id="`type-${key}`" v-model="form[key]" type="number" step="0.001" min="0" :class="number" :aria-invalid="!!form.errors[key]" />
                <InputError :message="form.errors[key]" />
            </div>
            <div v-for="[key, label] in [['advance_filing_days', 'File days in advance'], ['max_consecutive_days', 'Max days in a row'], ['medical_certificate_after_days', 'Medical cert. after (days)'], ['min_service_months', 'Min. service (months)'], ['sort_order', 'Order']]" :key="key">
                <label :for="`type-${key}`" class="block text-sm font-medium text-text">{{ label }}</label>
                <input :id="`type-${key}`" v-model="form[key]" type="number" min="0" :class="number" :aria-invalid="!!form.errors[key]" />
                <InputError :message="form.errors[key]" />
            </div>

            <div>
                <label for="type-sex" class="block text-sm font-medium text-text">For</label>
                <Select id="type-sex" v-model="form.sex" :class="field" :options="[{ value: '', label: 'Everyone' }, { value: 'female', label: 'Women' }, { value: 'male', label: 'Men' }]" />
            </div>
            <fieldset class="sm:col-span-3">
                <legend class="block text-sm font-medium text-text">Employment statuses (none ticked = everyone)</legend>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    <label v-for="option in statusOptions" :key="option.value" class="inline-flex items-center gap-1.5 text-sm text-text">
                        <input v-model="form.eligible_statuses" type="checkbox" :value="option.value" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                        {{ option.label }}
                    </label>
                </div>
                <InputError :message="form.errors.eligible_statuses" />
            </fieldset>

            <fieldset class="sm:col-span-4">
                <legend class="sr-only">Rules</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label v-for="[key, label] in checks" :key="key" class="inline-flex items-center gap-2 text-sm text-text">
                        <input v-model="form[key]" type="checkbox" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                        {{ label }}
                    </label>
                </div>
            </fieldset>

            <div class="sm:col-span-4">
                <TextInput id="type-docs" v-model="form.supporting_documents" label="Supporting documents to attach" :invalid="!!form.errors.supporting_documents" />
                <InputError :message="form.errors.supporting_documents" />
            </div>
            <div class="sm:col-span-4">
                <TextInput id="type-policy" v-model="form.policy_reference" label="Policy reference" :invalid="!!form.errors.policy_reference" />
                <InputError :message="form.errors.policy_reference" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="open = false">Cancel</Button>
            <Button type="submit" form="leave-type-form" :variant="editing ? 'primary' : 'success'" :loading="form.processing">Save</Button>
        </template>
    </Modal>
</template>
