<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../Components/UI/AdminCard.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
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
        <AdminCard title="Top failing IPs" subtitle="Failed sign-ins in the last 7 days" icon="shield" class="xl:col-span-1">
            <table class="w-full text-[0.8125rem]">
                <caption class="sr-only">IP addresses with the most failed sign-ins in the last 7 days</caption>
                <thead class="bg-surface-muted text-left text-xs font-semibold text-text-muted uppercase">
                    <tr>
                        <th scope="col" class="px-3 py-2">IP address</th>
                        <th scope="col" class="px-3 py-2 text-right">Attempts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-if="!topIps.length">
                        <td colspan="2" class="px-3 py-6 text-center text-text-muted">No failed sign-ins this week.</td>
                    </tr>
                    <tr v-for="row in topIps" :key="row.ip_address">
                        <td class="px-3 py-2 font-mono text-xs">{{ row.ip_address }}</td>
                        <td class="px-3 py-2 text-right">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="row.attempts >= 5 ? 'bg-danger-bg text-danger' : 'bg-warning-bg text-warning'">
                                {{ row.attempts }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </AdminCard>

        <AdminCard title="All security events" icon="exclamation" class="xl:col-span-2">
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
        </AdminCard>
    </div>
</template>
