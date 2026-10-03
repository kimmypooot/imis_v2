<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Alert from '../../../../Components/Feedback/Alert.vue';
import Button from '../../../../Components/UI/Button.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import LdLog from '../../../../Components/HR/LdLog.vue';
import LdRecordModal from '../../../../Components/HR/LdRecordModal.vue';
import LdRejectModal from '../../../../Components/HR/LdRejectModal.vue';
import { confirm } from '../../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * HR-3b: one employee's L&D log for HRD — the employee's own year view,
 * plus adding (approved at once), editing and removing on their behalf,
 * and approving or rejecting their pending entries.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    records: { type: Array, required: true },
    stats: { type: Object, required: true },
    canAdd: { type: Boolean, required: true },
    typeOptions: { type: Array, required: true },
    modalityOptions: { type: Array, required: true },
    certificateKilobytes: { type: Number, required: true },
});

const base = computed(() => `/admin/hr/employees/${props.employee.id}/learning-development`);

const changeYear = (value) => router.get(base.value, { year: value }, { preserveScroll: true });

const modalOpen = ref(false);
const editing = ref(null);
const rejecting = ref(null);

const openAdd = () => {
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (record) => {
    editing.value = record;
    modalOpen.value = true;
};

const remove = async (record) => {
    const confirmed = await confirm({
        title: 'Remove this training?',
        text: `"${record.title}" will leave the L&D log and the PDS of ${props.employee.name}.`,
        tone: 'danger',
        confirmText: 'Remove',
    });
    if (confirmed) router.delete(`${base.value}/${record.id}`, { preserveScroll: true });
};

const approve = (record) => router.post(`/admin/hr/learning-development/reviews/${record.id}/approve`, {}, { preserveScroll: true });

const breadcrumbs = computed(() => [
    { label: 'Home', href: '/admin' },
    { label: 'Learning & Development', href: '/admin/hr/learning-development' },
    // No link to the employee record: L&D officers may not hold employees.view.
    { label: props.employee.name },
]);
</script>

<template>
    <Head :title="`L&D — ${employee.name}`" />

    <PageHeader
        :title="`L&D — ${employee.name}`"
        :description="[employee.position, employee.unit].filter(Boolean).join(' · ') || null"
        :breadcrumbs="breadcrumbs"
    >
        <template #actions>
            <label class="sr-only" for="ld-year">Year</label>
            <select
                id="ld-year"
                :value="year"
                class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                @change="changeYear($event.target.value)"
            >
                <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
            </select>
            <Button v-if="canAdd" variant="success" @click="openAdd"><Icon name="plus" size="sm" />Add training</Button>
        </template>
    </PageHeader>

    <Alert tone="info" class="mb-6">
        Personal records under the Data Privacy Act: opening this log or a certificate is logged. Trainings you add are approved at once.
    </Alert>

    <LdLog
        :year="year"
        :records="records"
        :stats="stats"
        :type-options="typeOptions"
        :modality-options="modalityOptions"
        :caption="`L&D trainings of ${employee.name}, ${year}`"
        :export-name="`ld-${employee.name}-${year}`"
        pending-note="waiting for review"
    >
        <template #actions="{ row }">
            <template v-if="row.can.review">
                <ActionButton icon="check" tone="success" :label="`Approve ${row.title}`" @click="approve(row)" />
                <ActionButton v-if="row.approval_status === 'pending'" icon="xCircle" tone="danger" :label="`Reject ${row.title}`" @click="rejecting = row" />
            </template>
            <ActionButton v-if="row.can.update" icon="pencil" :label="`Edit ${row.title}`" @click="openEdit(row)" />
            <ActionButton v-if="row.can.delete" icon="trash" tone="danger" :label="`Remove ${row.title}`" @click="remove(row)" />
        </template>
    </LdLog>

    <LdRecordModal
        :open="modalOpen"
        :record="editing"
        :url="base"
        :type-options="typeOptions"
        :modality-options="modalityOptions"
        :certificate-kilobytes="certificateKilobytes"
        on-behalf
        @close="modalOpen = false"
    />

    <LdRejectModal :record="rejecting" @close="rejecting = null" />
</template>
