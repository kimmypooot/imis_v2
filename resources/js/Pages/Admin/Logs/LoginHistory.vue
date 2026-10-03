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
 * The legacy "Login History" page (imis_history_login_logs): every sign-in,
 * newest first, with how and when it ended.
 */
defineProps({
    logins: { type: Object, required: true },
    query: { type: Object, required: true },
});

const statusLabels = { active: 'Active', logged_out: 'Logged out', expired: 'Expired', ended: 'Ended by admin' };
const statusTones = { active: 'active', logged_out: 'logged_out', expired: 'expired', ended: 'inactive' };

const columns = [
    { key: 'username', label: 'Username' },
    { key: 'ip_address', label: 'IP address' },
    { key: 'logged_in_at', label: 'Login time', value: (row) => formatDateTime(row.logged_in_at) },
    { key: 'logged_out_at', label: 'Logout time', value: (row) => (row.logged_out_at ? formatDateTime(row.logged_out_at) : '') },
    { key: 'status', label: 'Status', sortable: false, align: 'center', value: (row) => statusLabels[row.status] },
    { key: 'browser', label: 'User agent', sortable: false, value: (row) => row.user_agent ?? '' },
];
</script>

<template>
    <Head title="Login History" />

    <PageHeader title="Login History" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Login History' }]" />

    <AdminCard title="Sign-ins" icon="clock">
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
            <template #cell-status="{ row }">
                <StatusBadge :status="statusTones[row.status]" :label="statusLabels[row.status]" />
            </template>
            <template #cell-browser="{ row }">
                <span :title="row.user_agent">{{ row.browser }}</span>
            </template>
        </DataTable>
    </AdminCard>
</template>
