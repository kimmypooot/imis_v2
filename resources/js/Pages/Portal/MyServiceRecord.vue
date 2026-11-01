<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Icon from '../../Components/UI/Icon.vue';
import RecordSummary from '../../Components/HR/RecordSummary.vue';
import { formatCalendarDate } from '../../Utils/datetime';
import ServiceRecordTable from '../../Components/HR/ServiceRecordTable.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-5 self-service: your own Service Record, read-only. Your printout is
 * a copy for reference; ask HR for a certified one.
 */
const props = defineProps({
    person: { type: Object, default: null },
    lines: { type: Array, required: true },
    hasRecord: { type: Boolean, required: true },
});

// Service since the earliest start; the present post is the line with no end date (or the last one).
const summary = computed(() => {
    const lines = props.lines;
    const starts = lines.map((line) => line.date_from).filter(Boolean).sort();
    const present = lines.find((line) => !line.date_to) ?? lines.at(-1);

    return [
        { icon: 'clipboard', label: 'Service entries', value: String(lines.length) },
        { icon: 'calendar', label: 'In service since', value: starts[0] ? formatCalendarDate(starts[0]) : null },
        { icon: 'user', label: 'Present post', value: present?.designation },
    ];
});
</script>

<template>
    <Head title="My Service Record" />

    <PageHeader
        title="My Service Record"
        description="Your service in this office as HR keeps it. For a certified copy, ask the HR office."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Service Record' }]"
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

    <div v-if="hasRecord" class="space-y-6">
        <RecordSummary v-if="lines.length" :items="summary" class="lg:grid-cols-3" />
        <Card title="Service" icon="clipboard">
            <ServiceRecordTable :lines="lines" />
        </Card>
    </div>
    <Card v-else>
        <EmptyState
            icon="clipboard"
            title="No employee record yet"
            description="HR hasn't set up your employee record. Contact the HR office if you think this is a mistake."
        />
    </Card>
</template>
