<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import EmptyState from '../UI/EmptyState.vue';
import SystemLaunchCard from './SystemLaunchCard.vue';

/**
 * The legacy dashboard directory: categories of division cards, and a
 * drill-down into one division's systems. The open division lives in the
 * URL (?division=unit-3) via an Inertia partial reload, so it can be
 * bookmarked and the browser's Back button returns to the directory —
 * the legacy version kept it in page state and lost it on refresh.
 */
const props = defineProps({
    directory: { type: Object, required: true /** { categories, systems } */ },
    division: { type: String, default: null },
});

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

const systemCount = (category) => new Set(category.divisions.flatMap((division) => division.system_ids)).size;

let returnFocusKey = null;

const visit = (division) =>
    router.get('/dashboard', division ? { division } : {}, {
        only: ['division'],
        preserveState: true,
        preserveScroll: true,
    });

const open = (division) => {
    returnFocusKey = division.key;
    systemQuery.value = '';
    visit(division.key);
};

const back = () => visit(null);

// Move focus with the view change, and keep the directory in view.
watch(selected, async (now, before) => {
    await nextTick();
    if (now) {
        sectionRef.value?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        headingRef.value?.focus({ preventScroll: true });
    } else if (before && returnFocusKey) {
        sectionRef.value?.querySelector(`[data-division="${returnFocusKey}"]`)?.focus();
    }
});
</script>

<template>
    <section ref="sectionRef" aria-labelledby="directory-heading" class="scroll-mt-20">
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
                            <button type="button" class="hover:text-primary" @click="back">Systems Directory</button>
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
                        class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                    />
                </div>
            </div>

            <div v-if="visibleSystems.length" class="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-3 lg:gap-6">
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
                        placeholder="Search divisions or systems…"
                        class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                    />
                </div>
            </div>

            <EmptyState
                v-if="directory.categories.length === 0"
                icon="grid"
                title="No systems available yet"
                description="Systems registered in the directory will appear here."
            />
            <EmptyState v-else-if="visibleCategories.length === 0" icon="search" title="No matches" description="Try a different search." />

            <div v-else class="space-y-11">
                <div v-for="category in visibleCategories" :key="category.key">
                    <!-- Legacy category label: small caps, tinted icon, count badge, trailing rule. -->
                    <h3 class="mb-4 flex items-center gap-2.5 text-xs font-bold tracking-widest text-text-muted uppercase">
                        <span
                            class="flex h-6.5 w-6.5 shrink-0 items-center justify-center rounded-md"
                            :class="category.type === 'committee' ? 'bg-warning-bg text-warning' : 'bg-info-bg text-primary'"
                            aria-hidden="true"
                        >
                            <Icon :name="icons[category.type]" size="sm" />
                        </span>
                        {{ category.label }}
                        <span
                            v-if="category.type !== 'committee'"
                            class="shrink-0 rounded-full bg-info-bg px-2 py-px text-xs font-semibold tracking-normal text-primary normal-case"
                        >
                            {{ category.divisions.length }} {{ category.divisions.length === 1 ? 'division' : 'divisions' }}
                            · {{ systemCount(category) }} {{ systemCount(category) === 1 ? 'system' : 'systems' }}
                        </span>
                        <span class="h-px flex-1 bg-border" aria-hidden="true" />
                    </h3>

                    <ul class="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-3 lg:gap-6 xl:grid-cols-4">
                        <li v-for="division in category.divisions" :key="division.key">
                            <button
                                type="button"
                                :data-division="division.key"
                                class="group flex h-full w-full rounded-lg border border-border bg-surface text-left transition-colors hover:border-primary hover:bg-info-bg/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                                @click="open(division)"
                            >
                                <span class="flex w-full gap-3.5 p-5">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-info-bg text-primary" aria-hidden="true">
                                        <Icon :name="icons[division.type]" />
                                    </span>
                                    <span class="flex min-w-0 flex-1 flex-col gap-1 pt-1.5">
                                        <span class="text-base leading-snug font-semibold text-text">{{ division.name }}</span>
                                        <span class="line-clamp-2 flex-1 text-sm leading-snug text-text-muted">
                                            {{ division.description }}
                                        </span>
                                        <!-- Looks like the legacy button; the whole card is the control. -->
                                        <span
                                            class="mt-3 flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium whitespace-nowrap text-white transition group-hover:bg-primary-hover group-hover:shadow-md"
                                            aria-hidden="true"
                                        >
                                            <Icon name="chevronRight" size="sm" />
                                            View Systems ({{ division.system_ids.length }})
                                        </span>
                                        <span class="sr-only">View {{ division.system_ids.length }} systems</span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </section>
</template>
