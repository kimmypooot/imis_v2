<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import SystemLaunchCard from './SystemLaunchCard.vue';

/**
 * The legacy dashboard directory, in a card on the dashboard: categories of
 * division cards, and a drill-down into one division's systems that slides
 * in and out. The open division lives in the URL (?division=unit-3) through
 * a client-side visit, so it can be bookmarked and the browser's Back
 * button returns to the directory — the legacy version kept it in page
 * state and lost it on refresh.
 */
const props = defineProps({
    directory: { type: Object, required: true /** { categories, systems } */ },
    division: { type: String, default: null },
});

// Cards per row: three at most, so they fit beside the dashboard's side column.
const gridClass = 'grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3';

const icons = { unit: 'building', committee: 'users', other: 'grid' };

const sectionRef = ref(null);
const headingRef = ref(null);
const query = ref('');
const systemQuery = ref('');

const systemsOf = (division) => division.system_ids.map((id) => props.directory.systems[id]).filter(Boolean);

const selected = computed(() => {
    for (const category of props.directory.categories) {
        const match = category.divisions.find((division) => division.key === props.division);
        if (match) return { ...match, category: category.label };
    }

    return null;
});

const matches = (text, term) => (text || '').toLowerCase().includes(term);

// Search covers division names/descriptions and the names of their systems.
const visibleCategories = computed(() => {
    const term = query.value.trim().toLowerCase();

    return props.directory.categories
        .map((category) => ({
            ...category,
            divisions: category.divisions.filter(
                (division) =>
                    !term ||
                    [division.name, division.short_name, division.description].some((text) => matches(text, term)) ||
                    systemsOf(division).some((system) => matches(system.name, term) || matches(system.short_name, term)),
            ),
        }))
        .filter((category) => category.divisions.length);
});

const visibleSystems = computed(() => {
    if (!selected.value) return [];
    const term = systemQuery.value.trim().toLowerCase();

    return systemsOf(selected.value).filter(
        (system) => !term || [system.name, system.short_name, system.description].some((text) => matches(text, term)),
    );
});

// Systems matching the search, so typing a system's name lands on the system
// itself (with the offices it sits under) instead of only on its division.
const MAX_SYSTEM_RESULTS = 12;
const divisionsOf = computed(() => {
    const map = {};
    for (const category of props.directory.categories) {
        for (const division of category.divisions) {
            for (const id of division.system_ids) (map[id] ??= []).push(division.name);
        }
    }

    return map;
});
const systemMatches = computed(() => {
    const term = query.value.trim().toLowerCase();
    if (!term) return [];

    return Object.values(props.directory.systems).filter((system) => [system.name, system.short_name, system.description].some((text) => matches(text, term)));
});

// Press / anywhere (outside a field) to jump to the directory search.
const focusSearch = (event) => {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;

    const target = event.target;
    if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;

    const input = document.getElementById(selected.value ? 'system-search' : 'division-search');
    if (!input) return;

    event.preventDefault();
    input.focus();
    input.select();
};
onMounted(() => document.addEventListener('keydown', focusSearch));
onBeforeUnmount(() => document.removeEventListener('keydown', focusSearch));

const systemCount = (category) => new Set(category.divisions.flatMap((division) => division.system_ids)).size;

let returnFocusKey = null;

// Opening a division needs no server round trip: every division and system is
// already on the page, so a client-side visit just sets the `division` prop
// and the URL (?division=unit-3). Bookmarks and Back still work, and it stays
// on whichever page shows the directory (the dashboard or its preview).
const visit = (division) => {
    const path = window.location.pathname;

    router.push({
        url: division ? `${path}?division=${encodeURIComponent(division)}` : path,
        props: (current) => ({ ...current, division }),
        preserveState: true,
        preserveScroll: true,
    });
};

const open = (division) => {
    returnFocusKey = division.key;
    systemQuery.value = '';
    visit(division.key);
};

const back = () => visit(null);

