<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { formatCalendarDate, formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The SALN queue. HRD receives hard copies (by the reference printed on
 * each page, so an outdated printout is refused); the SALN Committee — or
 * HRD, overriding it — reviews what HRD received; HRD files the signed
 * copies once cleared. Your own SALN never appears here.
 */
defineProps({
    received: { type: Array, required: true },
    awaitingHardCopy: { type: Array, required: true },
    cleared: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const receiving = useForm({ reference: '' });
const receive = () => receiving.post('/admin/hr/saln/receive', { preserveScroll: true, onSuccess: () => receiving.reset() });
const receiveRow = (row) => router.post('/admin/hr/saln/receive', { saln_id: row.id, version: row.current_version }, { preserveScroll: true });

const columns = [
    { key: 'name', label: 'Employee', value: (row) => row.employee.name },
    { key: 'reference', label: 'Reference', value: (row) => `${row.reference} v${row.current_version}` },
    { key: 'type_label', label: 'Filing', value: (row) => `${row.type_label}, ${row.reckoning_date}` },
    { key: 'updated_at', label: 'Since', value: (row) => formatDateTime(row.updated_at) },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];
</script>

<template>
    <Head title="SALN Review" />

    <PageHeader
        title="SALN Review"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'SALN Review' }]"
    >
        <template #actions>
            <Link v-if="canManage" href="/admin/hr/saln" class="text-sm text-link hover:underline">Compliance</Link>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card v-if="canManage" title="Receive a hard copy" icon="documentText" description="Type the reference printed at the foot of each page. Only the latest printout can be received.">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="receive">
                <div class="min-w-64">
                    <InputLabel for="receive-reference">Reference</InputLabel>
                    <input id="receive-reference" v-model="receiving.reference" required placeholder="SALN-2025-00042 v2" class="form-control mt-1 block w-full px-3 py-2" />
                    <InputError :message="receiving.errors.reference" />
                </div>
                <Button type="submit" :loading="receiving.processing">Receive</Button>
            </form>
        </Card>

        <Card title="For review" icon="checkCircle" description="Hard copies HRD has received, oldest first. Clear one for the oath, or return it with what to correct.">
            <DataTable :columns="columns" :rows="received" caption="SALNs for review" export-name="imis-saln-review" empty-text="Nothing to review.">
                <template #cell-name="{ row }"><PersonCell :name="row.employee.name" :photo-url="row.employee.photo_url" :subtitle="row.employee.unit" /></template>
                <template #cell-type_label="{ row }">{{ row.type_label }}, {{ formatCalendarDate(row.reckoning_date) }}</template>
                <template #cell-actions="{ row }">
                    <ActionButton icon="eye" tone="neutral" :label="`Review the SALN of ${row.employee.name}`" :href="`/admin/hr/saln/${row.id}`" />
                </template>
            </DataTable>
        </Card>

        <template v-if="canManage">
            <Card title="Printed, hard copy not yet received" icon="clock">
                <DataTable :columns="columns" :rows="awaitingHardCopy" caption="SALNs awaiting their hard copy" export-name="imis-saln-awaiting" empty-text="None.">
                    <template #cell-name="{ row }"><PersonCell :name="row.employee.name" :photo-url="row.employee.photo_url" :subtitle="row.employee.unit" /></template>
                    <template #cell-type_label="{ row }">{{ row.type_label }}, {{ formatCalendarDate(row.reckoning_date) }}</template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-center gap-1">
                            <ActionButton icon="check" tone="success" :label="`Receive ${row.reference} v${row.current_version}`" @click="receiveRow(row)" />
                            <ActionButton icon="eye" tone="neutral" :label="`Open the SALN of ${row.employee.name}`" :href="`/admin/hr/saln/${row.id}`" />
                        </div>
                    </template>
                </DataTable>
            </Card>

            <Card title="Cleared, signed copy not yet filed" icon="upload">
                <DataTable :columns="columns" :rows="cleared" caption="Cleared SALNs awaiting the signed copy" export-name="imis-saln-cleared" empty-text="None.">
                    <template #cell-name="{ row }"><PersonCell :name="row.employee.name" :photo-url="row.employee.photo_url" :subtitle="row.employee.unit" /></template>
                    <template #cell-type_label="{ row }">{{ row.type_label }}, {{ formatCalendarDate(row.reckoning_date) }}</template>
                    <template #cell-actions="{ row }">
                        <ActionButton icon="upload" tone="primary" :label="`File the signed SALN of ${row.employee.name}`" :href="`/admin/hr/saln/${row.id}`" />
                    </template>
                </DataTable>
            </Card>
        </template>
    </div>
</template>
