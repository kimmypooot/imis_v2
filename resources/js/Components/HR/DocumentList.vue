<script setup>
import { computed, ref, watch } from 'vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import { formatCalendarDate, formatDateTime } from '../../Utils/datetime';

/**
 * 201-file documents in the shared DataTable: searchable and sortable,
 * stacked as cards on phones, with pills to show one part of the file
 * (appointment, personal records, …; the document type's `group`, set on
 * the server). Open and Download go through the authorized, audited file
 * routes. HR's archive/restore buttons come in through the `actions` slot.
 */
const props = defineProps({
    documents: { type: Array, required: true },
    empty: { type: String, default: 'No documents yet.' },
    caption: { type: String, default: '201 file documents' },
});

const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
const kind = (mime) => (mime === 'application/pdf' ? 'PDF' : 'Image');

// The parts of the file that have documents, each with its count.
const group = ref('all');
const groups = computed(() => {
    const counts = new Map();
    for (const document of props.documents) {
        const entry = counts.get(document.group) ?? { value: document.group, label: document.group_label, count: 0 };
        entry.count += 1;
        counts.set(document.group, entry);
    }

    return [{ value: 'all', label: 'All', count: props.documents.length }, ...counts.values()];
});
// A part emptied (e.g. by archiving its last document) falls back to All.
watch(groups, (list) => {
    if (!list.some((option) => option.value === group.value)) group.value = 'all';
});
const shown = computed(() => (group.value === 'all' ? props.documents : props.documents.filter((document) => document.group === group.value)));

const columns = [
    { key: 'document', label: 'Document', value: (row) => [row.type_label, row.title, row.remarks].filter(Boolean).join(' ') },
    { key: 'document_date', label: 'Dated', value: (row) => row.document_date ?? '' },
    { key: 'file', label: 'File', value: (row) => `${kind(row.mime_type)} ${size(row.size)}`, sortable: false },
    { key: 'uploaded_at', label: 'Added', value: (row) => row.uploaded_at },
    { key: 'actions', label: 'Actions', sortable: false, export: false, align: 'right' },
];
</script>

<template>
    <div class="space-y-4">
        <FilterButtons v-if="groups.length > 2" v-model="group" label="Part of the 201 file" variant="pills" :options="groups" />

        <DataTable
            :columns="columns"
            :rows="shown"
            :caption="caption"
            :numbered="false"
            :exportable="false"
            :page-size-select="false"
            :default-page-size="25"
            :empty-text="empty"
            empty-icon="clipboard"
            :row-class="(row) => (row.archived_at ? 'opacity-70' : '')"
        >
            <template #cell-document="{ row }">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-info-bg text-link" aria-hidden="true">
                        <Icon :name="row.mime_type === 'application/pdf' ? 'documentText' : 'camera'" size="sm" />
                    </span>
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-medium text-text">
                            {{ row.type_label }}
                            <StatusBadge v-if="row.archived_at" status="inactive" label="Archived" />
                        </p>
                        <p v-if="row.title" class="text-text">{{ row.title }}</p>
                        <p v-if="row.remarks" class="mt-0.5 text-xs whitespace-pre-line text-text-muted">{{ row.remarks }}</p>
                        <p v-if="row.archived_at" class="mt-0.5 text-xs text-danger-fg">
                            Archived {{ formatDateTime(row.archived_at) }}<template v-if="row.archived_by"> by {{ row.archived_by }}</template>: {{ row.archive_reason }}
                        </p>
                    </div>
                </div>
            </template>
            <template #cell-document_date="{ row }">
                <span class="whitespace-nowrap text-text">{{ row.document_date ? formatCalendarDate(row.document_date) : '—' }}</span>
            </template>
            <template #cell-file="{ row }">
                <span class="whitespace-nowrap text-text-muted">{{ kind(row.mime_type) }} · {{ size(row.size) }}</span>
            </template>
            <template #cell-uploaded_at="{ row }">
                <span class="block whitespace-nowrap text-text">{{ formatDateTime(row.uploaded_at) }}</span>
                <span v-if="row.uploaded_by" class="block text-xs text-text-muted">by {{ row.uploaded_by }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1">
                    <ActionButton icon="eye" tone="neutral" :label="`Open ${row.type_label}`" :href="`/hr/documents/${row.id}`" external />
                    <ActionButton icon="download" tone="primary" :label="`Download ${row.type_label}`" :href="`/hr/documents/${row.id}/download`" external />
                    <slot name="actions" :document="row" />
                </div>
            </template>
        </DataTable>
    </div>
</template>
