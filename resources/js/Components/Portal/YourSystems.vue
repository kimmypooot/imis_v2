<script setup>
import { computed, ref } from 'vue';
import Icon from '../UI/Icon.vue';
import ExternalLinkIndicator from './ExternalLinkIndicator.vue';

/**
 * A one-click row of the systems you already have a role in, above the
 * Systems Directory. It comes from your access records, nothing is tracked
 * about what you open. A system under maintenance that you may still open
 * is marked in amber. The directory below stays the full list.
 */
const props = defineProps({
    directory: { type: Object, required: true /** { categories, systems } */ },
});

const SHOWN = 8;
const expanded = ref(false);

const mine = computed(() =>
    Object.values(props.directory.systems)
        .filter((system) => system.role && system.url)
        .sort((a, b) => a.name.localeCompare(b.name)),
);
const shown = computed(() => (expanded.value ? mine.value : mine.value.slice(0, SHOWN)));
</script>

<template>
    <section v-if="mine.length" aria-labelledby="your-systems-heading">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 id="your-systems-heading" class="text-lg font-semibold text-text">Your systems</h2>
                <p class="text-sm text-text-muted">The systems you have a role in, one click away.</p>
            </div>
            <button
                v-if="mine.length > SHOWN"
                type="button"
                class="rounded-sm text-sm font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Show fewer' : `Show all ${mine.length}` }}
            </button>
        </div>

        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
