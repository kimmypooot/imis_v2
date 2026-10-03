<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Alert from '../../../../Components/Feedback/Alert.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import PdsEditor from '../../../../Components/HR/Pds/PdsEditor.vue';
import PdsStatusCard from '../../../../Components/HR/Pds/PdsStatusCard.vue';
import PdsPhotoCard from '../../../../Components/HR/Pds/PdsPhotoCard.vue';
import PdsHistory from '../../../../Components/HR/Pds/PdsHistory.vue';

defineOptions({ layout: AdminLayout });

/**
 * HR-2: an employee's PDS for HR — read-only with `pds.view`, editable
 * on their behalf with `pds.update` — with its verification status and
 * signed history. Opening it was logged.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    schema: { type: Object, required: true },
    pds: { type: Object, required: true },
    canEdit: { type: Boolean, required: true },
    status: { type: Object, required: true },
    history: { type: Array, required: true },
    photoUrl: { type: String, default: null },
});

const base = computed(() => `/admin/hr/employees/${props.employee.id}/pds`);

const breadcrumbs = computed(() => [
    { label: 'Home', href: '/admin' },
    { label: 'Employees', href: '/admin/hr/employees' },
    { label: props.employee.name, href: `/admin/hr/employees/${props.employee.id}` },
    { label: 'Personal Data Sheet' },
]);
</script>

<template>
    <Head :title="`PDS — ${employee.name}`" />

    <PageHeader :title="`Personal Data Sheet — ${employee.name}`" :breadcrumbs="breadcrumbs" />

    <Alert tone="info" class="mb-6">
        Sensitive personal information under the Data Privacy Act. Your access to this PDS has been logged.
        <template v-if="canEdit"> Changes you make are saved on the employee's behalf and logged under your name.</template>
    </Alert>

    <div class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-3">
            <PdsStatusCard :status="status" for-hr class="lg:col-span-2">
                <a
                    :href="`${base}/print`"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                >
                    <Icon name="printer" size="sm" />Print current<span class="sr-only"> (opens in a new tab)</span>
                </a>
            </PdsStatusCard>
            <PdsPhotoCard :photo-url="photoUrl" :url="`${base}/photo`" :readonly="!canEdit" />
        </div>

        <PdsEditor :schema="schema" :pds="pds" :url="base" :readonly="!canEdit" />

        <PdsHistory :history="history" :view-url="(item) => `/admin/hr/pds-verifications/${item.id}`" />
    </div>
</template>
