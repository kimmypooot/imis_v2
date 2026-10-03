<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import AdminCard from '../../Components/UI/AdminCard.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Icon from '../../Components/UI/Icon.vue';
import PdsEditor from '../../Components/HR/Pds/PdsEditor.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * One of your past PDS submissions, exactly as you signed it (read-only).
 */
defineProps({
    schema: { type: Object, required: true },
    submission: { type: Object, required: true },
});

const badge = { submitted: 'info', verified: 'active', returned: 'inactive', superseded: 'draft' };
</script>

<template>
    <Head title="PDS submission" />

    <PageHeader
        :title="`PDS signed ${formatDateTime(submission.signed_at)}`"
        :breadcrumbs="[{ label: 'My Personal Data Sheet', href: '/my/pds' }, { label: 'Submission' }]"
    />

    <div class="space-y-6">
        <AdminCard>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <img v-if="submission.photo_url" :src="submission.photo_url" alt="PDS photo as signed" class="h-28 w-22 rounded border border-border object-cover" />
                    <div class="space-y-1">
                        <StatusBadge :status="badge[submission.status]" :label="submission.status_label" />
                        <p v-if="submission.reviewed_at" class="text-sm text-text-muted">
                            {{ submission.status === 'verified' ? 'Verified' : 'Returned' }} by {{ submission.reviewer }} on {{ formatDateTime(submission.reviewed_at) }}
                        </p>
                        <p v-if="submission.remarks" class="text-sm whitespace-pre-line text-text">Remarks: {{ submission.remarks }}</p>
                    </div>
                </div>
                <a
                    :href="`/pds/submissions/${submission.id}/print`"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                >
                    <Icon name="printer" size="sm" />Print<span class="sr-only"> (opens in a new tab)</span>
                </a>
            </div>
        </AdminCard>

        <PdsEditor :schema="schema" :pds="submission.pds" readonly />
    </div>
</template>
