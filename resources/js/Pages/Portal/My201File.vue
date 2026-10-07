<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import DocumentList from '../../Components/HR/DocumentList.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * HR-2c self-service: your own 201 file, read-only — the documents HR
 * keeps for you and your verified PDS history.
 */
defineProps({
    hasRecord: { type: Boolean, required: true },
    documents: { type: Array, required: true },
    pdsHistory: { type: Array, required: true },
});
</script>

<template>
    <Head title="My 201 File" />

    <PageHeader
        title="My 201 File"
        description="The documents HR keeps in your personnel file. To add or correct one, send it to the HR office."
        :breadcrumbs="[{ label: 'My 201 File' }]"
    />

    <div v-if="hasRecord" class="space-y-6">
        <Card title="Documents" icon="clipboard">
            <DocumentList :documents="documents" empty="HR hasn't added documents to your 201 file yet." />
        </Card>

        <Card title="Verified PDS history" icon="checkCircle">
            <p v-if="pdsHistory.length === 0" class="text-sm text-text-muted">
                None yet. <Link href="/my/pds" class="font-medium text-primary hover:underline">Sign and submit your PDS</Link> for HR to verify.
            </p>
            <ul v-else class="divide-y divide-border text-sm">
                <li v-for="item in pdsHistory" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <span class="text-text">Signed {{ formatDateTime(item.signed_at) }} · verified {{ formatDateTime(item.reviewed_at) }}</span>
                    <span class="flex gap-3 font-medium">
                        <Link :href="`/my/pds/submissions/${item.id}`" class="text-primary hover:underline">View</Link>
                        <a :href="`/pds/submissions/${item.id}/print`" target="_blank" rel="noopener" class="text-primary hover:underline">
                            Print<span class="sr-only"> (opens in a new tab)</span>
                        </a>
                    </span>
                </li>
            </ul>
        </Card>
    </div>
    <EmptyState v-else icon="clipboard" title="Your employee record isn't set up yet" description="Contact the HR office." />
</template>
