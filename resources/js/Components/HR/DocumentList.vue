<script setup>
import Icon from '../UI/Icon.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import { formatCalendarDate, formatDateTime } from '../../Utils/datetime';

/**
 * 201-file documents: open in a new tab or download (both go through
 * the authorized, audited file routes). HR's archive/restore buttons
 * come in through the `actions` slot.
 */
defineProps({
    documents: { type: Array, required: true },
    empty: { type: String, default: 'No documents yet.' },
});

const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
const kind = (mime) => (mime === 'application/pdf' ? 'PDF' : 'Image');
</script>

<template>
    <p v-if="documents.length === 0" class="rounded-md bg-surface-muted px-4 py-6 text-center text-sm text-text-muted">{{ empty }}</p>
    <ul v-else class="divide-y divide-border">
        <li v-for="document in documents" :key="document.id" class="flex flex-wrap items-start gap-3 py-3 first:pt-0 last:pb-0" :class="{ 'opacity-70': document.archived_at }">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                <Icon :name="document.mime_type === 'application/pdf' ? 'clipboard' : 'camera'" size="sm" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-text">
                    {{ document.type_label }}
                    <StatusBadge v-if="document.archived_at" status="inactive" label="Archived" />
                </p>
                <p v-if="document.title" class="text-sm text-text">{{ document.title }}</p>
                <p class="text-xs text-text-muted">
                    <template v-if="document.document_date">Dated {{ formatCalendarDate(document.document_date) }} · </template>
                    {{ kind(document.mime_type) }}, {{ size(document.size) }} · Added {{ formatDateTime(document.uploaded_at) }}<template v-if="document.uploaded_by"> by {{ document.uploaded_by }}</template>
                </p>
                <p v-if="document.remarks" class="mt-0.5 text-xs whitespace-pre-line text-text-muted">{{ document.remarks }}</p>
                <p v-if="document.archived_at" class="mt-0.5 text-xs text-danger">
                    Archived {{ formatDateTime(document.archived_at) }}<template v-if="document.archived_by"> by {{ document.archived_by }}</template>: {{ document.archive_reason }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3 text-sm font-medium">
                <a :href="`/hr/documents/${document.id}`" target="_blank" rel="noopener" class="text-primary hover:underline">
                    Open<span class="sr-only"> {{ document.type_label }} (opens in a new tab)</span>
                </a>
                <a :href="`/hr/documents/${document.id}/download`" class="text-primary hover:underline">
                    Download<span class="sr-only"> {{ document.type_label }}</span>
                </a>
                <slot name="actions" :document="document" />
            </div>
        </li>
    </ul>
</template>
