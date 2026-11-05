<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Card from '../../../Components/UI/Card.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import DashboardBanner from '../../../Components/Admin/DashboardBanner.vue';
import HrAnalytics from '../../../Components/Admin/HrAnalytics.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

/**
 * The Human Resources dashboard, landing page of the HR area: welcome
 * banner with HR quick actions, then the analytics (HrAnalytics). Those
 * need `employees.view`; without it `hr` is null and the viewer is
 * pointed at their HR pages instead.
 */
defineProps({
    hr: { type: Object, default: null },
    readiness: { type: Object, default: null },
    generatedAt: { type: String, required: true },
});

const { canAny } = useAuthorization();

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({ only: ['hr', 'generatedAt'], onFinish: () => (refreshing.value = false) });
};

const quickActions = computed(() =>
    [
        { label: 'Employees', href: '/admin/hr/employees', icon: 'users', permission: ['employees.view', 'employees.view_unit'] },
        { label: 'Staffing', href: '/admin/hr/staffing', icon: 'building', permission: ['plantilla.view', 'plantilla.manage'] },
        { label: 'Leave', href: '/admin/hr/leave/applications', icon: 'calendar', permission: ['leave.view', 'leave.manage'] },
        { label: 'Learning & Development', href: '/admin/hr/learning-development', icon: 'chartBar', permission: ['ld.view', 'ld.manage'] },
    ].filter((action) => canAny(action.permission)),
);
</script>

<template>
    <Head title="HR Dashboard" />

    <!-- The data timestamp and Refresh only apply when there are analytics. -->
    <DashboardBanner
        badge="Human Resources"
        badge-icon="clipboard"
        :actions="quickActions"
        :generated-at="hr ? generatedAt : null"
        :refreshing="refreshing"
        @refresh="refresh"
    />

    <Link
        v-if="readiness"
        href="/admin/hr/readiness"
        class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text hover:border-warning focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
    >
        <span>
            <strong>Cutover readiness:</strong>
            {{ readiness.remaining }} of {{ readiness.total }} checks still to do before the legacy LMS and ldms can be retired.
        </span>
        <span class="font-medium text-primary">Open checklist</span>
    </Link>

    <template v-if="hr">
        <HrAnalytics :hr="hr" />
    </template>

    <Card v-else>
        <EmptyState
            icon="clipboard"
            title="Your HR pages are in the sidebar"
            description="The HR analytics name people and show their birthdays, so they're only for those who can view all employee records."
        />
    </Card>
</template>
