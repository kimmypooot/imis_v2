<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import OrbitCircles from '@cscro8/ui/Components/UI/OrbitCircles.vue';
import Collapse from '@cscro8/ui/Components/UI/Collapse.vue';
import ReleaseItem from '../../Components/Portal/ReleaseItem.vue';
import { imisHistory, isPreRelease } from '../../Components/Portal/imisHistory';
import { formatCalendarDate } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * The release history: a banner for the latest release with its
 * highlights, then a timeline of every release, newest first, beside a
 * side panel (search and the New / Improved / Fixed filter, the road to
 * 3.0.0 while IMIS 3 is in development, and a sticky jump list of the
 * releases with scroll-spy like the User Guide). Entries are bullets with their
 * topic in bold; a release is a month of work, so long lists show their
 * first few until opened. The timeline ends with the versions from before
 * IMIS kept release notes (imisHistory). On phones the panel is just the
 * search and filter, above the timeline.
 */
const props = defineProps({
    releases: { type: Array, required: true },
});

const page = usePage();
const currentVersion = computed(() => page.props.app?.version);

// One look per kind of entry, so a list scans by shape and colour, not just by the word.
const kinds = {
    feature: { label: 'New', icon: 'plus', tile: 'bg-success-bg text-success-fg', dot: 'bg-success', tone: 'success' },
    improvement: { label: 'Improved', icon: 'activity', tile: 'bg-info-bg text-link', dot: 'bg-link', tone: 'info' },
    fix: { label: 'Fixed', icon: 'check', tile: 'bg-warning-bg text-warning-fg', dot: 'bg-warning', tone: 'warning' },
};
const order = ['feature', 'improvement', 'fix'];

/** A release date is a calendar date: read it as that day wherever the viewer is. */
const calendar = (value) => {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day);
};
const monthShort = (value) => calendar(value).toLocaleDateString(undefined, { month: 'short' });
const monthYear = (value) => calendar(value).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;

const filter = ref('all');
const query = ref('');
const needle = computed(() => query.value.trim().toLowerCase());
const showAll = ref(false);
const INITIAL = 5;

// Every release with only the entries matching the search.
const searched = computed(() =>
    props.releases.map((release) => ({
        ...release,
        items: needle.value ? release.items.filter((item) => item.description.toLowerCase().includes(needle.value)) : release.items,
    })),
);

const countOf = (type) => searched.value.reduce((total, release) => total + release.items.filter((item) => item.type === type).length, 0);
const filters = computed(() => [
    { key: 'all', label: 'Everything', icon: 'grid', tile: 'bg-surface-muted text-text-muted', count: searched.value.reduce((total, release) => total + release.items.length, 0) },
    ...order.map((type) => ({ key: type, label: kinds[type].label, icon: kinds[type].icon, tile: kinds[type].tile, count: countOf(type) })),
]);

const latest = computed(() => props.releases[0] ?? null);
const countsOf = (release) => order.map((type) => ({ type, count: release.items.filter((item) => item.type === type).length })).filter((entry) => entry.count);
const latestCounts = computed(() => (latest.value ? countsOf(latest.value) : []));
const inDevelopment = computed(() => isPreRelease(latest.value?.version));

// The latest release's first few new features, by their topic, for the banner.
const highlights = computed(() =>
    (latest.value?.items ?? [])
        .filter((item) => item.type === 'feature')
        .map((item) => {
            const at = item.description.indexOf(': ');
            return at > 2 && at <= 40 ? item.description.slice(0, at) : null;
        })
        .filter(Boolean)
        .slice(0, 3),
);

// Releases with their entries grouped by kind, after the search and filter; releases left empty are hidden.
const timeline = computed(() =>
    searched.value
        .map((release, index) => ({
            ...release,
            isLatest: index === 0,
            total: props.releases[index].items.length,
            counts: countsOf(props.releases[index]),
            groups: order
                .filter((type) => filter.value === 'all' || filter.value === type)
                .map((type) => ({ type, items: release.items.filter((item) => item.type === type) }))
                .filter((group) => group.items.length),
        }))
        .filter((release) => release.groups.length),
);

