<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Icon from '../../Components/UI/Icon.vue';
import OrbitCircles from '../../Components/UI/OrbitCircles.vue';
import Tabs from '../../Components/UI/Tabs.vue';
import MetricDetail from '../../Components/Executive/MetricDetail.vue';
import MetricTile from '../../Components/Executive/MetricTile.vue';
import StatusRing from '../../Components/Executive/StatusRing.vue';
import SystemTile from '../../Components/Executive/SystemTile.vue';
import { formatAge, formatValue, headline, sectionStates, systemStates, targetStatuses } from '../../Components/Executive/format';
import { firstName } from '../../Composables/useSplash';
import { toast } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * The Executive Dashboard, built for top management. From top to bottom:
 * a headline band that says in one sentence whether anything is wrong
 * (with the share of figures on track); "Needs your attention", only what
 * is off target, not updating or down; the viewer's own watch list; then
 * tabs: an overview (system status board and the key figures of every
 * module), one tab per module and one for connected systems. A figure opens
 * in a dialog with its trend, target progress and breakdown. Figures come
 * from the last collection (every 30 minutes), never fetched live.
 */
const props = defineProps({
    dashboard: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});

const page = usePage();

const greeting = computed(() => {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    const name = firstName(page.props.auth.user?.name);

    return name ? `${part}, ${name}` : part;
});

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({ only: ['dashboard'], onFinish: () => (refreshing.value = false) });
};

const summary = computed(() => props.dashboard.summary);
const modules = computed(() => props.dashboard.sections.filter((section) => section.kind === 'module'));
const systemSections = computed(() => props.dashboard.sections.filter((section) => section.kind === 'system'));
const allMetrics = computed(() => props.dashboard.sections.flatMap((section) => section.metrics.map((metric) => ({ metric, section }))));

const notUpdating = computed(() => props.dashboard.sections.filter((section) => ['failing', 'stale'].includes(section.state)).length);
const verdict = computed(() => headline(summary.value, notUpdating.value));

// What needs attention: figures off target, sources that stopped updating, systems with a problem.
const attention = computed(() => {
    const items = [];

    for (const { metric, section } of allMetrics.value) {
        if (metric.status === 'behind') items.push({ id: `m:${metric.id}`, tone: 'danger', title: metric.label, where: section.label, text: `${formatValue(metric.value, metric.unit)}, ${targetStatuses.behind.label.toLowerCase()} target`, metric });
        else if (metric.status === 'at_risk') items.push({ id: `m:${metric.id}`, tone: 'warning', title: metric.label, where: section.label, text: `${formatValue(metric.value, metric.unit)}, a little short of target`, metric });
    }

    for (const section of props.dashboard.sections) {
        if (section.state === 'failing') items.push({ id: `s:${section.key}`, tone: 'danger', title: section.label, where: 'Figures', text: 'Not updating. The last collection failed.' });
        else if (section.state === 'stale') items.push({ id: `s:${section.key}`, tone: 'warning', title: section.label, where: 'Figures', text: 'Figures are not current.' });
    }

    for (const system of props.dashboard.systems) {
        if (['ok', 'not_connected', 'waiting'].includes(system.state)) continue;
        const state = systemStates[system.state];
        items.push({ id: `y:${system.key}`, tone: state.tone === 'danger' ? 'danger' : 'warning', title: system.name, where: 'System', text: `${state.label}. ${system.detail}` });
    }

    return items.sort((a, b) => (a.tone === b.tone ? 0 : a.tone === 'danger' ? -1 : 1));
});

const attentionCount = (section) =>
    section.metrics.filter((metric) => ['behind', 'at_risk'].includes(metric.status)).length + (['failing', 'stale'].includes(section.state) ? 1 : 0);

