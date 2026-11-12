<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import OrbitCircles from '../../Components/UI/OrbitCircles.vue';
import { formatDate } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * The release history: a banner for the latest release, then a timeline
 * of every release, newest first, that can be narrowed to New, Improved or
 * Fixed. The dashboard pop-up keeps using the plain ReleaseNotes.
 */
const props = defineProps({
    releases: { type: Array, required: true },
});

const page = usePage();
const currentVersion = computed(() => page.props.app?.version);

// One look per kind of entry, so a list scans by shape and colour, not just by the badge word.
const kinds = {
    feature: { label: 'New', icon: 'plus', tile: 'bg-success-bg text-success-fg', tone: 'success' },
    improvement: { label: 'Improved', icon: 'activity', tile: 'bg-info-bg text-link', tone: 'info' },
    fix: { label: 'Fixed', icon: 'check', tile: 'bg-warning-bg text-warning-fg', tone: 'warning' },
};
const order = ['feature', 'improvement', 'fix'];

const filter = ref('all');
const showAll = ref(false);
const INITIAL = 5;

const countOf = (type) => props.releases.reduce((total, release) => total + release.items.filter((item) => item.type === type).length, 0);
const filters = computed(() => [
    { key: 'all', label: 'Everything', count: props.releases.reduce((total, release) => total + release.items.length, 0) },
    ...order.map((type) => ({ key: type, label: kinds[type].label, count: countOf(type) })).filter((entry) => entry.count),
]);

const latest = computed(() => props.releases[0] ?? null);
const latestCounts = computed(() =>
    latest.value ? order.map((type) => ({ type, count: latest.value.items.filter((item) => item.type === type).length })).filter((entry) => entry.count) : [],
);

// Releases with their entries grouped by kind, after the filter; releases left empty are hidden.
const timeline = computed(() =>
    props.releases
        .map((release, index) => ({
            ...release,
            isLatest: index === 0,
            groups: order
                .filter((type) => filter.value === 'all' || filter.value === type)
                .map((type) => ({ type, items: release.items.filter((item) => item.type === type) }))
                .filter((group) => group.items.length),
        }))
        .filter((release) => release.groups.length),
);

const shown = computed(() => (showAll.value ? timeline.value : timeline.value.slice(0, INITIAL)));
</script>

<template>
    <Head title="What's New" />

    <PageHeader
        title="What's New"
        description="Release notes for IMIS, newest first."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: `What's New` }]"
    />

    <EmptyState
        v-if="releases.length === 0"
        icon="megaphone"
        title="No release notes yet"
        description="Notes about new features and fixes will appear here."
    />

    <div v-else class="max-w-4xl space-y-8">
        <!-- The latest release, at a glance. -->
        <section class="relative overflow-hidden rounded-lg bg-primary p-6 text-white md:p-8">
            <OrbitCircles />
            <div class="relative z-10 flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-xs font-semibold tracking-wide text-white/75 uppercase">
                        <Icon name="megaphone" size="sm" />Latest release
                    </p>
                    <h2 class="mt-2 text-xl font-bold md:text-2xl">
                        {{ latest.title }}
                        <span class="relative -top-[0.15em] ml-1.5 inline-block rounded-full border border-white/20 bg-white/10 px-2.5 py-0.5 align-middle text-xs font-semibold tracking-wide text-white/80">v{{ latest.version }}</span>
                    </h2>
                    <p v-if="latest.release_date" class="mt-1 text-sm text-white/80">
                        Released <time :datetime="latest.release_date">{{ formatDate(latest.release_date) }}</time>
                        <template v-if="currentVersion && currentVersion === latest.version"> · you're up to date</template>
                    </p>
                </div>

                <ul v-if="latestCounts.length" class="flex shrink-0 gap-3" aria-label="In this release">
                    <li v-for="entry in latestCounts" :key="entry.type" class="min-w-[4.5rem] rounded-lg bg-white/10 px-3 py-2 text-center ring-1 ring-white/15">
                        <span class="block text-xl leading-none font-bold tabular-nums">{{ entry.count }}</span>
                        <span class="mt-1 block text-xs text-white/80">{{ kinds[entry.type].label }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Narrow the history to one kind of change. -->
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter release notes">
            <button
                v-for="entry in filters"
                :key="entry.key"
                type="button"
                class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="filter === entry.key ? 'border-primary bg-primary font-medium text-white' : 'border-border bg-surface text-text-muted hover:border-primary/40 hover:text-text'"
                :aria-pressed="filter === entry.key"
                @click="filter = entry.key"
            >
                {{ entry.label }}
                <span class="rounded-full px-1.5 text-xs tabular-nums" :class="filter === entry.key ? 'bg-white/20' : 'bg-surface-muted'">{{ entry.count }}</span>
            </button>
        </div>

        <!-- The timeline: a rail down the left with a node per release. -->
        <ol class="relative space-y-8 border-l-2 border-border pl-6 sm:pl-8">
            <li v-for="release in shown" :key="release.id" class="relative">
                <span
                    class="absolute top-6 -left-[calc(1.5rem+0.4375rem)] h-3 w-3 rounded-full border-2 border-surface ring-2 sm:-left-[calc(2rem+0.4375rem)]"
                    :class="release.isLatest ? 'bg-accent ring-accent/30' : 'bg-primary ring-border-strong'"
                    aria-hidden="true"
                />

                <Card>
                    <template #header>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <h2 class="text-base font-semibold text-text">{{ release.title }}</h2>
                                <span class="rounded-full bg-info-bg px-2.5 py-0.5 text-xs font-medium text-link">v{{ release.version }}</span>
                                <Badge v-if="release.isLatest" tone="success">Latest</Badge>
                                <Badge v-if="currentVersion && currentVersion === release.version && !release.isLatest" tone="neutral">You're on this</Badge>
                            </div>
                            <time v-if="release.release_date" :datetime="release.release_date" class="mt-1 flex items-center gap-1.5 text-xs text-text-muted">
                                <Icon name="calendar" size="xs" />{{ formatDate(release.release_date) }}
                            </time>
                        </div>
                    </template>

                    <div class="space-y-5">
                        <section v-for="group in release.groups" :key="group.type" :aria-label="kinds[group.type].label">
                            <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-text-muted uppercase">
                                <span class="flex h-6 w-6 items-center justify-center rounded-md" :class="kinds[group.type].tile" aria-hidden="true">
                                    <Icon :name="kinds[group.type].icon" size="xs" />
                                </span>
                                {{ kinds[group.type].label }}
                                <span class="font-normal tabular-nums">({{ group.items.length }})</span>
                            </h3>
                            <ul class="space-y-2 pl-8">
                                <li v-for="(item, index) in group.items" :key="index" class="text-sm leading-relaxed text-text">
                                    <span class="sr-only">{{ kinds[group.type].label }}: </span>{{ item.description }}
                                </li>
                            </ul>
                        </section>
                    </div>
                </Card>
            </li>
        </ol>

        <p v-if="!timeline.length" class="rounded-lg border border-dashed border-border-strong p-6 text-center text-sm text-text-muted">
            No release has a note of this kind.
        </p>

        <div v-if="timeline.length > INITIAL" class="flex justify-center">
            <Button variant="outline" @click="showAll = !showAll">
                {{ showAll ? 'Show fewer releases' : `Show ${timeline.length - INITIAL} older release${timeline.length - INITIAL === 1 ? '' : 's'}` }}
            </Button>
        </div>
    </div>
</template>
