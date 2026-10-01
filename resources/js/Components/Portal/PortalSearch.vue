<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';

/**
 * The compact search box in the application header. Submitting goes to
 * `/search?q=…` (all result types). The `/` and Ctrl/⌘+K shortcuts that
 * focus it are handled once by AppShell, which finds the visible box via
 * the `data-portal-search` attribute — so every instance needs its own `id`.
 */
const props = defineProps({
    id: {
        type: String,
        required: true,
    },
});

const page = usePage();
const inputRef = ref(null);

// On the search page, mirror the current query instead of starting empty.
const currentQuery = computed(() => (page.component === 'Portal/Search' ? page.props.query ?? '' : ''));
const query = ref(currentQuery.value);
watch(currentQuery, (value) => {
    query.value = value;
});

const submit = () => {
    const term = query.value.trim();

    if (term === '') {
        inputRef.value?.focus();
        return;
    }

    router.get('/search', { q: term });
};

const clear = () => {
    query.value = '';
    inputRef.value?.focus();
};

const onKeydown = (event) => {
    if (event.key !== 'Escape') return;

    if (query.value !== '') {
        query.value = '';
    } else {
        inputRef.value?.blur();
    }
};
</script>

<template>
    <form role="search" class="relative w-full max-w-sm" @submit.prevent="submit">
        <label :for="props.id" class="sr-only">Search services, systems, organizational units, and announcements</label>
        <Icon
            name="search"
            size="sm"
            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted"
        />
        <input
            :id="props.id"
            ref="inputRef"
            v-model="query"
            data-portal-search
            type="search"
            enterkeyhint="search"
            autocomplete="off"
            maxlength="100"
            aria-keyshortcuts="/ Control+K Meta+K"
            placeholder="Search the portal…"
            class="block w-full rounded-md border border-border bg-surface-muted py-2 pl-9 pr-9 text-sm text-text placeholder:text-text-muted focus:border-primary focus:bg-surface focus:outline-none focus:ring-2 focus:ring-focus-ring [&::-webkit-search-cancel-button]:hidden"
            @keydown="onKeydown"
        />
        <button
            v-if="query"
            type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            aria-label="Clear search"
            @click="clear"
        >
            <Icon name="close" size="sm" />
        </button>
        <kbd
            v-else
            class="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 rounded border border-border bg-surface px-1.5 font-sans text-xs text-text-muted md:block"
            aria-hidden="true"
            title="Press / to search"
        >/</kbd>
    </form>
</template>
