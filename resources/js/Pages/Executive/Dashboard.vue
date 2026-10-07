<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Button from '../../Components/UI/Button.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Icon from '../../Components/UI/Icon.vue';
import SystemTile from '../../Components/Executive/SystemTile.vue';
import MetricTile from '../../Components/Executive/MetricTile.vue';
import { formatAge, sectionStates } from '../../Components/Executive/format';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * Exec-1 to Exec-3: top management's view of every IMIS module and
 * connected system in one place. It answers, in order: is anything
 * wrong (system tiles), how are we doing against targets (the counts
 * and each figure's status), and where (each figure's breakdown, and a
 * link into the module for those who may open it). Figures come from the
 * last collection (every 30 minutes), never fetched live.
 */
const props = defineProps({
    dashboard: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({ only: ['dashboard'], onFinish: () => (refreshing.value = false) });
};

// "Needs attention" shows only figures off target, and sections that aren't updating.
const filter = ref('all');
const sections = computed(() =>
    props.dashboard.sections
        .map((section) => ({
            ...section,
            metrics: filter.value === 'attention' ? section.metrics.filter((metric) => ['behind', 'at_risk'].includes(metric.status)) : section.metrics,
        }))
        .filter((section) => filter.value === 'all' || section.metrics.length || ['failing', 'stale'].includes(section.state)),
);

const summary = computed(() => props.dashboard.summary);
const modules = computed(() => sections.value.filter((section) => section.kind === 'module'));
const systems = computed(() => sections.value.filter((section) => section.kind === 'system'));
</script>

<template>
    <Head title="Executive Dashboard" />

    <PageHeader
        title="Executive Dashboard"
        description="Every IMIS module and connected system in one place: what's running, how the figures stand against their targets, and where to look next. Totals only; open a figure's details to go into the module."
        :breadcrumbs="[{ label: 'Executive Dashboard' }]"
    >
        <template #actions>
            <Button variant="outline" size="sm" :loading="refreshing" @click="refresh">
                <Icon name="refresh" size="sm" />
                Refresh
            </Button>
            <Button variant="outline" size="sm" href="/executive/print" external>
                <Icon name="printer" size="sm" />
                Print
            </Button>
            <Button v-if="canManage" variant="secondary" size="sm" href="/executive/settings">
                <Icon name="cog" size="sm" />
                Settings
            </Button>
        </template>
    </PageHeader>

    <Card v-if="!dashboard.lastCollectedAt">
        <EmptyState
            icon="chartBar"
            title="No figures yet"
            description="Figures are collected every 30 minutes. They appear here after the first collection."
        />
    </Card>

    <div v-else class="space-y-6">
        <!-- At a glance: the answer to "is anything wrong?" before any detail. -->
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="At a glance">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">Systems</p>
                <p class="mt-1 text-2xl font-bold text-text">
                    {{ summary.systemsNeedingAttention ? `${summary.systemsNeedingAttention} need attention` : 'All running' }}
                </p>
                <p class="text-xs text-text-muted">{{ summary.systems }} systems, IMIS included</p>
            </div>
            <template v-if="summary.withTargets">
                <div class="rounded-lg border border-l-4 border-border border-l-success bg-surface p-4">
                    <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">On track</p>
                    <p class="mt-1 text-2xl font-bold text-text">{{ summary.onTrack }}</p>
                    <p class="text-xs text-text-muted">of {{ summary.withTargets }} figures with targets</p>
                </div>
                <div class="rounded-lg border border-l-4 border-border border-l-warning bg-surface p-4">
                    <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">At risk</p>
                    <p class="mt-1 text-2xl font-bold text-text">{{ summary.atRisk }}</p>
                    <p class="text-xs text-text-muted">short of target, within tolerance</p>
                </div>
                <div class="rounded-lg border border-l-4 border-border border-l-danger bg-surface p-4">
                    <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">Behind</p>
                    <p class="mt-1 text-2xl font-bold text-text">{{ summary.behind }}</p>
                    <p class="text-xs text-text-muted">needs action</p>
                </div>
            </template>
            <div v-else class="rounded-lg border border-dashed border-border bg-surface p-4 sm:col-span-1 lg:col-span-3">
                <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">Targets</p>
                <p class="mt-1 text-sm text-text">No targets set yet.</p>
                <p class="text-xs text-text-muted">
                    Figures show without an on-track/behind status until targets are agreed and entered<template v-if="canManage">
                        in Settings</template>.
                </p>
            </div>
        </section>

        <p class="text-xs text-text-muted">
            Figures as of <time :datetime="dashboard.lastCollectedAt">{{ formatDateTime(dashboard.lastCollectedAt) }}</time>
            ({{ formatAge(dashboard.lastCollectedAt) }}). Collected every 30 minutes.
        </p>

        <Card title="Systems" icon="grid" description="Each system's status and whether its figures are arriving.">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <SystemTile v-for="system in dashboard.systems" :key="system.key" :system="system" />
            </div>
        </Card>

        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Show figures">
            <Button size="sm" :variant="filter === 'all' ? 'primary' : 'secondary'" :aria-pressed="filter === 'all'" @click="filter = 'all'">All figures</Button>
            <Button
                size="sm"
                :variant="filter === 'attention' ? 'primary' : 'secondary'"
                :aria-pressed="filter === 'attention'"
                @click="filter = 'attention'"
            >
                Needs attention
            </Button>
        </div>

        <Card v-if="!sections.length">
            <EmptyState icon="checkCircle" title="Nothing needs attention" description="No figure is behind or at risk, and every source is updating." />
        </Card>

        <!-- IMIS's own modules first, then connected systems. -->
        <template v-for="(group, index) in [modules, systems]" :key="index">
            <Card v-for="section in group" :key="section.key" :title="section.label" :icon="section.kind === 'system' ? 'grid' : 'chartBar'">
                <template #actions>
                    <Badge v-if="sectionStates[section.state]" :tone="sectionStates[section.state].tone">{{ sectionStates[section.state].label }}</Badge>
                    <span v-if="section.collectedAt" class="text-xs text-text-muted">Updated {{ formatAge(section.collectedAt) }}</span>
                </template>

                <p v-if="section.error" class="mb-3 rounded-md bg-danger-bg px-3 py-2 text-xs text-danger">
                    The last collection failed: {{ section.error }}<template v-if="section.metrics.length"> Showing the last figures received.</template>
                </p>

                <div v-if="section.metrics.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricTile v-for="metric in section.metrics" :key="metric.id" :metric="metric" />
                </div>
                <p v-else-if="!section.error" class="text-sm text-text-muted">No figures yet.</p>
            </Card>
        </template>
    </div>
</template>