const tabs = computed(() => [
    { key: 'overview', label: 'Overview', icon: 'home' },
    ...modules.value.map((section) => ({ key: section.key, label: section.label, badge: attentionCount(section) || undefined })),
    { key: 'systems', label: 'Connected systems', icon: 'grid', badge: systemSections.value.reduce((sum, section) => sum + attentionCount(section), 0) || undefined },
]);
const tab = ref('overview');
const showTab = (key) => {
    tab.value = key;
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

// The open figure's dialog.
const detail = ref(null);
const detailSection = ref(null);
const open = (metric) => {
    detail.value = metric;
    detailSection.value = allMetrics.value.find((entry) => entry.metric.id === metric.id)?.section.label ?? null;
};

// The viewer's watch list: a per-browser convenience, so storage may fail and the page still works.
const STORAGE_KEY = 'imis.executive.watch';
const readPins = () => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    } catch {
        return [];
    }
};
const pins = ref(readPins());
const togglePin = (metric) => {
    pins.value = pins.value.includes(metric.id) ? pins.value.filter((id) => id !== metric.id) : [...pins.value, metric.id];
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(pins.value));
    } catch {
        // Not saved; the list still works until the page is closed.
    }
};
const watched = computed(() => allMetrics.value.filter(({ metric }) => pins.value.includes(metric.id)));

const keyFigures = (section) => section.metrics.slice(0, 3);

// Meeting mode: the browser's full screen, for a projector or a shared screen.
const fullscreen = ref(false);
const syncFullscreen = () => (fullscreen.value = Boolean(document.fullscreenElement));
const toggleFullscreen = async () => {
    try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await document.documentElement.requestFullscreen();
    } catch {
        toast.error('This browser would not switch to full screen.');
    }
};
onMounted(() => document.addEventListener('fullscreenchange', syncFullscreen));
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', syncFullscreen));

// A short text summary for a message or meeting notes.
const copySummary = async () => {
    const lines = [`Executive summary, as of ${formatDateTime(props.dashboard.lastCollectedAt)}`, verdict.value.text];
    if (summary.value.withTargets) lines.push(`${summary.value.onTrack} of ${summary.value.withTargets} figures with targets are on track.`);
    for (const item of attention.value) lines.push(`- ${item.title} (${item.where}): ${item.text}`);

    try {
        await navigator.clipboard.writeText(lines.join('\n'));
        toast.success('Summary copied.');
    } catch {
        toast.error('The summary could not be copied.');
    }
};

const heroButton =
    'inline-flex items-center gap-1.5 rounded-md border border-white/30 bg-white/10 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-60';
const toneIcon = { danger: 'xCircle', warning: 'exclamation', success: 'checkCircle' };
const toneChip = { danger: 'bg-danger-bg text-danger-fg', warning: 'bg-warning-bg text-warning-fg', success: 'bg-success-bg text-success-fg' };
</script>

