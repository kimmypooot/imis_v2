<script setup>
import { computed, onMounted, ref } from 'vue';
import Icon from '../UI/Icon.vue';
import ExternalLinkIndicator from './ExternalLinkIndicator.vue';

/**
 * A one-click row of the systems you already have a role in, above the
 * Systems Directory: the four you opened most recently (from the launch
 * records IMIS already keeps), filled up from your other systems when you
 * have opened fewer. Nothing extra is tracked. A system under maintenance that you may still open
 * is marked in amber. The directory below stays the full list. The row can
 * be hidden; the choice is remembered on this browser.
 */
const props = defineProps({
    directory: { type: Object, required: true /** { categories, systems } */ },
    // Ids of the systems opened most recently, newest first.
    recent: { type: Array, default: () => [] },
});

const SHOWN = 4;
const expanded = ref(false);

const HIDDEN_KEY = 'imis.hide-your-systems';
const hidden = ref(false);

onMounted(() => {
    try {
        hidden.value = window.localStorage.getItem(HIDDEN_KEY) === '1';
    } catch {
        // Storage unavailable — the row simply stays visible.
    }
});

const toggleHidden = () => {
    hidden.value = !hidden.value;

    try {
        window.localStorage.setItem(HIDDEN_KEY, hidden.value ? '1' : '0');
    } catch {
        // Storage unavailable — the choice lasts until the page reloads.
    }
};

// Systems you can open: the ones you opened lately first, then the rest A to Z.
const mine = computed(() => {
    const rank = (system) => {
        const position = props.recent.indexOf(system.id);

        return position === -1 ? Infinity : position;
    };

    return Object.values(props.directory.systems)
        .filter((system) => system.role && system.url)
        .sort((a, b) => (rank(a) === rank(b) ? a.name.localeCompare(b.name) : rank(a) - rank(b)));
});
const hasRecent = computed(() => mine.value.some((system) => props.recent.includes(system.id)));
const shown = computed(() => (expanded.value ? mine.value : mine.value.slice(0, SHOWN)));
</script>

<template>
    <section v-if="mine.length" aria-labelledby="your-systems-heading">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 id="your-systems-heading" class="text-lg font-semibold text-text">Your systems</h2>
                <p v-if="!hidden" class="text-sm text-text-muted">{{ hasRecent ? 'The ones you opened most recently, one click away.' : 'The systems you have a role in, one click away.' }}</p>
            </div>
            <div class="flex items-center gap-4">
                <button
                    v-if="!hidden && mine.length > SHOWN"
                    type="button"
                    class="rounded-sm text-sm font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :aria-expanded="expanded"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? 'Show fewer' : `Show all ${mine.length}` }}
                </button>
                <button
                    type="button"
                    class="rounded-sm text-sm font-medium text-text-muted hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :aria-expanded="!hidden"
                    aria-controls="your-systems-list"
                    @click="toggleHidden"
                >
                    {{ hidden ? 'Show' : 'Hide' }}
                </button>
            </div>
        </div>

        <ul v-show="!hidden" id="your-systems-list" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <li v-for="system in shown" :key="system.id">
                <a
                    :href="`/applications/${system.id}/launch`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="group flex h-full items-center gap-3 rounded-lg border bg-surface p-3.5 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :class="system.under_maintenance ? 'border-bypass/40 hover:border-bypass' : 'border-border hover:border-success/60'"
                >
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg transition-transform duration-200 group-hover:scale-105"
                        :class="system.under_maintenance ? 'bg-warning-bg text-bypass' : 'bg-success-bg text-success'"
                        aria-hidden="true"
                    >
                        <Icon :name="system.icon || 'grid'" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-text">{{ system.short_name || system.name }}</span>
                        <span class="block truncate text-xs text-text-muted">
                            <template v-if="system.under_maintenance">Under maintenance · </template>{{ system.role }}
                        </span>
                    </span>
                    <ExternalLinkIndicator class="shrink-0 text-text-muted group-hover:text-success" />
                </a>
            </li>
        </ul>
    </section>
</template>
