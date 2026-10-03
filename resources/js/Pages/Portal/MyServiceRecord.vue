<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import AdminCard from '../../Components/UI/AdminCard.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Icon from '../../Components/UI/Icon.vue';
import ServiceRecordTable from '../../Components/HR/ServiceRecordTable.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-5 self-service: your own Service Record, read-only. Your printout is
 * a copy for reference; ask HR for a certified one.
 */
defineProps({
    person: { type: Object, default: null },
    lines: { type: Array, required: true },
    hasRecord: { type: Boolean, required: true },
});
</script>

<template>
    <Head title="My Service Record" />

    <PageHeader
        title="My Service Record"
        description="Your service in this office as HR keeps it. For a certified copy, ask the HR office."
        :breadcrumbs="[{ label: 'My Service Record' }]"
    >
        <template v-if="hasRecord && lines.length" #actions>
            <a
                href="/my/service-record/print"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                <Icon name="printer" size="sm" />Print a copy
            </a>
        </template>
    </PageHeader>

    <AdminCard v-if="hasRecord" title="Service" icon="clipboard">
        <ServiceRecordTable :lines="lines" />
    </AdminCard>
    <EmptyState
        v-else
        icon="clipboard"
        title="No employee record yet"
        description="HR hasn't set up your employee record. Contact the HR office if you think this is a mistake."
    />
</template>