<template>
    <div class="space-y-6">
        <Head title="Executive Dashboard" />

        <!-- Headline: one sentence that answers "is anything wrong?", with the on-track ring. -->
        <section class="relative overflow-hidden rounded-xl bg-brand px-5 py-6 text-white shadow-md md:px-8 md:py-8" aria-labelledby="exec-title">
            <OrbitCircles />

            <div class="relative z-10 flex flex-col items-center gap-6 md:flex-row md:justify-between">
                <div class="min-w-0 text-center md:text-left">
                    <p class="text-sm text-white/80">{{ greeting }}</p>
                    <h1 id="exec-title" class="text-2xl font-bold md:text-3xl">Executive Dashboard</h1>

                    <p v-if="dashboard.lastCollectedAt" class="mt-3 flex items-start justify-center gap-2 text-lg font-semibold md:justify-start md:text-xl">
                        <Icon :name="toneIcon[verdict.tone]" class="mt-1 shrink-0" />
                        <span>{{ verdict.text }}</span>
                    </p>
                    <p v-else class="mt-3 text-lg">No figures have been collected yet.</p>

                    <p v-if="dashboard.lastCollectedAt" class="mt-1 text-sm text-white/75">
                        Figures as of <time :datetime="dashboard.lastCollectedAt">{{ formatDateTime(dashboard.lastCollectedAt) }}</time>
                        ({{ formatAge(dashboard.lastCollectedAt) }}). Updated every 30 minutes.
                    </p>

                    <div class="mt-4 flex flex-wrap justify-center gap-2 md:justify-start">
                        <button type="button" :class="heroButton" :disabled="refreshing" @click="refresh">
                            <Icon name="refresh" size="sm" :class="refreshing ? 'animate-spin' : ''" />
                            Refresh
                        </button>
                        <button type="button" :class="heroButton" @click="copySummary">
                            <Icon name="copy" size="sm" />
                            Copy summary
                        </button>
                        <button type="button" :class="heroButton" :aria-pressed="fullscreen" @click="toggleFullscreen">
                            <Icon name="desktop" size="sm" />
                            {{ fullscreen ? 'Exit full screen' : 'Meeting mode' }}
                        </button>
                        <a href="/executive/print" target="_blank" rel="noopener" :class="heroButton">
                            <Icon name="printer" size="sm" />
                            Print
                        </a>
                        <Link v-if="canManage" href="/executive/settings" :class="heroButton">
                            <Icon name="cog" size="sm" />
                            Settings
                        </Link>
                    </div>
                </div>

                <div v-if="dashboard.lastCollectedAt" class="flex shrink-0 items-center gap-5">
                    <StatusRing :on-track="summary.onTrack" :at-risk="summary.atRisk" :behind="summary.behind" />
                    <ul v-if="summary.withTargets" class="space-y-1.5 text-sm">
                        <li class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-green-300" aria-hidden="true" />{{ summary.onTrack }} on track</li>
                        <li class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-amber-300" aria-hidden="true" />{{ summary.atRisk }} at risk</li>
                        <li class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-red-300" aria-hidden="true" />{{ summary.behind }} behind</li>
                    </ul>
                    <p v-else class="max-w-40 text-sm text-white/80">Targets are not set yet, so figures show without an on-track status.</p>
                </div>
            </div>
        </section>

        <Card v-if="!dashboard.lastCollectedAt">
            <EmptyState icon="chartBar" title="No figures yet" description="Figures are collected every 30 minutes. They appear here after the first collection." />
        </Card>

        <template v-else>
            <!-- Only what needs a decision or a look. -->
            <section aria-labelledby="attention-title">
                <h2 id="attention-title" class="mb-3 flex items-center gap-2 text-lg font-semibold text-text">
                    Needs your attention
                    <Badge v-if="attention.length" :tone="attention.some((item) => item.tone === 'danger') ? 'danger' : 'warning'" pill>{{ attention.length }}</Badge>
                </h2>

                <div v-if="!attention.length" class="flex items-center gap-3 rounded-xl border border-success-border bg-success-bg px-4 py-4 text-success-fg">
                    <Icon name="checkCircle" />
                    <p class="text-sm font-medium">Nothing needs attention. Every system is running and no figure is behind or at risk.</p>
                </div>

                <ul v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="item in attention.slice(0, 9)" :key="item.id">
                        <component
                            :is="item.metric ? 'button' : 'div'"
                            :type="item.metric ? 'button' : undefined"
                            class="flex w-full items-start gap-3 rounded-xl border border-border bg-surface p-3 text-left transition"
                            :class="item.metric ? 'hover:border-primary/40 hover:shadow-md focus-visible:outline-2 focus-visible:outline-focus-ring' : ''"
                            @click="item.metric && open(item.metric)"
                        >
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" :class="toneChip[item.tone]" aria-hidden="true">
                                <Icon :name="toneIcon[item.tone]" size="sm" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-xs font-semibold tracking-wide text-text-muted uppercase">{{ item.where }}</span>
                                <span class="block text-sm font-semibold text-text">{{ item.title }}</span>
                                <span class="block text-sm text-text-muted">{{ item.text }}</span>
                            </span>
                        </component>
                    </li>
                </ul>
                <p v-if="attention.length > 9" class="mt-2 text-sm text-text-muted">And {{ attention.length - 9 }} more. Open a tab below to see them.</p>
            </section>

            <!-- The viewer's own short list. -->
            <section v-if="watched.length" aria-labelledby="watch-title">
                <h2 id="watch-title" class="mb-3 text-lg font-semibold text-text">My watch list</h2>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricTile v-for="{ metric, section } in watched" :key="metric.id" :metric="metric" :section-label="section.label" pinned featured @open="open" @toggle-pin="togglePin" />
                </div>
            </section>

            <Tabs v-model="tab" :tabs="tabs">
                <template #overview>
                    <div class="space-y-8">
                        <section aria-labelledby="systems-title">
                            <h2 id="systems-title" class="mb-3 text-lg font-semibold text-text">Systems</h2>
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                <SystemTile v-for="system in dashboard.systems" :key="system.key" :system="system" />
                            </div>
                        </section>

                        <section v-for="section in modules" :key="section.key" :aria-label="section.label">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h2 class="flex items-center gap-2 text-lg font-semibold text-text">
                                    {{ section.label }}
                                    <Badge v-if="sectionStates[section.state]" :tone="sectionStates[section.state].tone">{{ sectionStates[section.state].label }}</Badge>
                                </h2>
                                <button v-if="section.metrics.length > 3" type="button" class="inline-flex items-center gap-1 text-sm font-medium text-link hover:underline" @click="showTab(section.key)">
                                    All {{ section.metrics.length }} figures
                                    <Icon name="arrowRight" size="sm" />
                                </button>
                            </div>
                            <div v-if="section.metrics.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <MetricTile v-for="metric in keyFigures(section)" :key="metric.id" :metric="metric" :pinned="pins.includes(metric.id)" featured @open="open" @toggle-pin="togglePin" />
                            </div>
                            <p v-else class="text-sm text-text-muted">No figures yet.</p>
                        </section>
                    </div>
                </template>

                <template v-for="section in modules" :key="section.key" #[section.key]>
                    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm text-text-muted">
                        <Badge v-if="sectionStates[section.state]" :tone="sectionStates[section.state].tone">{{ sectionStates[section.state].label }}</Badge>
                        <span v-if="section.collectedAt">Updated {{ formatAge(section.collectedAt) }}</span>
                    </div>
                    <p v-if="section.error" class="mb-3 rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-fg">
                        The last collection failed: {{ section.error }}<template v-if="section.metrics.length"> Showing the last figures received.</template>
                    </p>
                    <div v-if="section.metrics.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <MetricTile v-for="metric in section.metrics" :key="metric.id" :metric="metric" :pinned="pins.includes(metric.id)" @open="open" @toggle-pin="togglePin" />
                    </div>
                    <p v-else-if="!section.error" class="text-sm text-text-muted">No figures yet.</p>
                </template>

                <template #systems>
                    <EmptyState v-if="!systemSections.length" icon="grid" title="No connected system sends figures yet" description="Connected systems appear here once their summary feed is set up in Settings." />
                    <div v-else class="space-y-8">
                        <section v-for="section in systemSections" :key="section.key" :aria-label="section.label">
                            <h2 class="mb-3 flex flex-wrap items-center gap-2 text-lg font-semibold text-text">
                                {{ section.label }}
                                <Badge v-if="sectionStates[section.state]" :tone="sectionStates[section.state].tone">{{ sectionStates[section.state].label }}</Badge>
                                <span v-if="section.collectedAt" class="text-sm font-normal text-text-muted">Updated {{ formatAge(section.collectedAt) }}</span>
                            </h2>
                            <p v-if="section.error" class="mb-3 rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-fg">
                                The last collection failed: {{ section.error }}<template v-if="section.metrics.length"> Showing the last figures received.</template>
                            </p>
                            <div v-if="section.metrics.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <MetricTile v-for="metric in section.metrics" :key="metric.id" :metric="metric" :pinned="pins.includes(metric.id)" @open="open" @toggle-pin="togglePin" />
                            </div>
                            <p v-else-if="!section.error" class="text-sm text-text-muted">No figures yet.</p>
                        </section>
                    </div>
                </template>
            </Tabs>
        </template>

        <MetricDetail :metric="detail" :section-label="detailSection" @close="detail = null" />
    </div>
</template>
