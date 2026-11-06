<script setup>
import InputLabel from '../../Components/Forms/InputLabel.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import Spinner from '../../Components/UI/Spinner.vue';
import Pagination from '../../Components/UI/Pagination.vue';
import SearchResultItem from '../../Components/Search/SearchResultItem.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    query: { type: String, required: true },
    type: { type: String, default: null },
    typeOptions: { type: Array, required: true },
    minLength: { type: Number, required: true },
    maxLength: { type: Number, required: true },
    // null until a searchable query is given.
    counts: { type: Object, default: null },
    // "All" view: [{ type, label, total, items }], only types that matched.
    groups: { type: Array, default: () => [] },
    // Filtered view: a Laravel paginator of results.
    results: { type: Object, default: null },
});

const term = ref(props.query);
watch(
    () => props.query,
    (value) => {
        term.value = value;
    },
);

const inputRef = ref(null);

// --- Loading state: any visit to /search started from this page. -------
const loading = ref(false);
const removeListeners = [
    router.on('start', (event) => {
        loading.value = event.detail.visit.url.pathname === '/search';
    }),
    router.on('finish', () => {
        loading.value = false;
    }),
];
onBeforeUnmount(() => removeListeners.forEach((remove) => remove()));

// --- Derived state -------------------------------------------------------
const tooShort = computed(() => props.query !== '' && props.counts === null);
const total = computed(() =>
    props.counts ? Object.values(props.counts).reduce((sum, count) => sum + count, 0) : 0,
);
const activeLabel = computed(() => props.typeOptions.find((option) => option.value === props.type)?.label);

const statusMessage = computed(() => {
    if (loading.value) return 'Searching…';
    if (!props.counts) return '';

    const shown = props.type ? props.counts[props.type] : total.value;
    const noun = shown === 1 ? 'result' : 'results';
    const scope = props.type ? ` in ${activeLabel.value}` : '';

    return `${shown} ${noun} for “${props.query}”${scope}`;
});

const searchUrl = (type = null) => {
    const params = new URLSearchParams({ q: props.query });
    if (type) params.set('type', type);

    return `/search?${params}`;
};

// --- Actions -------------------------------------------------------------
const submit = () => {
    const q = term.value.trim();

    if (q === '') {
        inputRef.value?.focus();
        return;
    }

    // Keep the current type filter; a new query starts at page 1.
    router.get('/search', props.type ? { q, type: props.type } : { q }, { preserveScroll: true });
};

const clear = () => {
    term.value = '';
    inputRef.value?.focus();
};

const onKeydown = (event) => {
    if (event.key === 'Escape' && term.value !== '') {
        event.preventDefault();
        clear();
    }
};
</script>

