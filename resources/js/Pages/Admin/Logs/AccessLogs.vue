<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import LogFilters from '../../../Components/Admin/LogFilters.vue';
import UserAvatar from '../../../Components/UI/UserAvatar.vue';
import { formatDateTime, timeAgo } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "System Access Logs" page (imis_history_access_logs): every
 * time someone opened a system through IMIS, and the role they held then.
 */
const props = defineProps({
    logs: { type: Object, required: true },
    query: { type: Object, required: true },
    applicationOptions: { type: Array, required: true },
});

const columns = [
    { key: 'username', label: 'User', value: (row) => `${row.name ?? ''} ${row.username ?? ''}` },
    { key: 'ip_address', label: 'IP address' },
    { key: 'application_name', label: 'System' },
    { key: 'role_name', label: 'Role', value: (row) => row.role_name ?? 'Guest' },
    { key: 'accessed_at', label: 'Accessed at', value: (row) => formatDateTime(row.accessed_at) },
    { key: 'browser', label: 'User agent', sortable: false, value: (row) => row.user_agent ?? '' },
];
</script>

<template>
    <Head title="System Access Logs" />

    <PageHeader
        title="System Access Logs"
        description="Every time someone opened a system through IMIS, with the role they held at that moment."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'System Access Logs' }]"
    />

    <Card title="System launches" icon="globe">
        <DataTable
            :columns="columns"
            :paginator="logs"
            :query="query"
            url="/admin/access-logs"
            :only="['logs']"
            export-url="/admin/access-logs/export"
            caption="System access logs"
            export-name="imis-system-access-logs"
            :numbered="false"
        >
            <template #toolbar>
                <LogFilters
                    url="/admin/access-logs"
                    :only="['logs']"
                    :query="query"
                    :select="{ key: 'application_id', label: 'All systems', options: props.applicationOptions }"
                />
            </template>
            <template #cell-username="{ row }">
                <div class="flex items-center gap-3">
                    <UserAvatar :name="row.name ?? row.username ?? '?'" :photo-url="row.profile_photo_url" size="sm" />
                    <div class="min-w-0">
                        <div class="truncate font-medium text-text">{{ row.name ?? row.username }}</div>
                        <div v-if="row.name" class="truncate text-xs text-text-muted">@{{ row.username }}</div>
                    </div>
                </div>
            </template>
            <template #cell-accessed_at="{ row }">
                <div>{{ formatDateTime(row.accessed_at) }}</div>
                <div class="text-xs text-text-muted">{{ timeAgo(row.accessed_at) }}</div>
            </template>
            <template #cell-role_name="{ row }">
                <span v-if="row.role_name" class="rounded-full bg-info-bg px-2 py-0.5 text-xs font-medium text-link">{{ row.role_name }}</span>
                <span v-else class="text-text-muted">Guest</span>
            </template>
            <template #cell-browser="{ row }">
                <span :title="row.user_agent">{{ row.browser }}</span>
            </template>
        </DataTable>
    </Card>
</template>
