<script setup>
import { Link } from '@inertiajs/vue3';
import Card from '../../UI/Card.vue';
import StatusBadge from '../../UI/StatusBadge.vue';
import { formatDateTime } from '../../../Utils/datetime';

/**
 * Every signed submission, newest first — the verified ones are the PDS
 * history kept in the 201 file. `viewUrl` picks where "View" goes
 * (the employee's own page, or HR's review page).
 */
defineProps({
    history: { type: Array, required: true },
    viewUrl: { type: Function, required: true /** (submission) => url */ },
});

const badge = { submitted: 'info', verified: 'active', returned: 'inactive', superseded: 'draft' };
</script>

<template>
    <Card title="Submissions" icon="clock" description="Each one is an exact copy of the PDS as it was signed.">
        <p v-if="history.length === 0" class="text-sm text-text-muted">No submissions yet.</p>
        <ul v-else class="divide-y divide-border">
            <li v-for="item in history" :key="item.id" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <div class="min-w-0 space-y-0.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge :status="badge[item.status]" :label="item.status_label" />
                        <span class="text-sm text-text">Signed {{ formatDateTime(item.signed_at) }}</span>
                    </div>
                    <p v-if="item.reviewed_at" class="text-xs text-text-muted">
                        {{ item.status === 'verified' ? 'Verified' : 'Returned' }} by {{ item.reviewer }} on {{ formatDateTime(item.reviewed_at) }}
                    </p>
                    <p v-if="item.remarks" class="text-xs whitespace-pre-line text-text-muted">Remarks: {{ item.remarks }}</p>
                </div>
                <div class="flex shrink-0 gap-3 text-sm font-medium">
                    <Link :href="viewUrl(item)" class="text-link hover:underline">View</Link>
                    <a :href="`/pds/submissions/${item.id}/print`" target="_blank" rel="noopener" class="text-link hover:underline">
                        Print<span class="sr-only"> (opens in a new tab)</span>
                    </a>
                </div>
            </li>
        </ul>
    </Card>
</template>
