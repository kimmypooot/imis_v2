<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Button from '../../Components/UI/Button.vue';
import ActionButton from '../../Components/UI/ActionButton.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Icon from '../../Components/UI/Icon.vue';
import LdLog from '../../Components/HR/LdLog.vue';
import LdRecordModal from '../../Components/HR/LdRecordModal.vue';
import { confirm } from '../../Composables/useDialog';

defineOptions({ layout: PortalLayout });

/**
 * HR-3a: your Learning & Development log (ldms's "My L&D Interventions").
 * One year at a time: summary cards, month tabs, filters, and every
 * training — added here, reviewed by HRD, shown on your PDS.
 */
defineProps({
    employee: { type: Object, default: null },
    year: { type: Number, default: null },
    years: { type: Array, default: () => [] },
    records: { type: Array, default: () => [] },
    stats: { type: Object, default: null },
    typeOptions: { type: Array, default: () => [] },
    modalityOptions: { type: Array, default: () => [] },
    certificateKilobytes: { type: Number, default: 10240 },
});

const changeYear = (value) => router.get('/my/learning-development', { year: value }, { preserveScroll: true });

const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => {
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (record) => {
    editing.value = record;
    modalOpen.value = true;
};

const remove = async (record) => {
    const confirmed = await confirm({ title: 'Remove this training?', text: `"${record.title}" will leave your L&D log and your PDS.`, tone: 'danger', confirmText: 'Remove' });
    if (confirmed) router.delete(`/my/learning-development/${record.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="My Learning & Development" />

    <PageHeader
        title="My Learning & Development"
        :description="employee ? [employee.name, employee.position, employee.unit].filter(Boolean).join(' · ') : null"
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Learning & Development' }]"
    >
        <template v-if="employee" #actions>
            <label class="sr-only" for="ld-year">Year</label>
            <select
                id="ld-year"
                :value="year"
                class="form-control px-3 py-2"
                @change="changeYear($event.target.value)"
            >
                <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
            </select>
            <Button variant="success" @click="openAdd"><Icon name="plus" size="sm" />Add training</Button>
        </template>
    </PageHeader>

    <EmptyState v-if="!employee" icon="clipboard" title="Your employee record isn't set up yet" description="Contact the HR office." />

    <LdLog
        v-else
        :year="year"
        :records="records"
        :stats="stats"
        :type-options="typeOptions"
        :modality-options="modalityOptions"
        :caption="`My L&D trainings, ${year}`"
        :export-name="`my-ld-${year}`"
    >
        <template #actions="{ row }">
            <ActionButton v-if="row.can.update" icon="pencil" :label="`Edit ${row.title}`" @click="openEdit(row)" />
            <ActionButton v-if="row.can.delete" icon="trash" tone="danger" :label="`Remove ${row.title}`" @click="remove(row)" />
            <span v-if="!row.can.update" class="text-xs text-text-muted" title="Approved by HRD — contact HRD to change it">Locked</span>
        </template>
    </LdLog>

    <LdRecordModal
        v-if="employee"
        :open="modalOpen"
        :record="editing"
        url="/my/learning-development"
        :type-options="typeOptions"
        :modality-options="modalityOptions"
        :certificate-kilobytes="certificateKilobytes"
        @close="modalOpen = false"
    />
</template>