const shown = computed(() => (showAll.value ? timeline.value : timeline.value.slice(0, INITIAL)));
const matchCount = computed(() => filters.value.find((entry) => entry.key === filter.value)?.count ?? 0);

// Each kind of entry lists its first few; the rest open on request. A search shows every match.
const GROUP_LIMIT = 6;
const expanded = ref(new Set());
const groupKey = (release, group) => `${release.id}-${group.type}`;
const isOpen = (release, group) => expanded.value.has(groupKey(release, group));
const toggleGroup = (key) => (expanded.value.has(key) ? expanded.value.delete(key) : expanded.value.add(key));

// Versions from before release notes, newest first, once the timeline reaches its end.
const releaseVersions = computed(() => new Set(props.releases.map((release) => release.version)));
const earlier = computed(() => imisHistory.filter((milestone) => !milestone.current && !releaseVersions.value.has(milestone.version)).reverse());
const showEarlier = computed(() => filter.value === 'all' && !needle.value && earlier.value.length > 0 && shown.value.length === timeline.value.length);

// The road to 3.0.0, newest first like the timeline: the launch, every development build so far, then the original PHP versions.
const road = computed(() => [
    ...imisHistory
        .filter((milestone) => !milestone.current)
        .map((milestone) => ({
            key: `v${milestone.version}`,
            era: 'Original PHP system',
            version: milestone.version,
            when: [milestone.when, milestone.label].filter(Boolean).join(' · '),
            state: 'done',
        })),
    ...props.releases
        .filter((release) => isPreRelease(release.version))
        .reverse()
        .map((release) => ({
            key: release.id,
            era: 'IMIS 3 rebuild',
            version: release.version,
            when: release.release_date ? monthYear(release.release_date) : '',
            state: release.id === latest.value?.id ? 'current' : 'done',
        })),
    { key: 'launch', era: 'IMIS 3 rebuild', version: '3.0.0', when: 'Launch in 2027', state: 'next' },
].reverse());
const startsEra = (index) => index === 0 || road.value[index - 1].era !== road.value[index].era;

/**
 * Scroll-spy for the jump list: the release being read is the last one
 * whose top has passed just under the fixed header. Near the bottom of the
 * page the last one wins, since it can never reach the top.
 */
const EARLIER_ID = 'earlier-versions';
const releaseId = (release) => `release-${release.id}`;
const jumpIds = computed(() => [...shown.value.map(releaseId), ...(showEarlier.value ? [EARLIER_ID] : [])]);
const activeId = ref(null);
const READING_LINE = 110;
let frame = null;

const update = () => {
    frame = null;
    const ids = jumpIds.value;
    const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
    let current = ids[0] ?? null;

    for (const id of ids) {
        const element = document.getElementById(id);
        if (element && element.getBoundingClientRect().top <= READING_LINE) current = id;
    }

    activeId.value = atBottom ? ids.at(-1) ?? null : current;
};

const schedule = () => {
    if (frame === null) frame = window.requestAnimationFrame(update);
};

watch(jumpIds, () => nextTick(schedule));

onMounted(() => {
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    update();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', schedule);
    window.removeEventListener('resize', schedule);
    if (frame !== null) window.cancelAnimationFrame(frame);
});

const goTo = (id) => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.getElementById(id)?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
};

