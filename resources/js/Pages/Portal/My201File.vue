<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import StatTile from '@cscro8/ui/Components/UI/StatTile.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import DocumentList from '../../Components/HR/DocumentList.vue';
import PdsVerifiedHistory from '../../Components/HR/Pds/PdsVerifiedHistory.vue';
import { formatCalendarDate } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * HR-2c self-service: your own 201 file, read-only — the documents HR
 * keeps for you and your verified PDS history, on the shared kit (stat
 * tiles, the DataTable with filter pills, icon row actions).
 */
const props = defineProps({
    hasRecord: { type: Boolean, required: true },
    documents: { type: Array, required: true },
    pdsHistory: { type: Array, required: true },
});

const lastVerified = computed(() => props.pdsHistory[0]?.reviewed_at ?? null);
const groups = computed(() => new Set(props.documents.map((document) => document.group)).size);
</script>

<template>
    <Head title="My 201 File" />

    <PageHeader
        title="My 201 File"
        description="The documents HR keeps in your personnel file. To add or correct one, send it to the HR office."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My 201 File' }]"
    />

    <div v-if="hasRecord" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <StatTile label="Documents on file" :value="documents.length" icon="clipboard" tone="primary" />
            <StatTile label="Parts of the file" :value="groups" icon="documentText" tone="info" />
            <StatTile
                label="Last PDS verified"
                :value="lastVerified ? formatCalendarDate(lastVerified.slice(0, 10)) : 'Not yet'"
                icon="checkCircle"
                :tone="lastVerified ? 'success' : 'warning'"
            />
        </div>

        <Alert tone="info">
            Your 201 file is personal data under the Data Privacy Act. Only you and authorized HR staff can open it, and each time someone other
            than you opens or downloads a document, it is logged. See who did at
            <Link href="/my/record-access" class="font-medium text-link hover:underline">Who accessed my record</Link>.
        </Alert>

        <Card title="Documents" icon="clipboard" description="Open a document to view it, or download a copy.">
            <DocumentList :documents="documents" caption="My 201 file documents" empty="HR hasn't added documents to your 201 file yet." />
        </Card>

        <Card title="Verified PDS history" icon="checkCircle" description="Each Personal Data Sheet HR verified, exactly as you signed it.">
            <PdsVerifiedHistory :items="pdsHistory" :view-url="(item) => `/my/pds/submissions/${item.id}`">
                <template #empty>
                    <EmptyState icon="checkCircle" title="No verified PDS yet" description="Sign and submit your PDS for HR to verify; each verified copy is kept here.">
                        <template #action>
                            <Link href="/my/pds" class="font-medium text-link hover:underline">Go to My Personal Data Sheet</Link>
                        </template>
                    </EmptyState>
                </template>
            </PdsVerifiedHistory>
        </Card>
    </div>
    <Card v-else>
        <EmptyState icon="clipboard" title="Your employee record isn't set up yet" description="Contact the HR office." />
    </Card>
</template>
