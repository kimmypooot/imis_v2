<script setup>
import { computed } from 'vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import { formatDateTime } from '../../../Utils/datetime';

/**
 * Each verified Personal Data Sheet in a 201 file, exactly as signed:
 * when it was signed and verified (and by whom, for HR), with View and
 * Print as row actions. `viewUrl` gives a submission's page for the
 * viewer (the employee's own view, or HR's verification page).
 */
const props = defineProps({
    items: { type: Array, required: true },
    viewUrl: { type: Function, required: true },
    showReviewer: { type: Boolean, default: false },
    empty: { type: String, default: 'No verified PDS yet.' },
});

const columns = computed(() => [
    { key: 'signed_at', label: 'Signed', value: (row) => row.signed_at },
    { key: 'reviewed_at', label: 'Verified', value: (row) => row.reviewed_at },
    ...(props.showReviewer ? [{ key: 'reviewer', label: 'Verified by', value: (row) => row.reviewer ?? '' }] : []),
    { key: 'actions', label: 'Actions', sortable: false, export: false, align: 'right' },
]);
</script>

<template>
    <DataTable
        :columns="columns"
        :rows="items"
        caption="Verified PDS history"
        :numbered="false"
        :searchable="false"
        :exportable="false"
        :page-size-select="false"
        :default-page-size="10"
        :empty-text="empty"
        empty-icon="checkCircle"
        density="compact"
    >
        <template #cell-signed_at="{ row }">
            <span class="whitespace-nowrap text-text">{{ formatDateTime(row.signed_at) }}</span>
        </template>
        <template #cell-reviewed_at="{ row }">
            <span class="whitespace-nowrap text-text">{{ formatDateTime(row.reviewed_at) }}</span>
        </template>
        <template #cell-actions="{ row }">
            <div class="flex justify-end gap-1">
                <ActionButton icon="eye" tone="neutral" :label="`View the PDS signed ${formatDateTime(row.signed_at)}`" :href="viewUrl(row)" />
                <ActionButton icon="printer" tone="primary" :label="`Print the PDS signed ${formatDateTime(row.signed_at)}`" :href="`/pds/submissions/${row.id}/print`" external />
            </div>
        </template>
        <template v-if="$slots.empty" #empty>
            <slot name="empty" />
        </template>
    </DataTable>
</template>
