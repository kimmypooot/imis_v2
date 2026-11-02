<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Button from '../../../../Components/UI/Button.vue';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import Card from '../../../../Components/UI/Card.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import ServiceRecordTable from '../../../../Components/HR/ServiceRecordTable.vue';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-5: someone's Service Record for HR, built from their plantilla
 * periods and reassignment history. "Print certified copy" opens the
 * printable form with the certifying officer from System Settings. HR
 * (`service_records.manage`) adds service in other agencies by hand.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    person: { type: Object, required: true },
    lines: { type: Array, required: true },
    printUrl: { type: String, required: true },
    canManage: { type: Boolean, default: false },
});

const base = `/admin/hr/employees/${props.employee.id}/service-record/entries`;

const STATUSES = ['Permanent', 'Temporary', 'Casual', 'Contractual', 'Coterminous', 'Substitute', 'Provisional'];
const BRANCHES = ['National', 'Local', 'GOCC', 'SUC'];

const fields = [
    { key: 'agency', label: 'Agency', required: true, span: 'full', help: 'The government agency, e.g. Department of Education.' },
    { key: 'station', label: 'Station / place of assignment', span: 'full', help: 'Office or division, e.g. Schools Division of Tacloban City.' },
    { key: 'date_from', label: 'From', type: 'date', required: true },
    { key: 'date_to', label: 'To', type: 'date', required: true },
    { key: 'designation', label: 'Designation', required: true, span: 'full' },
    { key: 'status', label: 'Status', required: true, suggestions: STATUSES },
    { key: 'branch', label: 'Branch', required: true, suggestions: BRANCHES },
    { key: 'salary_grade', label: 'Salary grade', type: 'number' },
    { key: 'step', label: 'Step', type: 'number' },
    { key: 'annual_salary', label: 'Annual salary', type: 'number' },
    { key: 'leave_without_pay', label: 'Leave without pay (days)', type: 'number' },
    { key: 'separation_date', label: 'Separation date', type: 'date' },
    { key: 'separation_cause', label: 'Cause of separation', help: 'e.g. Transfer, Resignation, End of contract.' },
    { key: 'remarks', label: 'Remarks', type: 'textarea' },
];

const defaults = {
    agency: '', station: '', date_from: '', date_to: '', designation: '', status: '', branch: 'National',
    salary_grade: '', step: '', annual_salary: '', leave_without_pay: '', separation_date: '', separation_cause: '', remarks: '',
};
const nullable = ['station', 'salary_grade', 'step', 'annual_salary', 'leave_without_pay', 'separation_date', 'separation_cause', 'remarks'];

const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => {
    editing.value = null;
    modalOpen.value = true;
};

// The form's values from a line on screen (its `entry` carries the rest).
const openEdit = (line) => {
    editing.value = {
        ...line,
        ...line.entry,
        name: line.designation,
        salary_grade: line.salary_grade ?? '',
        step: line.step ?? '',
        separation_date: line.separation_date ?? '',
        separation_cause: line.separation_cause ?? '',
    };
    modalOpen.value = true;
};

const remove = async (line) => {
    if (await confirm({ title: 'Remove this service line?', text: `${line.designation}, ${line.entry.agency}. It no longer shows on the Service Record.`, confirmText: 'Remove', tone: 'danger' })) {
        router.delete(`${base}/${line.entry.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="`Service Record — ${employee.name}`" />

    <PageHeader
        :title="`Service Record — ${employee.name}`"
        :breadcrumbs="[
            { label: 'Human Resources', href: '/admin/hr' },
            { label: 'Employees', href: '/admin/hr/employees' },
            { label: employee.name, href: `/admin/hr/employees/${employee.id}` },
            { label: 'Service Record' },
        ]"
    >
        <template #actions>
            <Button v-if="canManage" variant="success" @click="openAdd"><Icon name="plus" size="sm" />Add service from another agency</Button>
            <Button :href="printUrl" external>
                <Icon name="printer" size="sm" />Print certified copy
            </Button>
        </template>
    </PageHeader>

    <Card title="Service" icon="clipboard" :description="`Born ${person.date_of_birth ? formatCalendarDate(person.date_of_birth) : '—'}${person.place_of_birth ? ` in ${person.place_of_birth}` : ''}`">
        <ServiceRecordTable :lines="lines">
            <template v-if="canManage" #actions="{ line }">
                <div v-if="line.source === 'entry'" class="flex justify-end gap-1">
                    <ActionButton icon="pencil" :label="`Edit ${line.designation}`" @click="openEdit(line)" />
                    <ActionButton icon="trash" tone="danger" :label="`Remove ${line.designation}`" @click="remove(line)" />
                </div>
            </template>
        </ServiceRecordTable>
        <p class="mt-4 text-xs text-text-muted">
            CSC lines come from the plantilla (appointments, step increments) and break where a reassignment or a new salary schedule takes
            effect; leave without pay comes from approved leave. Service in other agencies is entered by HR.
        </p>
    </Card>

    <RecordModal
        v-if="canManage"
        :open="modalOpen"
        noun="Service Line"
        :record="editing"
        :fields="fields"
        :defaults="defaults"
        :nullable="nullable"
        :store-url="base"
        :update-url="(record) => `${base}/${record.id}`"
        @close="modalOpen = false"
    />
</template>
