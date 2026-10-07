<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import EmployeeDetails from '../../../../Components/HR/EmployeeDetails.vue';
import AssignmentTimeline from '../../../../Components/HR/AssignmentTimeline.vue';
import AssignmentModal from '../../../../Components/HR/AssignmentModal.vue';
import PlantillaHistory from '../../../../Components/HR/PlantillaHistory.vue';
import { employeeDefaults, employeeFields, employeeNullable } from '../../../../Components/HR/employeeFields';
import { confirm } from '../../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * HR-1: one employee record. Opening it was logged (unless it's your
 * own). Editing is HR's (employees.update), and never your own record.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    employmentStatusOptions: { type: Array, required: true },
    assignments: { type: Object, required: true },
    // HR-4: plantilla periods; null when the viewer may not see salaries.
    plantilla: { type: Array, default: null },
    // HR-1b: options for recording a movement; null without employees.reassign.
    assignmentForm: { type: Object, default: null },
});

const editOpen = ref(false);

// HR-1b: record, correct or remove a movement.
const assignmentsUrl = computed(() => `/admin/hr/employees/${props.employee.id}/assignments`);
const assignmentOpen = ref(false);
const editingAssignment = ref(null);
const openAssignment = (entry = null) => {
    editingAssignment.value = entry;
    assignmentOpen.value = true;
};
const removeAssignment = async (entry) => {
    const text = entry.moves_office
        ? 'If this reassignment is the one that moved them, their office goes back to where it moved them from.'
        : 'It is removed from the history; their office is not affected.';
    if (await confirm({ title: `Remove this ${entry.kind_label.toLowerCase()}?`, text, tone: 'danger', confirmText: 'Remove' })) {
        router.delete(`${assignmentsUrl.value}/${entry.id}`, { preserveScroll: true });
    }
};
const breadcrumbs = computed(() => [
    { label: 'Human Resources', href: '/admin/hr' },
    { label: 'Employees', href: '/admin/hr/employees' },
    { label: props.employee.user.name },
]);
</script>

<template>
    <Head :title="employee.user.name" />

    <PageHeader :title="employee.user.name" :breadcrumbs="breadcrumbs">
        <template v-if="employee.can.update || employee.can.viewPds || employee.can.viewDocuments || employee.can.viewLd || employee.can.viewServiceRecord || employee.can.viewLeave" #actions>
            <Link
                v-if="employee.can.viewLeave"
                :href="`/admin/hr/employees/${employee.id}/leave`"
                class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                <Icon name="calendar" size="sm" />Leave
            </Link>
            <Link
                v-if="employee.can.viewServiceRecord"
                :href="`/admin/hr/employees/${employee.id}/service-record`"
                class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                <Icon name="printer" size="sm" />Service Record
            </Link>
            <Link
                v-if="employee.can.viewLd"
                :href="`/admin/hr/employees/${employee.id}/learning-development`"
                class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                <Icon name="chartBar" size="sm" />L&amp;D
            </Link>
            <Link
                v-if="employee.can.viewDocuments"
                :href="`/admin/hr/employees/${employee.id}/documents`"
                class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                <Icon name="database" size="sm" />201 File
            </Link>
            <Link
                v-if="employee.can.viewPds"
                :href="`/admin/hr/employees/${employee.id}/pds`"
                class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                <Icon name="clipboard" size="sm" />Personal Data Sheet
            </Link>
            <Button v-if="employee.can.update" @click="editOpen = true"><Icon name="pencil" size="sm" />Edit employment</Button>
        </template>
    </PageHeader>

    <EmployeeDetails :employee="employee" />

    <PlantillaHistory v-if="plantilla" class="mt-6" :periods="plantilla" link-items />

    <AssignmentTimeline
        class="mt-6"
        :assignments="assignments"
        :can-manage="!!assignmentForm"
        @edit="openAssignment"
        @remove="removeAssignment"
    >
        <template v-if="assignmentForm" #actions>
            <Button variant="success" @click="openAssignment()"><Icon name="plus" size="sm" />Record movement</Button>
        </template>
    </AssignmentTimeline>

    <AssignmentModal
        v-if="assignmentForm"
        :open="assignmentOpen"
        :entry="editingAssignment"
        :url="assignmentsUrl"
        :current-office-id="employee.user.unit_id"
        :kind-options="assignmentForm.kindOptions"
        :capacity-options="assignmentForm.capacityOptions"
        :office-options="assignmentForm.officeOptions"
        :scan-kilobytes="assignmentForm.scanKilobytes"
        @close="assignmentOpen = false"
    />

    <RecordModal
        :open="editOpen"
        noun="Employment"
        :record="employee"
        :fields="employeeFields(employmentStatusOptions)"
        :defaults="employeeDefaults"
        :nullable="employeeNullable"
        store-url="/admin/hr/employees"
        :update-url="(record) => `/admin/hr/employees/${record.id}`"
        @close="editOpen = false"
    />
</template>
