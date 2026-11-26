<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import ApprovalTrail from '@cscro8/ui/Components/UI/ApprovalTrail.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import Textarea from '@cscro8/ui/Components/Forms/Textarea.vue';
import SalnHistory from '../../../../Components/HR/Saln/SalnHistory.vue';
import SalnFileUpload from '../../../../Components/HR/Saln/SalnFileUpload.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * One SALN for HRD and the SALN Committee: what was declared (read-only),
 * the printouts and history, and what can be done now — receive the hard
 * copy, clear or return it, or file the signed copy. Opening it is logged.
 */
const props = defineProps({
    saln: { type: Object, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const s = computed(() => props.saln.statement ?? {});
const t = computed(() => s.value.totals ?? {});
const tones = { draft: 'draft', generated: 'info', received: 'info', returned: 'warning', cleared: 'active', filed: 'active' };
const owners = Object.fromEntries(props.options.owners.map((owner) => [owner.value, owner.label]));
const peso = (value) => (value === null || value === undefined || value === '' ? '—' : String(value).toUpperCase() === 'N/A' ? 'N/A' : formatPeso(Number(value)));

const decision = useForm({ decision: 'clear', remarks: '' });
const decide = (choice) => {
    decision.decision = choice;
    decision.post(`/admin/hr/saln/${props.saln.id}/decide`, { preserveScroll: true });
};
const receiving = useForm({ saln_id: props.saln.id, version: props.saln.current_version });
const receive = () => receiving.post('/admin/hr/saln/receive', { preserveScroll: true });

const sections = computed(() => [
    {
        title: 'Real properties',
        rows: s.value.real_properties ?? [],
        cols: [['description', 'Description'], ['kind', 'Kind'], ['location', 'Location'], ['assessed_value', 'Assessed', true], ['market_value', 'Market value', true], ['acquisition_year', 'Year'], ['acquisition_mode', 'Mode'], ['acquisition_cost', 'Cost', true]],
        // Eight columns don't fit: fold them into three cells, each field still its own CSV column.
        folds: [
            { key: 'property', label: 'Property', fields: ['description', 'kind', 'location'] },
            { key: 'values', label: 'Value', fields: ['assessed_value', 'market_value'] },
            { key: 'acquired', label: 'Acquired', fields: ['acquisition_year', 'acquisition_mode', 'acquisition_cost'] },
        ],
    },
    { title: 'Personal properties', rows: s.value.personal_properties ?? [], cols: [['description', 'Description'], ['acquisition_year', 'Year'], ['acquisition_cost', 'Cost / amount', true]] },
    { title: 'Liabilities', rows: s.value.liabilities ?? [], cols: [['nature', 'Nature'], ['creditor', 'Creditor'], ['outstanding_balance', 'Outstanding balance', true]] },
    { title: 'Business interests and financial connections', rows: s.value.business_interests ?? [], none: s.value.no_business_interests && 'Declares none.', cols: [['name', 'Entity'], ['address', 'Address'], ['nature', 'Nature'], ['acquired_on', 'Acquired']] },
    { title: 'Relatives in the government service', rows: s.value.relatives ?? [], none: s.value.no_relatives && 'Knows of none.', cols: [['name', 'Name'], ['relationship', 'Relationship'], ['position', 'Position'], ['agency_and_address', 'Agency/office and address']] },
].map((section) => ({ ...section, rows: section.rows.map((row, index) => ({ ...row, _row: index })), columns: columnsOf(section) })));

const cellText = (row, [key, , num]) => (num ? peso(row[key]) : (row[key] ?? ''));
const labelOf = (section, key) => section.cols.find(([name]) => name === key);
const factsOf = (section, fold, row) => fold.fields.map((key) => ({ label: labelOf(section, key)[1], value: cellText(row, labelOf(section, key)) || null }));
function columnsOf(section) {
    const owner = section.rows.some((row) => row.owner) ? [{ key: 'owner', label: 'Whose', value: (row) => owners[row.owner] ?? '' }] : [];
    const plain = (col, extra = {}) => ({ key: col[0], label: col[1], align: col[2] ? 'right' : 'left', value: (row) => cellText(row, col), ...extra });
    if (!section.folds) {
        return [...owner, ...section.cols.map((col) => plain(col))];
    }
    return [
        ...owner,
        ...section.folds.map((fold) => ({ key: fold.key, label: fold.label, sortable: false, export: false })),
        ...section.cols.map((col) => plain(col, { hidden: true })),
    ];
}
</script>

<template>
    <Head :title="`SALN ${saln.reference}`" />

    <PageHeader
        :title="`SALN of ${saln.employee.name}`"
        :description="`${saln.type_label} as of ${formatCalendarDate(saln.reckoning_date)} · ${saln.reference}`"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'SALN Review', href: '/admin/hr/saln/queue' }, { label: saln.reference }]"
    >
        <template #actions>
            <StatusBadge :status="tones[saln.status]" :label="saln.status_label" />
            <Button v-if="can.edit" variant="outline" size="sm" :href="`/admin/hr/saln/${saln.id}/edit`">Encode on their behalf</Button>
        </template>
    </PageHeader>

    <Card class="mb-6" title="Where it is" icon="activity">
        <ApprovalTrail :trail="saln.trail" label="SALN progress" />
    </Card>

    <div class="grid gap-6 xl:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
            <Card v-if="can.decide" title="Review" icon="checkCircle" description="Compare the hard copy with these entries and the form's requirements. Every applicable item must be filled in or marked N/A, and every page signed.">
                <InputLabel for="decide-remarks">Remarks (needed to return it)</InputLabel>
                <Textarea id="decide-remarks" v-model="decision.remarks" class="mt-1" placeholder="What the declarant should correct" />
                <InputError :message="decision.errors.remarks" />
                <div class="mt-3 flex flex-wrap gap-3">
                    <Button variant="success" :loading="decision.processing" @click="decide('clear')">Clear for oath</Button>
                    <Button variant="outline" :loading="decision.processing" @click="decide('return')">Return for correction</Button>
                </div>
            </Card>

            <Card v-if="can.receive" title="Receive the hard copy" icon="documentText" :description="`Check that every page shows ${saln.reference} v${saln.current_version}. An older printout can't be received.`">
                <InputError :message="receiving.errors.reference" />
                <Button :loading="receiving.processing" @click="receive">Receive v{{ saln.current_version }}</Button>
            </Card>

            <SalnFileUpload v-if="can.file" :url="`/admin/hr/saln/${saln.id}/file`" :max-kilobytes="options.maxKilobytes" :reference="saln.reference" />

            <Card title="Declarant" icon="user">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-text-muted">Name</dt><dd class="text-text">{{ [s.declarant?.family_name, s.declarant?.first_name, s.declarant?.middle_initial].filter(Boolean).join(', ') }}</dd></div>
                    <div><dt class="text-text-muted">Position</dt><dd class="text-text">{{ s.declarant?.position }}</dd></div>
                    <div><dt class="text-text-muted">Agency/office</dt><dd class="text-text">{{ s.declarant?.agency }}</dd></div>
                    <div><dt class="text-text-muted">Office address</dt><dd class="text-text">{{ s.declarant?.office_address }}</dd></div>
                    <div><dt class="text-text-muted">Spouse</dt><dd class="text-text">{{ s.spouse ? `${s.spouse.family_name}, ${s.spouse.first_name}${s.spouse.in_government ? ` — ${s.spouse.position}, ${s.spouse.agency}` : ''}` : 'None declared' }}</dd></div>
                    <div><dt class="text-text-muted">Children below 18</dt><dd class="text-text">{{ (s.children ?? []).map((c) => `${c.name} (${c.age})`).join('; ') || 'None declared' }}</dd></div>
                    <div><dt class="text-text-muted">Government ID</dt><dd class="text-text">{{ s.government_id?.type }} {{ s.government_id?.number }}</dd></div>
                    <div><dt class="text-text-muted">Oath</dt><dd class="text-text">{{ options.oathModes.find((m) => m.value === saln.oath_mode)?.label }}<template v-if="saln.officer_name"> — {{ saln.officer_name }}, {{ saln.officer_title }}</template></dd></div>
                </dl>
            </Card>

            <Card v-for="section in sections" :key="section.title" :title="section.title" icon="clipboard">
                <p v-if="section.none" class="text-sm text-text-muted">{{ section.none }}</p>
                <p v-else-if="!section.rows.length" class="text-sm text-text-muted">None declared.</p>
                <DataTable v-else :columns="section.columns" :page-size-select="false" :rows="section.rows" row-key="_row" :caption="section.title" :export-name="`imis-saln-${saln.reference}`" :numbered="false" :searchable="false" density="compact">
                    <template v-for="fold in section.folds ?? []" :key="fold.key" #[`cell-${fold.key}`]="{ row }">
                        <CellFacts wrap class="tabular-nums" :facts="factsOf(section, fold, row)" />
                    </template>
                </DataTable>
            </Card>

            <Card title="Totals" icon="chartBar">
                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between"><dt class="text-text-muted">Total assets</dt><dd class="tabular-nums text-text">{{ peso(t.total_assets) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-text-muted">Total liabilities</dt><dd class="tabular-nums text-text">{{ peso(t.total_liabilities) }}</dd></div>
                    <div class="flex justify-between font-semibold sm:col-span-2"><dt class="text-text">Net worth</dt><dd class="tabular-nums text-text">{{ peso(t.net_worth) }}</dd></div>
                </dl>
            </Card>
        </div>

        <SalnHistory :saln="saln" :print-base="`/admin/hr/saln/${saln.id}/versions`" />
    </div>
</template>
