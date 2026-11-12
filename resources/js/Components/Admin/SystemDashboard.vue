<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Card from '../UI/Card.vue';
import Badge from '../UI/Badge.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import StatTile from '../UI/StatTile.vue';
import SimpleChart from '../Charts/SimpleChart.vue';
import { useAuthorization } from '../../Composables/useAuthorization';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The system administration dashboard (AdminDashboard): metric cards,
 * login trend, users per IMIS role / office, access per system, top
 * systems, users without access, recent activity and inactive records.
 * Each section only appears if the server sent it (permission-scoped).
 */
const props = defineProps({
    attention: { type: Array, required: true },
    dashboard: { type: Object, required: true },
});

const { can } = useAuthorization();
const metrics = computed(() => props.dashboard.metrics);
const percent = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0);
</script>

<template>
    <div class="space-y-4">
        <!-- Row 1: metric cards -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile v-if="metrics.users" label="Total Users" :value="metrics.users.total" icon="users" tone="primary">
                <span class="font-semibold text-success-fg">{{ metrics.users.active }}</span> active ·
                {{ metrics.users.inactive }} inactive
            </StatTile>
            <StatTile
                v-if="metrics.systems"
                label="Registered Systems"
                :value="metrics.systems.total"
                icon="grid"
                tone="success"
                :progress="percent(metrics.systems.active, metrics.systems.total)"
            >
                <span class="font-semibold text-success-fg">{{ metrics.systems.active }}</span> active
                <template v-if="metrics.systems.underMaintenance"> · <span class="text-warning-fg">{{ metrics.systems.underMaintenance }}</span> in maintenance</template>
            </StatTile>
            <StatTile
                v-if="metrics.access"
                label="Access Grants"
                :value="metrics.access.grants"
                icon="key"
                tone="primary"
                :progress="percent(metrics.access.usersWithAccess, metrics.access.usersWithAccess + metrics.access.usersWithoutAccess)"
            >
                <span class="font-semibold text-success-fg">{{ metrics.access.usersWithAccess }}</span> people with access ·
                {{ metrics.access.usersWithoutAccess }} without
            </StatTile>
            <StatTile v-if="metrics.logins" label="Login Activity" :value="metrics.logins.today ?? '—'" icon="activity" tone="info">
                logins today
                <template v-if="metrics.logins.activeSessions !== null">
                    · <Link href="/admin/sessions" class="font-semibold text-link hover:underline">{{ metrics.logins.activeSessions }} active now</Link>
                </template>
            </StatTile>
        </div>

        <!-- Row 2: login trend + IMIS roles -->
        <div class="grid gap-4 xl:grid-cols-12">
            <Card v-if="dashboard.loginTrend" title="Login Trend" description="Sign-ins per day, last 14 days" icon="activity" class="xl:col-span-8">
                <SimpleChart
                    type="line"
                    :labels="dashboard.loginTrend.labels"
                    :values="dashboard.loginTrend.values"
                    label="Sign-ins"
                    :summary="`Sign-ins per day over the last 14 days; ${dashboard.loginTrend.values.reduce((a, b) => a + b, 0)} in total.`"
                />
            </Card>
            <Card v-if="dashboard.roleCounts" title="IMIS Roles" description="People per IMIS role" icon="users" class="xl:col-span-4">
                <SimpleChart
                    type="bar"
                    :labels="dashboard.roleCounts.labels"
                    :values="dashboard.roleCounts.values"
                    label="People"
                    :height="Math.max(160, dashboard.roleCounts.labels.length * 34)"
                    summary="Number of people holding each IMIS role."
                />
            </Card>
        </div>

        <!-- Row 3: users by office + access by system -->
        <div class="grid gap-4 xl:grid-cols-12">
            <Card v-if="dashboard.usersByOffice" title="Users by Office" icon="building" class="xl:col-span-5">
                <SimpleChart
                    type="bar"
                    :labels="dashboard.usersByOffice.labels"
                    :values="dashboard.usersByOffice.values"
                    label="People"
                    :height="Math.max(180, dashboard.usersByOffice.labels.length * 28)"
                    summary="Number of user accounts in each office."
                />
            </Card>
            <Card v-if="dashboard.accessBySystem" title="Access by System" description="People with a role in each system" icon="chartBar" class="xl:col-span-7">
                <SimpleChart
                    type="bar"
                    :labels="dashboard.accessBySystem.labels"
                    :values="dashboard.accessBySystem.values"
                    label="People with access"
                    :height="Math.max(180, dashboard.accessBySystem.labels.length * 28)"
                    summary="Number of people with a role in each system, most first."
                />
            </Card>
        </div>

        <!-- Row 4: top systems + users without access -->
        <div class="grid gap-4 xl:grid-cols-12">
            <Card v-if="dashboard.accessBySystem" title="Top Systems" icon="grid" class="xl:col-span-5">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <caption class="sr-only">Systems with the most people assigned</caption>
                        <thead>
                            <tr>
                                <th scope="col">System</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-right">Assigned</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!dashboard.accessBySystem.top.length">
                                <td colspan="3" class="px-3 py-6 text-center text-text-muted">No systems yet.</td>
                            </tr>
                            <tr v-for="(system, index) in dashboard.accessBySystem.top" :key="system.id">
                                <td>
                                    <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-surface-muted text-xs text-text-muted">{{ index + 1 }}</span>
                                    {{ system.name }}
                                </td>
                                <td class="text-center"><StatusBadge :status="system.status" /></td>
                                <td class="text-right tabular-nums">{{ system.assigned }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card
                v-if="dashboard.usersWithoutAccess"
                title="Users Without System Access"
                :description="`${dashboard.usersWithoutAccess.total} active ${dashboard.usersWithoutAccess.total === 1 ? 'account' : 'accounts'}`"
                icon="userPlus"
                class="xl:col-span-7"
            >
                <template v-if="can('applications.manage_access')" #actions>
                    <Link href="/admin/user-access" class="text-sm font-medium text-link hover:text-primary-hover">Assign access</Link>
                </template>
                <div class="max-h-80 overflow-auto">
                    <table class="data-table" data-sticky>
                        <caption class="sr-only">Active accounts with no system access</caption>
                        <thead>
                            <tr>
                                <th scope="col">Name / Position</th>
                                <th scope="col">Office</th>
                                <th scope="col" class="text-center">IMIS role</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!dashboard.usersWithoutAccess.users.length">
                                <td colspan="3" class="px-3 py-6 text-center text-text-muted">Everyone active has access to at least one system.</td>
                            </tr>
                            <tr v-for="person in dashboard.usersWithoutAccess.users" :key="person.id">
                                <td>
                                    <Link :href="`/admin/user-access?user=${person.id}`" class="font-medium text-text hover:text-link">{{ person.name }}</Link>
                                    <div class="text-xs text-text-muted">{{ person.position || '—' }}</div>
                                </td>
                                <td class="text-text-muted">{{ person.office || '—' }}</td>
                                <td class="text-center text-xs">{{ person.roles.join(', ') || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <!-- Row 5: recent activity + needs attention -->
        <div class="grid gap-4 xl:grid-cols-12">
            <Card v-if="dashboard.recentActivity" title="Recent Admin Activity" icon="clipboard" class="xl:col-span-8">
                <template #actions>
                    <Link href="/admin/audit-logs" class="text-sm font-medium text-link hover:text-primary-hover">View audit log</Link>
                </template>
                <ul class="divide-y divide-border">
                    <li v-if="!dashboard.recentActivity.length" class="py-6 text-center text-sm text-text-muted">No activity yet.</li>
                    <li v-for="entry in dashboard.recentActivity" :key="entry.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 text-sm">
                        <Badge tone="info">{{ entry.event }}</Badge>
                        <span class="min-w-0 flex-1 text-text">{{ entry.description }}</span>
                        <span class="flex flex-col text-right text-xs text-text-muted">
                            <span :title="entry.actor_label || undefined">{{ entry.actor }}</span>
                            <time :datetime="entry.created_at">{{ formatDateTime(entry.created_at) }}</time>
                        </span>
                    </li>
                </ul>
            </Card>

            <Card title="Needs Attention" description="Inactive records" icon="exclamation" class="xl:col-span-4">
                <ul v-if="attention.length" class="divide-y divide-border">
                    <li v-for="item in attention" :key="item.href" class="flex items-center justify-between py-2 text-sm">
                        <Link :href="item.href" class="font-medium text-link hover:text-primary-hover">{{ item.label }}</Link>
                        <Badge tone="warning">{{ item.inactiveCount }} inactive</Badge>
                    </li>
                </ul>
                <p v-else class="text-sm text-text-muted">Nothing inactive — all clear.</p>
            </Card>
        </div>
    </div>
</template>