<template>
    <Head :title="query ? `Search: ${query}` : 'Search'" />

    <PageHeader
        title="Search"
        description="Find services, systems, organizational units, and announcements you have access to."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Search' }]"
    />

    <form role="search" class="mb-6 max-w-2xl" @submit.prevent="submit">
        <InputLabel for="search-page-input">Search the portal</InputLabel>
        <div class="mt-1 flex flex-col gap-2 sm:flex-row">
            <div class="relative flex-1">
                <Icon
                    name="search"
                    size="sm"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted"
                />
                <input
                    id="search-page-input"
                    ref="inputRef"
                    v-model="term"
                    data-portal-search
                    type="search"
                    enterkeyhint="search"
                    autocomplete="off"
                    :maxlength="maxLength"
                    aria-describedby="search-page-hint"
                    :autofocus="query === ''"
                    class="form-control block w-full py-2.5 pl-9 pr-10 [&::-webkit-search-cancel-button]:hidden"
                    @keydown="onKeydown"
                />
                <button
                    v-if="term"
                    type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-sm p-1 text-text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    aria-label="Clear search"
                    @click="clear"
                >
                    <Icon name="close" size="sm" />
                </button>
            </div>
            <Button type="submit" :loading="loading">Search</Button>
        </div>
        <p id="search-page-hint" class="mt-1 text-xs text-text-muted">
            Press <kbd class="rounded-sm border border-border bg-surface px-1 font-sans">/</kbd> anywhere to jump here.
            Esc clears the box.
        </p>
    </form>

    <!-- Type filter: plain links (real URLs, back-button friendly). -->
    <nav v-if="counts" aria-label="Filter results by type" class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <ul class="flex min-w-max gap-2">
            <li>
                <Link
                    :href="searchUrl()"
                    preserve-scroll
                    :aria-current="type === null ? 'page' : undefined"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :class="type === null ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text hover:bg-surface-muted'"
                >
                    All <span :class="type === null ? 'text-white' : 'text-text-muted'">({{ total }})</span>
                </Link>
            </li>
            <li v-for="option in typeOptions" :key="option.value">
                <Link
                    :href="searchUrl(option.value)"
                    preserve-scroll
                    :aria-current="type === option.value ? 'page' : undefined"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :class="type === option.value ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text hover:bg-surface-muted'"
                >
                    {{ option.label }}
                    <span :class="type === option.value ? 'text-white' : 'text-text-muted'">({{ counts[option.value] }})</span>
                </Link>
            </li>
        </ul>
    </nav>

    <p role="status" aria-live="polite" class="mb-4 flex min-h-5 items-center gap-2 text-sm text-text-muted">
        <Spinner v-if="loading" size="sm" label="Searching…" />
        {{ statusMessage }}
    </p>

    <div :aria-busy="loading" :class="{ 'opacity-60 transition-opacity': loading }">
        <EmptyState
            v-if="query === ''"
            icon="search"
            title="Search the portal"
            description="Type a keyword such as a service name, system, office, or announcement title."
        />

        <EmptyState
            v-else-if="tooShort"
            icon="search"
            title="Keep typing"
            :description="`Enter at least ${minLength} characters to search.`"
        />

        <EmptyState
            v-else-if="total === 0"
            icon="search"
            :title="`No results for “${query}”`"
            description="Check the spelling, try a shorter or more general keyword, or browse the directories."
        >
            <template #action>
                <div class="flex flex-wrap justify-center gap-x-4 gap-y-1 text-sm">
                    <Link href="/services" class="text-primary underline">Services</Link>
                    <Link href="/applications" class="text-primary underline">Systems / Applications</Link>
                    <Link href="/organizational-units" class="text-primary underline">Organizational Units</Link>
                    <Link href="/announcements" class="text-primary underline">Announcements</Link>
                </div>
            </template>
        </EmptyState>

        <!-- Filtered: one type, paginated. -->
        <section v-else-if="type && results" aria-labelledby="results-heading">
            <h2 id="results-heading" class="mb-3 text-base font-semibold text-text">{{ activeLabel }}</h2>

            <ul v-if="results.data.length" class="space-y-3">
                <SearchResultItem v-for="result in results.data" :key="`${result.type}-${result.id}`" :result="result" />
            </ul>
            <EmptyState
                v-else
                icon="search"
                :title="`No ${activeLabel} match “${query}”`"
                description="Other types have matches — choose All above to see them."
            />

            <Pagination
                :prev-url="results.prev_page_url"
                :next-url="results.next_page_url"
                :current-page="results.current_page"
                :last-page="results.last_page"
            />
        </section>

        <!-- All types: top matches per group. -->
        <div v-else class="space-y-8">
            <section v-for="group in groups" :key="group.type" :aria-labelledby="`group-${group.type}`">
                <div class="mb-3 flex items-baseline justify-between gap-3">
                    <h2 :id="`group-${group.type}`" class="text-base font-semibold text-text">
                        {{ group.label }} <span class="font-normal text-text-muted">({{ group.total }})</span>
                    </h2>
                    <Link
                        v-if="group.total > group.items.length"
                        :href="searchUrl(group.type)"
                        class="shrink-0 text-sm font-medium text-primary hover:text-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    >
                        View all {{ group.total }}<span class="sr-only"> {{ group.label }} results</span>
                    </Link>
                </div>
                <ul class="space-y-3">
                    <SearchResultItem v-for="result in group.items" :key="`${result.type}-${result.id}`" :result="result" />
                </ul>
            </section>
        </div>
    </div>
</template>
