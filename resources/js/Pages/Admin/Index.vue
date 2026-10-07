<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import DashboardBanner from '../../Components/Admin/DashboardBanner.vue';
import SystemDashboard from '../../Components/Admin/SystemDashboard.vue';
import { useAuthorization } from '../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

/**
 * The system administration dashboard, as the legacy IMIS admin home:
 * welcome banner with quick actions, then SystemDashboard. Every section
 * is permission-scoped on the server. Human Resources has its own
 * dashboard (Admin/HR/Dashboard).
 */
defineProps({
    attention: { type: Array, required: true },
    dashboard: { type: Object, required: true },
});

const { can, hasRole } = useAuthorization();
const roleLabel = computed(() => (hasRole('super-admin') ? 'Super Administrator' : 'Administrator'));

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({ only: ['dashboard', 'attention'], onFinish: () => (refreshing.value = false) });
};

const quickActions = computed(() =>
    [
        { label: 'Manage Users', href: '/users', icon: 'users', permission: 'users.view' },
        { label: 'System Access', href: '/admin/system-access', icon: 'key', permission: 'applications.manage_access' },
        { label: 'Systems', href: '/admin/applications', icon: 'grid', permission: 'applications.view' },
    ].filter((action) => can(action.permission)),
);
</script>

<template>
    <Head title="Admin Dashboard" />

    <DashboardBanner
        :badge="roleLabel"
        :actions="quickActions"
        :generated-at="dashboard.generatedAt"
        :refreshing="refreshing"
        @refresh="refresh"
    />

    <SystemDashboard :dashboard="dashboard" :attention="attention" />
</template>
