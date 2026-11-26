<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import FileInput from '@cscro8/ui/Components/Forms/FileInput.vue';
import { formatPeso } from '../../../../Utils/money';
import { formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-8: the accounting office's payroll workbook beside this payroll run,
 * person by person. Upload it; for everyone who doesn't agree to the
 * centavo, the effects (basic salary, GSIS, tax and loans, …) add up to the
 * difference. The workbook is read and discarded; nothing is saved.
 */
const props = defineProps({
    run: { type: Object, required: true },
    result: { type: Object, default: null },
});

const form = useForm({ file: null });
const send = () => form.post(`/admin/hr/payroll/runs/${props.run.id}/reconciliation`, { forceFormData: true, onSuccess: () => form.reset() });
const clear = () => router.delete(`/admin/hr/payroll/runs/${props.run.id}/reconciliation`);

const show = ref('differences');
const rows = computed(() => {
    const all = props.result?.rows ?? [];

    return show.value === 'differences' ? all.filter((row) => row.status !== 'agrees') : all;
});
const columns = [
    { key: 'name', label: 'Workbook name' },
    { key: 'workbook_net', label: 'Workbook net', align: 'right' },
    { key: 'imis_net', label: 'IMIS net', align: 'right' },
    { key: 'variance', label: 'IMIS − workbook', align: 'right' },
    { key: 'effects', label: 'Why', sortable: false, value: (row) => row.effects.map((effect) => `${effect.label} ${effect.amount}`).join('; ') },
];
const signed = (amount) => `${amount > 0 ? '+' : '−'}${formatPeso(Math.abs(amount))}`;
</script>

<template>
    <Head :title="`Compare with workbook — ${run.period}`" />

    <PageHeader
        :title="`Compare with the payroll workbook — ${run.period}`"
        description="Upload the accounting office's payroll workbook to see, person by person, where this payroll differs and why. Nothing is saved."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll', href: '/admin/hr/payroll' }, { label: run.period, href: `/admin/hr/payroll/runs/${run.id}` }, { label: 'Compare with workbook' }]"
    />

    <Card title="1. Upload the workbook" icon="download">
        <p class="text-sm text-text">
            Use the monthly workbook with the <strong>Final Payroll</strong> sheet, saved as <strong>.xlsx</strong>. It is read here and not kept.
        </p>
        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="send">
            <div class="min-w-64 flex-1">
                <InputLabel for="reconcile-file">Payroll workbook (.xlsx)</InputLabel>
                <FileInput id="reconcile-file" class="mt-1" accept=".xlsx" required :invalid="!!form.errors.file" @update:model-value="(file) => (form.file = file)" />
                <InputError :message="form.errors.file" />
            </div>
            <Button type="submit" :loading="form.processing" :disabled="!form.file"><Icon name="checkCircle" size="sm" />Compare</Button>
        </form>
    </Card>

    <template v-if="result">
        <Card :title="`2. Result: ${result.file}`" icon="clipboard" class="mt-6" :description="`Compared ${formatDateTime(result.at)}`">
            <template #actions>
                <Button variant="secondary" size="sm" @click="clear">Clear</Button>
            </template>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-5">
                <div><dt class="text-text-muted">In the workbook</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ result.summary.workbook }}</dd></div>
                <div><dt class="text-text-muted">Agree to the centavo</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ result.summary.agree }}</dd></div>
                <div><dt class="text-text-muted">Differ</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ result.summary.differ }}</dd></div>
                <div><dt class="text-text-muted">Not found in IMIS</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ result.summary.unmatched }}</dd></div>
                <div><dt class="text-text-muted">On the payroll, not the workbook</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ result.summary.only_in_imis }}</dd></div>
            </dl>

            <FilterButtons v-model="show" class="mt-4" label="Show" :options="[{ value: 'differences', label: 'Differences only' }, { value: 'all', label: 'Everyone' }]" />

            <DataTable class="mt-3" :columns="columns" :rows="rows" :caption="`Workbook against payroll, ${run.period}`" :export-name="`imis-reconciliation-${run.month}`" numbered>
                <template #cell-name="{ row }">
                    <div class="font-medium text-text">{{ row.name }}</div>
                    <div v-if="row.account && row.account !== row.name" class="text-xs text-text-muted">{{ row.account }}</div>
                </template>
                <template #cell-workbook_net="{ row }"><span class="tabular-nums">{{ formatPeso(row.workbook_net) }}</span></template>
                <template #cell-imis_net="{ row }"><span class="tabular-nums">{{ row.imis_net === null ? '—' : formatPeso(row.imis_net) }}</span></template>
                <template #cell-variance="{ row }">
                    <Badge v-if="row.status === 'agrees'" tone="success">Agrees</Badge>
                    <span v-else-if="row.variance !== null" class="font-semibold tabular-nums">{{ signed(row.variance) }}</span>
                    <Badge v-else tone="warning">Not matched</Badge>
                </template>
                <template #cell-effects="{ row }">
                    <ul v-if="row.effects.length" class="space-y-0.5 text-sm">
                        <li v-for="effect in row.effects" :key="effect.label"><span class="text-text-muted">{{ effect.label }}:</span> <span class="tabular-nums">{{ signed(effect.amount) }}</span></li>
                    </ul>
                    <span v-else-if="row.note" class="text-sm text-text-muted">{{ row.note }}</span>
                </template>
            </DataTable>
        </Card>

        <Card v-if="result.only_in_imis.length" title="On this payroll but not in the workbook" icon="exclamation" class="mt-6">
            <ul class="divide-y divide-border text-sm">
                <li v-for="name in result.only_in_imis" :key="name" class="py-2">{{ name }}</li>
            </ul>
        </Card>
    </template>
</template>
