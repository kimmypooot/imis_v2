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
                        class="block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                    />
                </div>
            </div>

            <div v-if="visibleSystems.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
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
                        class="block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
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

            <div v-else class="space-y-6">
                <div v-for="category in visibleCategories" :key="category.key">
                    <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-text">
                        <span class="flex h-7 w-7 items-center justify-center rounded-md bg-csc-blue text-white" aria-hidden="true">
                            <Icon :name="icons[category.type]" size="sm" />
                        </span>
                        {{ category.label }}
                        <span class="rounded-full bg-surface px-2 py-0.5 text-xs font-medium text-text-muted ring-1 ring-border">
                            {{ category.divisions.length }} {{ category.divisions.length === 1 ? 'division' : 'divisions' }}
                            · {{ systemCount(category) }} {{ systemCount(category) === 1 ? 'system' : 'systems' }}
                        </span>
                    </h3>

                    <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <li v-for="division in category.divisions" :key="division.key">
                            <button
                                type="button"
                                :data-division="division.key"
                                class="flex h-full w-full gap-3 rounded-lg border border-border bg-surface p-4 text-left transition-colors hover:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                @click="open(division)"
                            >
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                                    <Icon :name="icons[division.type]" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium text-text">{{ division.name }}</span>
                                    <span v-if="division.description" class="mt-0.5 line-clamp-2 block text-sm text-text-muted">
                                        {{ division.description }}
                                    </span>
                                    <span class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary">
                                        View systems ({{ division.system_ids.length }})
                                        <Icon name="chevronRight" size="sm" />
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
