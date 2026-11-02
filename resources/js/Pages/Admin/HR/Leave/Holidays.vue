<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-6a: official holidays, entered by HR from each year's proclamations.
 * Regular holidays and special non-working days aren't counted as leave
 * days; special working days are.
 */
const props = defineProps({
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    holidays: { type: Array, required: true },
    typeOptions: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const changeYear = (value) => router.get('/admin/hr/leave/holidays', { year: value }, { preserveScroll: true });

const open = ref(false);
const editing = ref(null);
const edit = (holiday = null) => {
    editing.value = holiday;
    open.value = true;
};
const remove = async (holiday) => {
    if (await confirm({ title: `Remove ${holiday.name}?`, text: 'Leave days already counted are not recounted.', tone: 'danger', confirmText: 'Remove' })) {
        router.delete(`/admin/hr/leave/holidays/${holiday.id}`, { preserveScroll: true });
    }
};

const fields = [
    { key: 'date', label: 'Date', type: 'date', required: true },
    { key: 'type', label: 'Type', type: 'select', required: true, options: props.typeOptions },
    { key: 'name', label: 'Name', required: true, span: 'full' },
    { key: 'proclamation', label: 'Proclamation (e.g. Proclamation No. …)', span: 'full' },
];

const columns = [
    { key: 'date', label: 'Date' },
    { key: 'name', label: 'Holiday' },
    { key: 'type_label', label: 'Type' },
    { key: 'proclamation', label: 'Proclamation', value: (row) => row.proclamation ?? '' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center' },
];
</script>

<template>
    <Head title="Holidays" />

    <PageHeader title="Holidays" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Holidays' }]">
        <template #actions>
            <label class="sr-only" for="holiday-year">Year</label>
            <select id="holiday-year" :value="year" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring" @change="changeYear($event.target.value)">
                <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
            </select>
            <Button v-if="canManage" variant="success" @click="edit()"><Icon name="plus" size="sm" />Add holiday</Button>
        </template>
    </PageHeader>

    <Card :title="`Holidays — ${year}`" icon="calendar" description="From the year's proclamations. Days off are skipped when counting leave days.">
        <DataTable :columns="columns" :rows="holidays" :caption="`Holidays ${year}`" :export-name="`imis-holidays-${year}`" :empty-text="`No holidays entered for ${year} yet.`">
            <template #cell-date="{ row }"><span class="whitespace-nowrap">{{ formatCalendarDate(row.date) }}</span></template>
            <template #cell-type_label="{ row }"><StatusBadge :status="row.day_off ? 'info' : 'draft'" :label="row.type_label" /></template>
            <template #cell-proclamation="{ row }">{{ row.proclamation || '—' }}</template>
            <template #cell-actions="{ row }">
                <div v-if="canManage" class="flex justify-center gap-1">
                    <ActionButton icon="pencil" :label="`Edit ${row.name}`" @click="edit(row)" />
                    <ActionButton icon="trash" tone="danger" :label="`Remove ${row.name}`" @click="remove(row)" />
                </div>
            </template>
        </DataTable>
    </Card>

    <RecordModal
        v-if="canManage"
        :open="open"
        noun="Holiday"
        :record="editing"
        :fields="fields"
        :defaults="{ date: '', type: 'regular', name: '', proclamation: '' }"
        :nullable="['proclamation']"
        store-url="/admin/hr/leave/holidays"
        :update-url="(record) => `/admin/hr/leave/holidays/${record.id}`"
        @close="open = false"
    />
</template>
