<script setup>
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import LogFilters from '../../../Components/Admin/LogFilters.vue';
import { formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Security Audit Logs" page: failed and blocked sign-ins,
 * lockouts, and forced sign-outs, with the IPs failing most often.
 */
defineProps({
    events: { type: Object, required: true },
    topIps: { type: Array, required: true },
    query: { type: Object, required: true },
});

const ipColumns = [
    { key: 'ip_address', label: 'IP address' },
    { key: 'attempts', label: 'Attempts', align: 'right' },
];
const columns = [
    { key: 'event', label: 'Event', value: (row) => row.event_label },
    { key: 'severity', label: 'Severity', sortable: false, align: 'center' },
    { key: 'user', label: 'User', sortable: false, value: (row) => row.actor_label ?? row.target_label ?? '' },
    { key: 'ip_address', label: 'IP address' },
    { key: 'description', label: 'Details', sortable: false },
    { key: 'created_at', label: 'Timestamp', value: (row) => formatDateTime(row.created_at) },
];
</script>

<template>
    <Head title="Security Logs" />

    <PageHeader title="Security Audit Logs" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Security Logs' }]" />

    <div class="grid gap-6 xl:grid-cols-3">
        <Card title="Top failing IPs" description="Failed sign-ins in the last 7 days" icon="shield" class="xl:col-span-1">
            <DataTable :columns="ipColumns" :rows="topIps" row-key="ip_address" caption="IP addresses with the most failed sign-ins in the last 7 days" export-name="imis-failing-ips" :numbered="false" :searchable="false" :exportable="false" empty-text="No failed sign-ins this week.">
                <template #cell-ip_address="{ row }"><span class="font-mono text-xs">{{ row.ip_address }}</span></template>
                <template #cell-attempts="{ row }">
                    <Badge pill :tone="row.attempts >= 5 ? 'danger' : 'warning'">{{ row.attempts }}</Badge>
                </template>
            </DataTable>
        </Card>

        <Card title="All security events" icon="exclamation" class="xl:col-span-2">
            <DataTable
                :columns="columns"
                :paginator="events"
                :query="query"
                url="/admin/security-logs"
                :only="['events']"
                export-url="/admin/security-logs/export"
                caption="Security events"
                export-name="imis-security-logs"
                :numbered="false"
            >
                <template #toolbar>
                    <LogFilters url="/admin/security-logs" :only="['events']" :query="query" />
                </template>
                <template #cell-severity="{ row }">
                    <StatusBadge :status="row.severity" />
                </template>
                <template #cell-description="{ row }">
                    <span class="line-clamp-2 max-w-xs text-text-muted">{{ row.description }}</span>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