// Which way the view slides: in to a division, or back out to the directory.
// A pre-flush watcher, so it is set before the swap renders, also when the
// browser's Back/Forward buttons change the division.
const direction = ref('forward');
watch(selected, (now) => {
    direction.value = now ? 'forward' : 'back';
});

// Once the new view has slid in: move focus with it, and keep the directory
// in view. (With mode="out-in" the new view only exists after the old one
// has left, so this waits for the transition rather than the next tick.)
const afterViewChange = () => {
    if (selected.value) {
        sectionRef.value?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        headingRef.value?.focus({ preventScroll: true });
    } else if (returnFocusKey) {
        sectionRef.value?.querySelector(`[data-division="${returnFocusKey}"]`)?.focus();
    }
};
</script>

<template>
    <section ref="sectionRef" aria-labelledby="directory-heading" class="scroll-mt-20 rounded-lg border border-border bg-surface p-5 sm:p-6">
        <!-- Slides toward where you are going: in to a division, back out to the directory. -->
        <Transition
            mode="out-in"
            @after-enter="afterViewChange"
            enter-active-class="transition duration-200 ease-out"
            leave-active-class="transition duration-150 ease-in"
            :enter-from-class="direction === 'forward' ? 'translate-x-4 opacity-0' : '-translate-x-4 opacity-0'"
            :leave-to-class="direction === 'forward' ? '-translate-x-4 opacity-0' : 'translate-x-4 opacity-0'"
        >
            <div :key="selected?.key ?? 'directory'">
                <!-- Division drill-down -->
                <template v-if="selected">
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                            @click="back"
                        >
                            <Icon name="chevronLeft" size="sm" />
                            Back
                        </button>
                        <nav aria-label="Directory breadcrumb" class="text-sm text-text-muted">
                            <ol class="flex flex-wrap items-center gap-1.5">
                                <li>
                                    <button type="button" class="hover:text-link" @click="back">Systems Directory</button>
                                </li>
                                <li aria-hidden="true">/</li>
                                <li>{{ selected.category }}</li>
                                <li aria-hidden="true">/</li>
                                <li aria-current="page" class="font-medium text-text">{{ selected.name }}</li>
                            </ol>
                        </nav>
                    </div>

                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 id="directory-heading" ref="headingRef" tabindex="-1" class="text-lg font-semibold text-text focus:outline-none">
                                {{ selected.name }}
                            </h2>
                            <p v-if="selected.description" class="text-sm text-text-muted">{{ selected.description }}</p>
                        </div>
                        <div class="w-full sm:w-72">
                            <label for="system-search" class="sr-only">Search systems in {{ selected.name }}</label>
                            <input
                                id="system-search"
                                v-model="systemQuery"
                                type="search"
                                placeholder="Search systems…"
                                class="block w-full form-control px-3 py-2 text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                            />
                        </div>
                    </div>

                    <div v-if="visibleSystems.length" class="stagger" :class="gridClass">
                        <SystemLaunchCard v-for="system in visibleSystems" :key="system.id" :system="system" />
                    </div>
                    <EmptyState v-else icon="search" title="No systems match" description="Try a different search." />
                </template>

                <!-- Directory -->
                <template v-else>
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 id="directory-heading" class="text-lg font-semibold text-text">Systems Directory</h2>
                            <p class="text-sm text-text-muted">Browse systems by office or committee.</p>
                        </div>
                        <div class="w-full sm:w-80">
                            <label for="division-search" class="sr-only">Search divisions or systems</label>
                            <input
                                id="division-search"
                                v-model="query"
                                type="search"
                                placeholder="Search divisions or systems…  ( / )"
                                class="block w-full form-control px-3 py-2 text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                            />
                        </div>
                    </div>

                    <EmptyState
                        v-if="directory.categories.length === 0"
                        icon="grid"
                        title="No systems available yet"
                        description="Systems registered in the directory will appear here."
                    />
                    <EmptyState v-else-if="visibleCategories.length === 0 && systemMatches.length === 0" icon="search" title="No matches" description="Try a different search." />

                    <!-- Typing a system's name: the systems themselves first, one click from launch. -->
                    <div v-if="systemMatches.length" class="mb-10">
                        <h3 class="mb-4 flex items-center gap-2.5 text-xs font-bold tracking-widest text-text-muted uppercase">
                            <span class="flex h-6.5 w-6.5 shrink-0 items-center justify-center rounded-md bg-success-bg text-success-fg" aria-hidden="true"><Icon name="grid" size="sm" /></span>
                            Systems
                            <span class="shrink-0 rounded-full bg-success-bg px-2 py-px text-xs font-semibold tracking-normal text-success-fg normal-case">{{ systemMatches.length }} found</span>
                            <span class="h-px flex-1 bg-border" aria-hidden="true" />
                        </h3>
                        <ul :class="gridClass">
                            <li v-for="system in systemMatches.slice(0, MAX_SYSTEM_RESULTS)" :key="system.id">
                                <SystemLaunchCard :system="system" />
                                <p v-if="divisionsOf[system.id]?.length" class="mt-1.5 px-1 text-xs text-text-muted">In {{ divisionsOf[system.id].join(', ') }}</p>
                            </li>
                        </ul>
                        <p v-if="systemMatches.length > MAX_SYSTEM_RESULTS" class="mt-3 text-sm text-text-muted">
                            Showing {{ MAX_SYSTEM_RESULTS }} of {{ systemMatches.length }}. Type more of the name to narrow it down.
                        </p>
                    </div>

                    <div v-if="visibleCategories.length" class="space-y-11">
                        <div v-for="category in visibleCategories" :key="category.key">
                            <!-- Legacy category label: small caps, tinted icon, count badge, trailing rule. -->
                            <h3 class="mb-4 flex items-center gap-2.5 text-xs font-bold tracking-widest text-text-muted uppercase">
                                <span
                                    class="flex h-6.5 w-6.5 shrink-0 items-center justify-center rounded-md"
                                    :class="category.type === 'committee' ? 'bg-warning-bg text-warning-fg' : 'bg-info-bg text-link'"
                                    aria-hidden="true"
                                >
                                    <Icon :name="icons[category.type]" size="sm" />
                                </span>
                                {{ category.label }}
                                <span
                                    v-if="category.type !== 'committee'"
                                    class="shrink-0 rounded-full bg-info-bg px-2 py-px text-xs font-semibold tracking-normal text-link normal-case"
                                >
                                    {{ category.divisions.length }} {{ category.divisions.length === 1 ? 'division' : 'divisions' }}
                                    · {{ systemCount(category) }} {{ systemCount(category) === 1 ? 'system' : 'systems' }}
                                </span>
                                <span class="h-px flex-1 bg-border" aria-hidden="true" />
                            </h3>

                            <ul :class="gridClass">
                                <li v-for="division in category.divisions" :key="division.key">
                                    <button
                                        type="button"
                                        :data-division="division.key"
                                        class="group flex h-full w-full rounded-lg border border-border bg-surface text-left transition-colors hover:border-primary hover:bg-info-bg/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                                        @click="open(division)"
                                    >
                                        <!-- The icon beside the name; the description and the legacy button full width below. -->
                                        <span class="flex w-full flex-col gap-2 p-4">
                                            <span class="flex items-center gap-3">
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-info-bg text-link" aria-hidden="true">
                                                    <Icon :name="icons[division.type]" size="sm" />
                                                </span>
                                                <span class="min-w-0 text-base leading-snug font-semibold text-text">{{ division.name }}</span>
                                            </span>
                                            <span class="line-clamp-2 flex-1 text-sm leading-snug text-text-muted">
                                                {{ division.description }}
                                            </span>
                                            <span
                                                class="mt-2 flex min-h-10 w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium whitespace-nowrap text-white transition group-hover:bg-primary-hover group-hover:shadow-md"
                                                aria-hidden="true"
                                            >
                                                <Icon name="arrowCircleRight" size="sm" />
                                                View Systems ({{ division.system_ids.length }})
                                            </span>
                                            <span class="sr-only">View {{ division.system_ids.length }} systems</span>
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </template>
            </div>
        </Transition>
    </section>
</template>
