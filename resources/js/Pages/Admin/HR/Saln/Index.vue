<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import StatTile from '@cscro8/ui/Components/UI/StatTile.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import Switch from '@cscro8/ui/Components/Forms/Switch.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * SALN compliance for a year: everyone required to file the annual SALN
 * (status on December 31: permanent, temporary, coterminous, contractual),
 * plus entry and exit SALNs that fell due in the year. HRD can start a SALN
 * for someone (e.g. a separated employee who can't sign in) or exempt them.
 */
const props = defineProps({
    year: { type: Number, required: true },
    rows: { type: Array, required: true },
    counts: { type: Object, required: true },
    canManage: { type: Boolean, required: true },
    multipleMarriages: { type: Boolean, required: true },
});

// HRD's switch: whether employees are asked about multiple marriages.
const toggleMarriages = (value) => router.post('/admin/hr/saln/settings', { multiple_marriages: value }, { preserveScroll: true, preserveState: true });

const pickYear = (event) => router.get('/admin/hr/saln', { year: event.target.value }, { preserveState: true });
const years = computed(() => Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i));

const tones = { filed: 'active', in_progress: 'info', missing: 'warning', late: 'inactive', exempt: 'draft' };
const labels = { filed: 'Filed', in_progress: 'In progress', missing: 'Not started', late: 'Late', exempt: 'Exempt' };

const opening = useForm({ employee_id: null, type: 'annual', as_of: '', year: null });
const encode = (row) => {
    opening.employee_id = row.employee_id;
    opening.type = row.type;
    opening.as_of = row.as_of;
    opening.year = Number(row.as_of.slice(0, 4));
    opening.post('/admin/hr/saln');
};

const exempting = ref(null);
const exemption = useForm({ employee_id: null, year: props.year, reason: '' });
const startExempt = (row) => {
    exempting.value = row;
    exemption.employee_id = row.employee_id;
    exemption.year = Number(row.as_of.slice(0, 4));
    exemption.reason = '';
};
const saveExempt = () => exemption.post('/admin/hr/saln/exemptions', { preserveScroll: true, onSuccess: () => (exempting.value = null) });

const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'unit', label: 'Office', value: (row) => row.unit ?? '' },
    { key: 'type_label', label: 'Filing' },
    { key: 'due', label: 'Due', value: (row) => row.due },
    { key: 'state', label: 'Status', value: (row) => labels[row.state] },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '8rem' },
];
</script>

<template>
    <Head title="SALN Compliance" />

    <PageHeader
        title="SALN Compliance"
        description="Who has filed their Statement of Assets, Liabilities and Net Worth. Annual SALNs are due April 30; entry and exit SALNs within 30 days."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'SALN Compliance' }]"
    >
        <template #actions>
            <Link href="/admin/hr/saln/queue" class="text-sm text-link hover:underline">Review queue</Link>
            <label class="sr-only" for="saln-year">Year</label>
            <select id="saln-year" :value="year" class="filter-control" @change="pickYear">
                <option v-for="y in years" :key="y" :value="y">As of Dec 31, {{ y }}</option>
            </select>
        </template>
    </PageHeader>

    <Card v-if="canManage" title="SALN settings" icon="cog" class="mb-6">
        <Switch
            id="saln-multiple-marriages"
            :model-value="multipleMarriages"
            label="Ask employees about multiple marriages"
            description="Only where the law allows more than one marriage. Off, the question is hidden from the SALN and the form prints “Not Applicable”."
            @update:model-value="toggleMarriages"
        />
    </Card>

    <div class="stagger mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatTile label="Required to file" :value="counts.required" icon="users" />
        <StatTile label="Filed" :value="counts.filed" icon="checkCircle" tone="success" :progress="counts.required ? Math.round((counts.filed / counts.required) * 100) : null" />
        <StatTile label="In progress" :value="counts.in_progress" icon="clock" tone="info" />
        <StatTile label="Not started or late" :value="counts.missing + counts.late" icon="exclamation" tone="warning" />
    </div>

    <Card v-if="exempting" title="Exempt from filing" icon="shield" :description="`${exempting.name}, for ${exemption.year}. For example a laborer position or an honorary role.`" class="mb-6">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="saveExempt">
            <TextInput id="exempt-reason" v-model="exemption.reason" label="Reason" required maxlength="255" class="min-w-64 flex-1" :error="exemption.errors.reason" />
            <Button type="submit" :loading="exemption.processing">Record exemption</Button>
            <Button variant="ghost" @click="exempting = null">Cancel</Button>
        </form>
    </Card>

    <Card :title="`Filings as of ${year}`" icon="clipboard" description="Opening a SALN is logged.">
        <DataTable :columns="columns" :rows="rows" caption="SALN compliance" :export-name="`imis-saln-compliance-${year}`" empty-text="Nobody is required to file for this year.">
            <template #cell-name="{ row }"><PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="row.position" /></template>
            <template #cell-unit="{ row }">{{ row.unit || '—' }}</template>
            <template #cell-due="{ row }"><span class="whitespace-nowrap">{{ formatCalendarDate(row.due) }}</span></template>
            <template #cell-state="{ row }">
                <StatusBadge :status="tones[row.state]" :label="labels[row.state]" />
                <p v-if="row.status_label && row.state !== 'filed'" class="mt-1 text-xs text-text-muted">{{ row.status_label }}</p>
                <p v-if="row.exempt_reason" class="mt-1 text-xs text-text-muted">{{ row.exempt_reason }}</p>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex justify-center gap-1">
                    <ActionButton v-if="row.saln_id" icon="eye" tone="neutral" :label="`Open the SALN of ${row.name}`" :href="`/admin/hr/saln/${row.saln_id}`" />
                    <ActionButton v-else-if="canManage && row.state !== 'exempt'" icon="pencil" tone="primary" :label="`Encode the SALN of ${row.name}`" @click="encode(row)" />
                    <ActionButton v-if="canManage && !row.saln_id && row.state !== 'exempt'" icon="shield" tone="neutral" :label="`Exempt ${row.name}`" @click="startExempt(row)" />
                </div>
            </template>
        </DataTable>
    </Card>
</template>
