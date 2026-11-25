<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import Switch from '@cscro8/ui/Components/Forms/Switch.vue';
import { formatCalendarDate } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * My SALN: what is due, your SALNs, and starting one. The Statement of
 * Assets, Liabilities and Net Worth (RA 6713) is encoded here, printed on
 * the 2025 form, signed and sworn on paper, and filed in your 201 File.
 */
const props = defineProps({
    hasRecord: { type: Boolean, required: true },
    salns: { type: Array, required: true },
    due: { type: Array, required: true },
    hasFiled: { type: Boolean, required: true },
    options: { type: Object, required: true },
});

const form = useForm({ type: 'annual', year: new Date().getFullYear() - 1, as_of: '', from_previous: props.hasFiled });
const start = (row) => {
    if (row) {
        form.type = row.type;
        form.year = row.year;
        form.as_of = row.as_of;
    }
    form.post('/my/saln');
};

const tones = { draft: 'draft', generated: 'info', received: 'info', returned: 'warning', cleared: 'active', filed: 'active' };
const dueTones = { missing: 'warning', late: 'inactive', in_progress: 'info' };
const dueLabels = { missing: 'Not started', late: 'Late', in_progress: 'In progress' };

const columns = [
    { key: 'reference', label: 'Reference' },
    { key: 'type_label', label: 'Filing' },
    { key: 'reckoning_date', label: 'As of', value: (row) => formatCalendarDate(row.reckoning_date) },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '5rem' },
];
const asOfLabel = computed(() => (form.type === 'entry' ? 'First day of service' : 'Last day in office'));
</script>

<template>
    <Head title="My SALN" />

    <PageHeader
        title="My SALN"
        description="Your Sworn Statement of Assets, Liabilities and Net Worth. Encode it here, print it on the 2025 form, and hand the signed copy to HRD."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My SALN' }]"
    />

    <EmptyState v-if="!hasRecord" icon="user" title="No employee record yet" description="HRD hasn't set up your employee record, so there is no SALN to file here." />

    <div v-else class="space-y-6">
        <Card v-if="due.length" title="Due" icon="calendar">
            <ul class="divide-y divide-border">
                <li v-for="row in due" :key="`${row.type}-${row.as_of}`" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <p class="font-medium text-text">{{ row.type_label }} — as of {{ formatCalendarDate(row.as_of) }}</p>
                        <p class="text-sm text-text-muted">Due {{ formatCalendarDate(row.due) }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <StatusBadge :status="dueTones[row.state] ?? 'draft'" :label="dueLabels[row.state] ?? row.state" />
                        <Link v-if="row.saln_id" :href="`/my/saln/${row.saln_id}`" class="text-sm text-link hover:underline">Continue</Link>
                        <Button v-else size="sm" :loading="form.processing" @click="start(row)">Start</Button>
                    </div>
                </li>
            </ul>
        </Card>

        <Card title="Your SALNs" icon="documentText">
            <DataTable :columns="columns" :rows="salns" caption="Your SALNs" export-name="imis-my-saln" :searchable="false" empty-text="No SALN yet.">
                <template #cell-status="{ row }"><StatusBadge :status="tones[row.status]" :label="row.status_label" /></template>
                <template #cell-actions="{ row }">
                    <ActionButton icon="eye" tone="neutral" :label="`Open ${row.reference}`" :href="`/my/saln/${row.id}`" />
                </template>
            </DataTable>
        </Card>

        <Card title="Start another SALN" icon="plus" description="For example an exit SALN, or one HRD asked for.">
            <form class="grid gap-4 sm:grid-cols-3" @submit.prevent="start()">
                <div>
                    <InputLabel for="saln-type">Filing</InputLabel>
                    <select id="saln-type" v-model="form.type" class="form-control mt-1 block w-full px-3 py-2">
                        <option v-for="option in options.types" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div v-if="form.type === 'annual'">
                    <InputLabel for="saln-year">As of December 31 of</InputLabel>
                    <input id="saln-year" v-model="form.year" type="number" min="2000" class="form-control mt-1 block w-full px-3 py-2" />
                    <InputError :message="form.errors.year" />
                </div>
                <div v-else>
                    <InputLabel for="saln-as-of">{{ asOfLabel }}</InputLabel>
                    <input id="saln-as-of" v-model="form.as_of" type="date" class="form-control mt-1 block w-full px-3 py-2" />
                    <InputError :message="form.errors.as_of" />
                </div>
                <div class="flex items-end">
                    <Button type="submit" :loading="form.processing">Start</Button>
                </div>
                <div v-if="hasFiled" class="sm:col-span-3">
                    <Switch id="from-previous" v-model="form.from_previous" label="Start from my last filed SALN" description="Copies your entries; update them to the new date." />
                </div>
                <InputError class="sm:col-span-3" :message="form.errors.type" />
            </form>
        </Card>
    </div>
</template>