const clearSearch = () => {
    query.value = '';
    filter.value = 'all';
};
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

    <div v-else class="space-y-8">
        <!-- The latest release, at a glance. -->
        <section class="relative overflow-hidden rounded-lg bg-brand p-6 text-white md:p-8">
            <OrbitCircles />
            <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-xs font-semibold tracking-wide text-white/75 uppercase">
                        <Icon name="megaphone" size="sm" />{{ inDevelopment ? 'Latest development build' : 'Latest release' }}
                    </p>
                    <h2 class="mt-2 text-xl font-bold md:text-2xl">
                        {{ latest.title }}
                        <span class="relative -top-[0.15em] ml-1.5 inline-block rounded-full border border-white/20 bg-white/10 px-2.5 py-0.5 align-middle text-xs font-semibold tracking-wide text-white/80">v{{ latest.version }}</span>
                    </h2>
                    <p v-if="latest.release_date" class="mt-1 text-sm text-white/80">
                        Released <time :datetime="latest.release_date">{{ formatCalendarDate(latest.release_date) }}</time>
                        <template v-if="currentVersion && currentVersion === latest.version"> · you're up to date</template>
                    </p>
                    <ul v-if="highlights.length" class="mt-4 flex flex-wrap gap-2" aria-label="Highlights">
                        <li v-for="highlight in highlights" :key="highlight" class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium ring-1 ring-white/15">
                            <Icon name="checkCircle" size="xs" class="text-white/80" />{{ highlight }}
                        </li>
                    </ul>
                </div>

                <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center lg:flex-col lg:items-end">
                    <ul v-if="latestCounts.length" class="flex gap-3" aria-label="In this release">
                        <li v-for="entry in latestCounts" :key="entry.type" class="min-w-[4.5rem] rounded-lg bg-white/10 px-3 py-2 text-center ring-1 ring-white/15">
                            <span class="block text-xl leading-none font-bold tabular-nums">{{ entry.count }}</span>
                            <span class="mt-1 block text-xs text-white/80">{{ kinds[entry.type].label }}</span>
                        </li>
                    </ul>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 self-start rounded-md px-2 py-1 text-sm font-medium text-white/90 hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring-on-dark lg:self-end"
                        @click="goTo(releaseId(latest))"
                    >
                        Read the notes<Icon name="arrowRight" size="xs" />
                    </button>
                </div>
            </div>
        </section>

        <div class="lg:flex lg:items-start lg:gap-8">
            <!-- On phones: search and the filter chips, above the timeline. -->
            <div class="mb-8 space-y-3 lg:hidden">
                <div class="relative">
                    <label for="release-search-mobile" class="sr-only">Search release notes</label>
                    <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                    <input id="release-search-mobile" v-model="query" type="search" autocomplete="off" placeholder="Search release notes" class="block w-full form-control py-2 pr-3 pl-9" />
                </div>
                <FilterButtons v-model="filter" variant="pills" label="Filter release notes" :options="filters.map((entry) => ({ value: entry.key, label: entry.label, count: entry.count, disabled: !entry.count }))" />
            </div>

            <!-- On wide screens: a sticky panel of cards beside the timeline. -->
            <aside class="hidden w-80 shrink-0 lg:order-last lg:block lg:self-stretch">
                <div class="space-y-4">
                    <Card title="Find a change" icon="search">
                        <div class="relative">
                            <label for="release-search" class="sr-only">Search release notes</label>
                            <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                            <input id="release-search" v-model="query" type="search" autocomplete="off" placeholder="Search release notes" class="block w-full form-control py-2 pr-3 pl-9" />
                        </div>

                        <div class="-mx-2 mt-4 space-y-1" role="group" aria-label="Filter release notes">
                            <button
                                v-for="entry in filters"
                                :key="entry.key"
                                type="button"
                                class="flex w-full items-center gap-3 rounded-md px-2 py-2 text-compact transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:cursor-not-allowed disabled:opacity-50"
                                :class="filter === entry.key ? 'bg-info-bg font-semibold text-link' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
                                :aria-pressed="filter === entry.key"
                                :disabled="!entry.count && filter !== entry.key"
                                @click="filter = entry.key"
                            >
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md" :class="entry.tile" aria-hidden="true">
                                    <Icon :name="entry.icon" size="xs" />
                                </span>
                                <span class="flex-1 text-left">{{ entry.label }}</span>
                                <span class="min-w-[1.75rem] rounded-full px-1.5 text-center text-xs tabular-nums" :class="filter === entry.key ? 'bg-surface' : 'bg-surface-muted'">{{ entry.count }}</span>
                            </button>
                        </div>
                    </Card>

                    <Card v-if="inDevelopment" title="Road to 3.0.0" icon="calendar">
                        <p class="text-sm leading-relaxed text-text-muted">IMIS 3 is still being built. Each month's work comes out as one development build until the launch.</p>
                        <ol class="mt-4">
                            <template v-for="(step, index) in road" :key="step.key">
                                <li v-if="startsEra(index)" class="relative pb-3 pl-9" :class="{ 'pt-1': index > 0 }">
                                    <span v-if="index > 0" class="absolute inset-y-0 left-[0.6875rem] w-0.5 bg-primary/30" aria-hidden="true" />
                                    <span class="text-2xs font-semibold tracking-wide text-text-muted uppercase">{{ step.era }}</span>
                                </li>
                                <li class="relative flex gap-3 pb-4 last:pb-0">
                                    <span v-if="index < road.length - 1" class="absolute top-6 bottom-0 left-[0.6875rem] w-0.5" :class="step.state === 'next' ? 'bg-border' : 'bg-primary/30'" aria-hidden="true" />
                                    <span
                                        class="relative flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                        :class="{
                                            'bg-success-bg text-success-fg': step.state === 'done',
                                            'bg-primary text-white ring-4 ring-primary/15': step.state === 'current',
                                            'border-2 border-dashed border-border-strong bg-surface text-text-muted': step.state === 'next',
                                        }"
                                        aria-hidden="true"
                                    >
                                        <Icon v-if="step.state === 'done'" name="check" size="xs" />
                                        <span v-else-if="step.state === 'current'" class="h-2 w-2 rounded-full bg-white" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-2 text-compact font-semibold text-text">
                                            v{{ step.version }}
                                            <Badge v-if="step.state === 'current'" tone="info" pill>Latest</Badge>
                                        </span>
                                        <span class="block text-xs text-text-muted">{{ step.when }}</span>
                                    </span>
                                </li>
                            </template>
                        </ol>
                    </Card>

                    <Card v-else title="How versions work" icon="info">
                        <p class="text-sm leading-relaxed text-text-muted">
                            IMIS has one release a month (3.1.0, 3.2.0, and so on). A release such as 3.1.1 in between only fixes
                            problems.
                        </p>
                    </Card>
                </div>

                <!-- Only the jump list stays in view, so the panel never needs a scrollbar of its own. -->
                <div class="mt-4 lg:sticky lg:top-20">
                    <Card v-if="jumpIds.length > 1" title="Releases" icon="documentText">
                        <nav aria-label="Releases on this page">
                            <ul class="-mx-2 space-y-0.5">
                                <li v-for="release in shown" :key="release.id">
                                    <a
                                        :href="`#${releaseId(release)}`"
                                        class="flex items-center gap-3 rounded-md px-2 py-2 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                        :class="activeId === releaseId(release) ? 'bg-info-bg' : 'hover:bg-surface-muted'"
                                        :aria-current="activeId === releaseId(release) ? 'location' : undefined"
                                        @click.prevent="goTo(releaseId(release))"
                                    >
                                        <span class="h-2 w-2 shrink-0 rounded-full" :class="activeId === releaseId(release) ? 'bg-primary' : 'bg-border-strong'" aria-hidden="true" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-compact font-semibold" :class="activeId === releaseId(release) ? 'text-link' : 'text-text'">v{{ release.version }}</span>
                                            <span class="block truncate text-xs text-text-muted">
                                                <template v-if="release.release_date">{{ monthYear(release.release_date) }} · </template>{{ plural(release.total, 'change') }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li v-if="showEarlier">
                                    <a
                                        :href="`#${EARLIER_ID}`"
                                        class="flex items-center gap-3 rounded-md px-2 py-2 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                        :class="activeId === EARLIER_ID ? 'bg-info-bg' : 'hover:bg-surface-muted'"
                                        :aria-current="activeId === EARLIER_ID ? 'location' : undefined"
                                        @click.prevent="goTo(EARLIER_ID)"
                                    >
                                        <span class="h-2 w-2 shrink-0 rounded-full border-2" :class="activeId === EARLIER_ID ? 'border-primary' : 'border-border-strong'" aria-hidden="true" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-compact font-semibold" :class="activeId === EARLIER_ID ? 'text-link' : 'text-text'">Earlier versions</span>
                                            <span class="block text-xs text-text-muted">v{{ earlier.at(-1).version }} to v{{ earlier[0].version }}</span>
                                        </span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </Card>
                </div>
            </aside>

            <!-- The timeline: a rail down the left with a node per release. -->
            <div class="min-w-0 flex-1 space-y-8">
                <p class="sr-only" aria-live="polite">{{ needle ? `${plural(matchCount, 'change')} found` : '' }}</p>

                <div v-if="needle && timeline.length" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-info-bg px-4 py-2.5 text-sm text-info-fg">
                    <span>{{ plural(matchCount, 'change') }} matching “{{ query.trim() }}”</span>
                    <button type="button" class="rounded font-medium text-link hover:text-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="clearSearch">Clear search</button>
                </div>

                <ol v-if="timeline.length" class="relative space-y-8 border-l-2 border-border pl-6 sm:pl-8">
                    <li v-for="release in shown" :id="releaseId(release)" :key="release.id" class="relative scroll-mt-20">
                        <span
                            class="absolute top-9 -left-[calc(1.5rem+0.4375rem)] h-3 w-3 rounded-full border-2 border-surface ring-2 sm:-left-[calc(2rem+0.4375rem)]"
                            :class="release.isLatest ? 'bg-accent ring-accent/30' : 'bg-primary ring-border-strong'"
                            aria-hidden="true"
                        />

                        <Card>
                            <template #header>
                                <div class="flex min-w-0 items-start gap-4">
                                    <div
                                        v-if="release.release_date"
                                        class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-lg ring-1 ring-inset"
                                        :class="release.isLatest ? 'bg-primary text-white ring-primary' : 'bg-info-bg text-link ring-info-border'"
                                        aria-hidden="true"
                                    >
                                        <span class="text-2xs font-semibold tracking-wide uppercase">{{ monthShort(release.release_date) }}</span>
                                        <span class="text-base leading-tight font-bold tabular-nums">{{ release.release_date.slice(0, 4) }}</span>
                                    </div>
                                    <div class="min-w-0">
                                        <h2 class="text-base font-semibold text-text md:text-lg">{{ release.title }}</h2>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                            <Badge tone="info" pill>v{{ release.version }}</Badge>
                                            <Badge v-if="isPreRelease(release.version)" tone="neutral" pill>Development build</Badge>
                                            <Badge v-if="release.isLatest" tone="success" pill>Latest</Badge>
                                            <Badge v-if="currentVersion && currentVersion === release.version && !release.isLatest" tone="neutral" pill>You're on this</Badge>
                                            <span v-if="release.release_date" class="text-xs text-text-muted">
                                                <time :datetime="release.release_date">{{ formatCalendarDate(release.release_date) }}</time> · {{ plural(release.total, 'change') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <template #actions>
                                <ul class="hidden gap-1.5 xl:flex" aria-label="In this release">
                                    <li v-for="entry in release.counts" :key="entry.type" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="kinds[entry.type].tile">
                                        <Icon :name="kinds[entry.type].icon" size="xs" />
                                        <span class="tabular-nums">{{ entry.count }}</span> {{ kinds[entry.type].label }}
                                    </li>
                                </ul>
                            </template>

                            <div class="@container divide-y divide-border border-t border-border">
                                <section v-for="group in release.groups" :key="group.type" class="py-5 last:pb-0" :aria-labelledby="`${groupKey(release, group)}-title`">
                                    <h3 :id="`${groupKey(release, group)}-title`" class="mb-3 flex items-center gap-2.5 text-sm font-semibold text-text">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-md" :class="kinds[group.type].tile" aria-hidden="true">
                                            <Icon :name="kinds[group.type].icon" size="xs" />
                                        </span>
                                        {{ kinds[group.type].label }}
                                        <Badge :tone="kinds[group.type].tone" pill>{{ group.items.length }}</Badge>
                                    </h3>

                                    <ul class="gap-8 pl-1 @3xl:columns-2">
                                        <ReleaseItem
                                            v-for="(item, index) in needle ? group.items : group.items.slice(0, GROUP_LIMIT)"
                                            :key="index"
                                            :description="item.description"
                                            :label="kinds[group.type].label"
                                            :dot="kinds[group.type].dot"
                                            :query="query"
                                        />
                                    </ul>

                                    <template v-if="!needle && group.items.length > GROUP_LIMIT">
                                        <Collapse :id="`more-${groupKey(release, group)}`" :open="isOpen(release, group)" class="gap-8 pl-1 @3xl:columns-2">
                                            <ReleaseItem
                                                v-for="(item, index) in group.items.slice(GROUP_LIMIT)"
                                                :key="index"
                                                :description="item.description"
                                                :label="kinds[group.type].label"
                                                :dot="kinds[group.type].dot"
                                            />
                                        </Collapse>
                                        <button
                                            type="button"
                                            class="mt-1 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium text-link transition-colors hover:bg-info-bg focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                            :aria-expanded="isOpen(release, group)"
                                            :aria-controls="`more-${groupKey(release, group)}`"
                                            @click="toggleGroup(groupKey(release, group))"
                                        >
                                            <Icon name="chevronDown" size="xs" class="transition-transform duration-200 motion-reduce:transition-none" :class="{ 'rotate-180': isOpen(release, group) }" />
                                            {{ isOpen(release, group) ? 'Show fewer' : `Show ${group.items.length - GROUP_LIMIT} more` }}
                                        </button>
                                    </template>
                                </section>
                            </div>
                        </Card>
                    </li>

                    <!-- Versions from before IMIS kept release notes. -->
                    <template v-if="showEarlier">
                        <li :id="EARLIER_ID" class="scroll-mt-20 pt-2">
                            <h2 class="flex items-center gap-2 text-sm font-semibold text-text">
                                <Icon name="clock" size="sm" class="text-text-muted" />Earlier versions
                            </h2>
                            <p class="mt-0.5 text-sm text-text-muted">From before IMIS kept release notes.</p>
                        </li>
                        <li v-for="milestone in earlier" :key="milestone.version" class="relative">
                            <span
                                class="absolute top-5 -left-[calc(1.5rem+0.4375rem)] h-3 w-3 rounded-full border-2 border-surface bg-border-strong ring-2 ring-border sm:-left-[calc(2rem+0.4375rem)]"
                                aria-hidden="true"
                            />
                            <div class="rounded-lg bg-surface-muted p-4">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <Badge tone="neutral" pill>v{{ milestone.version }}</Badge>
                                    <h3 class="text-sm font-semibold text-text">{{ milestone.title }}</h3>
                                    <span v-if="milestone.when" class="text-xs text-text-muted">· {{ milestone.when }}</span>
                                </div>
                                <p class="mt-1.5 text-sm leading-relaxed text-text-muted">{{ milestone.text }}</p>
                            </div>
                        </li>
                    </template>
                </ol>

                <EmptyState
                    v-else
                    icon="search"
                    title="No changes match"
                    :description="needle ? `Nothing in the release notes mentions “${query.trim()}”${filter === 'all' ? '' : ' under this filter'}.` : 'No release has a note of this kind.'"
                >
                    <template #action>
                        <Button variant="outline" @click="clearSearch">Clear search and filter</Button>
                    </template>
                </EmptyState>

                <div v-if="timeline.length > INITIAL" class="flex justify-center">
                    <Button variant="outline" @click="showAll = !showAll">
                        {{ showAll ? 'Show fewer releases' : `Show ${timeline.length - INITIAL} older release${timeline.length - INITIAL === 1 ? '' : 's'}` }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
