<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import AdminCard from '../../Components/UI/AdminCard.vue';
import Icon from '../../Components/UI/Icon.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import MetricCard from '../../Components/Admin/MetricCard.vue';
import SimpleChart from '../../Components/Charts/SimpleChart.vue';
import { useAuthorization } from '../../Composables/useAuthorization';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy IMIS admin dashboard: welcome banner with quick actions,
 * metric cards, login trend, users per IMIS role / office, access per
 * system, top systems, users without access, and recent activity. Each
 * section only appears if the server sent it (permission-scoped).
 */
const props = defineProps({
    attention: { type: Array, required: true },
    dashboard: { type: Object, required: true },
});

const page = usePage();
const { can, hasRole } = useAuthorization();
const user = computed(() => page.props.auth.user);
const roleLabel = computed(() => (hasRole('super-admin') ? 'Super Administrator' : 'Administrator'));

const metrics = computed(() => props.dashboard.metrics);
const percent = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0);

const now = ref(new Date());
let clock;
onMounted(() => (clock = window.setInterval(() => (now.value = new Date()), 30000)));
onBeforeUnmount(() => window.clearInterval(clock));

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

    <!-- Welcome banner -->
    <section class="relative mb-4 overflow-hidden rounded-xl bg-linear-to-br from-csc-blue to-csc-blue-dark px-6 py-6 text-white shadow-sm">
        <div class="pointer-events-none absolute -top-14 -right-10 h-48 w-48 rounded-full border-[22px] border-white/5" aria-hidden="true" />
        <div class="relative flex flex-wrap items-center justify-between gap-4">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold">
                    <Icon name="shield" size="sm" />
                    {{ roleLabel }}
                </span>
                <h1 class="mt-2 text-xl font-semibold sm:text-2xl">Welcome back, {{ user?.name }}!</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-white/80">
                    <Icon name="calendar" size="sm" />
                    {{ now.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) }}
                    <span aria-hidden="true">·</span>
                    <Icon name="clock" size="sm" />
                    {{ now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) }}
                </p>
            </div>
            <div v-if="quickActions.length" class="flex flex-wrap gap-2">
                <Link
                    v-for="action in quickActions"
                    :key="action.href"
                    :href="action.href"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-2 text-sm font-medium transition-colors hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                >
                    <Icon :name="action.icon" size="sm" />
                    {{ action.label }}
                </Link>
            </div>
        </div>
    </section>

    <!-- Toolbar -->
    <div class="mb-4 flex items-center justify-end gap-3 text-xs text-text-muted">
        <span>Data as of {{ formatDateTime(dashboard.generatedAt) }}</span>
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-primary/40 px-3 py-1.5 text-xs font-medium text-primary hover:bg-primary hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:opacity-60"
            :disabled="refreshing"
            @click="refresh"
        >
            <Icon name="refresh" size="sm" :class="{ 'motion-safe:animate-spin': refreshing }" />
            Refresh
        </button>
    </div>

    <div class="space-y-4">
        <!-- Row 1: metric cards -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard v-if="metrics.users" label="Total Users" :value="metrics.users.total" icon="users" tone="primary">
                <span class="font-semibold text-success">{{ metrics.users.active }}</span> active ·
                <span class="text-danger">{{ metrics.users.inactive }}</span> inactive
            </MetricCard>
            <MetricCard
                v-if="metrics.systems"
                label="Registered Systems"
                :value="metrics.systems.total"
                icon="grid"
                tone="success"
                :progress="percent(metrics.systems.active, metrics.systems.total)"
            >
                <span class="font-semibold text-success">{{ metrics.systems.active }}</span> active
                <template v-if="metrics.systems.underMaintenance"> · <span class="text-warning">{{ metrics.systems.underMaintenance }}</span> in maintenance</template>
            </MetricCard>
            <MetricCard
                v-if="metrics.access"
                label="Access Grants"
                :value="metrics.access.grants"
                icon="key"
                tone="warning"
                :progress="percent(metrics.access.usersWithAccess, metrics.access.usersWithAccess + metrics.access.usersWithoutAccess)"
            >
                <span class="font-semibold text-success">{{ metrics.access.usersWithAccess }}</span> people with access ·
                <span class="text-danger">{{ metrics.access.usersWithoutAccess }}</span> without
            </MetricCard>
            <MetricCard v-if="metrics.logins" label="Login Activity" :value="metrics.logins.today ?? '—'" icon="activity" tone="info">
                logins today
                <template v-if="metrics.logins.activeSessions !== null">
                    · <Link href="/admin/sessions" class="font-semibold text-primary hover:underline">{{ metrics.logins.activeSessions }} active now</Link>
                </template>
            </MetricCard>
        </div>

        <!-- Row 2: login trend + IMIS roles -->
        <div class="grid gap-4 xl:grid-cols-12">
            <AdminCard v-if="dashboard.loginTrend" title="Login Trend" subtitle="Sign-ins per day, last 14 days" icon="activity" class="xl:col-span-8">
                <SimpleChart
                    type="line"
                    :labels="dashboard.loginTrend.labels"
                    :values="dashboard.loginTrend.values"
                    label="Sign-ins"
                    :summary="`Sign-ins per day over the last 14 days; ${dashboard.loginTrend.values.reduce((a, b) => a + b, 0)} in total.`"
                />
            </AdminCard>
            <AdminCard v-if="dashboard.roleCounts" title="IMIS Roles" subtitle="People per IMIS role" icon="users" class="xl:col-span-4">
                <SimpleChart
                    type="bar"
                    :labels="dashboard.roleCounts.labels"
                    :values="dashboard.roleCounts.values"
                    label="People"
                    :height="Math.max(160, dashboard.roleCounts.labels.length * 34)"
                    summary="Number of people holding each IMIS role."
                />
            </AdminCard>
        </div>

        <!-- Row 3: users by office + access by system -->
        <div class="grid gap-4 xl:grid-cols-12">
            <AdminCard v-if="dashboard.usersByOffice" title="Users by Office" icon="building" class="xl:col-span-5">
                <SimpleChart
                    type="bar"
                    :labels="dashboard.usersByOffice.labels"
                    :values="dashboard.usersByOffice.values"
                    label="People"
                    :height="Math.max(180, dashboard.usersByOffice.labels.length * 28)"
                    summary="Number of user accounts in each office."
                />
            </AdminCard>
            <AdminCard v-if="dashboard.accessBySystem" title="Access by System" subtitle="People with a role in each system" icon="chartBar" class="xl:col-span-7">
                <SimpleChart
                    type="bar"
                    :labels="dashboard.accessBySystem.labels"
                    :values="dashboard.accessBySystem.values"
                    label="People with access"
                    :height="Math.max(180, dashboard.accessBySystem.labels.length * 28)"
                    summary="Number of people with a role in each system, most first."
                />
            </AdminCard>
        </div>

        <!-- Row 4: top systems + users without access -->
        <div class="grid gap-4 xl:grid-cols-12">
            <AdminCard v-if="dashboard.accessBySystem" title="Top Systems" icon="grid" class="xl:col-span-5">
                <table class="w-full text-[0.8125rem]">
                    <caption class="sr-only">Systems with the most people assigned</caption>
                    <thead class="bg-surface-muted text-left text-xs font-semibold text-text-muted uppercase">
                        <tr>
                            <th scope="col" class="px-3 py-2">System</th>
                            <th scope="col" class="px-3 py-2 text-center">Status</th>
                            <th scope="col" class="px-3 py-2 text-right">Assigned</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-if="!dashboard.accessBySystem.top.length">
                            <td colspan="3" class="px-3 py-6 text-center text-text-muted">No systems yet.</td>
                        </tr>
                        <tr v-for="(system, index) in dashboard.accessBySystem.top" :key="system.id">
                            <td class="px-3 py-2">
                                <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-surface-muted text-xs text-text-muted">{{ index + 1 }}</span>
                                {{ system.name }}
                            </td>
                            <td class="px-3 py-2 text-center"><StatusBadge :status="system.status" /></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ system.assigned }}</td>
                        </tr>
                    </tbody>
                </table>
            </AdminCard>

            <AdminCard
                v-if="dashboard.usersWithoutAccess"
                title="Users Without System Access"
                :subtitle="`${dashboard.usersWithoutAccess.total} active ${dashboard.usersWithoutAccess.total === 1 ? 'account' : 'accounts'}`"
                icon="userPlus"
                class="xl:col-span-7"
            >
                <template v-if="can('applications.manage_access')" #actions>
                    <Link href="/admin/user-access" class="text-sm font-medium text-primary hover:text-primary-hover">Assign access</Link>
                </template>
                <div class="max-h-80 overflow-y-auto">
                    <table class="w-full text-[0.8125rem]">
                        <caption class="sr-only">Active accounts with no system access</caption>
                        <thead class="sticky top-0 bg-surface-muted text-left text-xs font-semibold text-text-muted uppercase">
                            <tr>
                                <th scope="col" class="px-3 py-2">Name / Position</th>
                                <th scope="col" class="px-3 py-2">Office</th>
                                <th scope="col" class="px-3 py-2 text-center">IMIS role</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-if="!dashboard.usersWithoutAccess.users.length">
                                <td colspan="3" class="px-3 py-6 text-center text-text-muted">Everyone active has access to at least one system.</td>
                            </tr>
                            <tr v-for="person in dashboard.usersWithoutAccess.users" :key="person.id">
                                <td class="px-3 py-2">
                                    <Link :href="`/admin/user-access?user=${person.id}`" class="font-medium text-text hover:text-primary">{{ person.name }}</Link>
                                    <div class="text-xs text-text-muted">{{ person.position || '—' }}</div>
                                </td>
                                <td class="px-3 py-2 text-text-muted">{{ person.office || '—' }}</td>
                                <td class="px-3 py-2 text-center text-xs">{{ person.roles.join(', ') || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AdminCard>
        </div>

        <!-- Row 5: recent activity + needs attention -->
        <div class="grid gap-4 xl:grid-cols-12">
            <AdminCard v-if="dashboard.recentActivity" title="Recent Admin Activity" icon="clipboard" class="xl:col-span-8">
                <template #actions>
                    <Link href="/admin/audit-logs" class="text-sm font-medium text-primary hover:text-primary-hover">View audit log</Link>
                </template>
                <ul class="divide-y divide-border">
                    <li v-if="!dashboard.recentActivity.length" class="py-6 text-center text-sm text-text-muted">No activity yet.</li>
                    <li v-for="entry in dashboard.recentActivity" :key="entry.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 text-sm">
                        <span class="rounded-full bg-info-bg px-2 py-0.5 text-xs font-semibold text-primary">{{ entry.event }}</span>
                        <span class="min-w-0 flex-1 text-text">{{ entry.description }}</span>
                        <span class="text-xs text-text-muted">{{ entry.actor }} · {{ formatDateTime(entry.created_at) }}</span>
                    </li>
                </ul>
            </AdminCard>

            <AdminCard title="Needs Attention" subtitle="Inactive records" icon="exclamation" class="xl:col-span-4">
                <ul v-if="attention.length" class="divide-y divide-border">
                    <li v-for="item in attention" :key="item.href" class="flex items-center justify-between py-2 text-sm">
                        <Link :href="item.href" class="font-medium text-primary hover:text-primary-hover">{{ item.label }}</Link>
                        <span class="rounded-full bg-warning-bg px-2 py-0.5 text-xs font-semibold text-warning">{{ item.inactiveCount }} inactive</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-text-muted">Nothing inactive — all clear.</p>
            </AdminCard>
        </div>
    </div>
</template>
