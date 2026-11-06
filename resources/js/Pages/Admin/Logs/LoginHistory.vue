<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
import LogFilters from '../../../Components/Admin/LogFilters.vue';
import UserAvatar from '../../../Components/UI/UserAvatar.vue';
import { formatDateTime, timeAgo } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Login History" page (imis_history_login_logs): every sign-in,
 * newest first, with how and when it ended.
 */
defineProps({
    logins: { type: Object, required: true },
    query: { type: Object, required: true },
});

const statusLabels = { active: 'Active', logged_out: 'Logged Out', expired: 'Expired', ended: 'Ended by Admin' };
const statusTones = { active: 'active', logged_out: 'logged_out', expired: 'expired', ended: 'inactive' };

// How long a finished sign-in lasted, e.g. "2h 5m".
const duration = (row) => {
    if (!row.logged_out_at) return '';
    const minutes = Math.max(0, Math.round((new Date(row.logged_out_at) - new Date(row.logged_in_at)) / 60000));

    if (minutes < 1) return '< 1m';

    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h${minutes % 60 ? ` ${minutes % 60}m` : ''}`;
};

const columns = [
    { key: 'username', label: 'User', value: (row) => `${row.name ?? ''} ${row.username ?? ''}` },
    { key: 'ip_address', label: 'IP address' },
    { key: 'logged_in_at', label: 'Login time', value: (row) => formatDateTime(row.logged_in_at) },
    { key: 'duration', label: 'Duration', sortable: false, value: duration },
    { key: 'logged_out_at', label: 'Logout time', value: (row) => (row.logged_out_at ? formatDateTime(row.logged_out_at) : '') },
    { key: 'status', label: 'Status', sortable: false, align: 'center', value: (row) => statusLabels[row.status] },
    { key: 'browser', label: 'User agent', sortable: false, value: (row) => row.user_agent ?? '' },
];
</script>

<template>
    <Head title="Login History" />

    <PageHeader title="Login History" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Login History' }]" />

    <Card title="Sign-ins" icon="clock">
        <DataTable
            :columns="columns"
            :paginator="logins"
            :query="query"
            url="/admin/login-history"
            :only="['logins']"
            export-url="/admin/login-history/export"
            caption="Login history"
            export-name="imis-login-history"
            :numbered="false"
        >
            <template #toolbar>
                <LogFilters url="/admin/login-history" :only="['logins']" :query="query" />
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
            <template #cell-logged_in_at="{ row }">
                <div>{{ formatDateTime(row.logged_in_at) }}</div>
                <div class="text-xs text-text-muted">{{ timeAgo(row.logged_in_at) }}</div>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :status="statusTones[row.status]" :label="statusLabels[row.status]" />
            </template>
            <template #cell-browser="{ row }">
                <span :title="row.user_agent">{{ row.browser }}</span>
            </template>
        </DataTable>
    </Card>
</template>
