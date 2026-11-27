<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-6a: official holidays, entered by HR from each year's proclamations.
 * Regular holidays and special non-working days aren't counted as leave
 * days; special working days are. A repeating holiday is entered once by
 * month and day and counts in every year; entering a dated holiday of the
 * same name in a year moves it for that year only.
 */
const props = defineProps({
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    repeating: { type: Array, required: true },
    holidays: { type: Array, required: true },
    typeOptions: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const changeYear = (value) => router.get('/admin/hr/leave/holidays', { year: value }, { preserveScroll: true });

const open = ref(false);
const editing = ref(null);
const edit = (holiday = null, defaults = {}) => {
    editing.value = holiday;
    newDefaults.value = { ...blank, ...defaults };
    open.value = true;
};
const remove = async (holiday) => {
    const text = holiday.repeats_annually
        ? 'It is removed from every year, past ones included. Leave days already counted are not recounted.'
        : 'Leave days already counted are not recounted.';
    if (await confirm({ title: `Remove ${holiday.name}?`, text, tone: 'danger', confirmText: 'Remove' })) {
        router.delete(`/admin/hr/leave/holidays/${holiday.id}`, { preserveScroll: true });
    }
};

const blank = { date: '', type: 'regular', name: '', proclamation: '', repeats_annually: false };
const newDefaults = ref({ ...blank });

const fields = [
    { key: 'date', label: 'Date', type: 'date', required: true, help: 'For a holiday that repeats every year, only the month and day are kept.' },
    { key: 'type', label: 'Type', type: 'select', required: true, options: props.typeOptions },
    { key: 'name', label: 'Name', required: true, span: 'full' },
    { key: 'proclamation', label: 'Proclamation (e.g. Proclamation No. …)', span: 'full' },
    { key: 'repeats_annually', label: 'Repeats every year on this month and day (not for movable holidays like Holy Week or National Heroes Day)', type: 'checkbox' },
];

const repeatingColumns = [
    { key: 'month_day', label: 'Every year on' },
    { key: 'name', label: 'Holiday' },
    { key: 'type_label', label: 'Type' },
    { key: 'proclamation', label: 'Proclamation', value: (row) => row.proclamation ?? '' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center' },
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
            <Button v-if="canManage" variant="success" @click="edit()"><Icon name="plus" size="sm" />Add holiday</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card title="Every year" icon="refresh" description="Fixed-date holidays, counted in every year. To move one in a particular year, add that year's date under the same name.">
            <template v-if="canManage" #actions>
                <Button variant="secondary" size="sm" @click="edit(null, { repeats_annually: true })"><Icon name="plus" size="sm" />Add repeating holiday</Button>
            </template>
            <DataTable :columns="repeatingColumns" :rows="repeating" caption="Holidays every year" export-name="imis-holidays-every-year" empty-text="No repeating holidays yet.">
                <template #cell-month_day="{ row }"><span class="whitespace-nowrap">{{ row.month_day }}</span></template>
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

        <Card :title="`${year} only`" icon="calendar" description="Holidays proclaimed for this year alone, such as Holy Week, National Heroes Day and the Eid days. Days off are skipped when counting leave days.">
            <template #actions>
                <label class="sr-only" for="holiday-year">Year</label>
                <select id="holiday-year" :value="year" class="filter-control" @change="changeYear($event.target.value)">
                    <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
                </select>
            </template>
            <DataTable :columns="columns" :rows="holidays" :caption="`Holidays ${year}`" :export-name="`imis-holidays-${year}`" :empty-text="`No holidays entered for ${year} alone yet.`">
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
    </div>

    <RecordModal
        v-if="canManage"
        :open="open"
        noun="Holiday"
        :record="editing"
        :fields="fields"
        :defaults="newDefaults"
        :nullable="['proclamation']"
        store-url="/admin/hr/leave/holidays"
        :update-url="(record) => `/admin/hr/leave/holidays/${record.id}`"
        @close="open = false"
    />
</template>
