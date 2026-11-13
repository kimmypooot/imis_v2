<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from './Icon.vue';

/**
 * The one pager. Two modes, one look:
 * - Link mode (pass `prevUrl` / `nextUrl`): Previous / "Page x of y" / Next
 *   as Inertia links, for a Laravel paginator. Takes plain URLs rather than
 *   the paginator's `links` array, whose labels contain HTML entities.
 * - Numbered mode (`numbered`): Previous, a compact 1 … 4 5 6 … 12 list and
 *   Next as buttons that emit `change` with the target page (DataTable).
 */
const props = defineProps({
    prevUrl: { type: String, default: null },
    nextUrl: { type: String, default: null },
    currentPage: { type: Number, required: true },
    lastPage: { type: Number, required: true },
    numbered: { type: Boolean, default: false },
    label: { type: String, default: 'Pagination' },
});

defineEmits(['change']);

const pages = computed(() => {
    const count = props.lastPage;
    if (count <= 7) return Array.from({ length: count }, (_, index) => index + 1);

    const set = new Set([1, count, props.currentPage - 1, props.currentPage, props.currentPage + 1].filter((value) => value >= 1 && value <= count));
    const sorted = [...set].sort((a, b) => a - b);

    return sorted.flatMap((value, index) => (index > 0 && value - sorted[index - 1] > 1 ? ['…', value] : [value]));
});
</script>

<template>
    <nav v-if="lastPage > 1" :aria-label="label" :class="numbered ? '' : 'mt-4 flex items-center justify-between gap-3 text-sm'">
        <template v-if="!numbered">
            <Link v-if="prevUrl" :href="prevUrl" class="pager-btn gap-1" preserve-scroll><Icon name="chevronLeft" size="sm" />Previous</Link>
            <span v-else />

            <span class="text-text-muted">Page {{ currentPage }} of {{ lastPage }}</span>

            <Link v-if="nextUrl" :href="nextUrl" class="pager-btn gap-1" preserve-scroll>Next<Icon name="chevronRight" size="sm" /></Link>
            <span v-else />
        </template>

        <ul v-else class="flex flex-wrap items-center gap-1">
            <li>
                <button type="button" class="pager-btn gap-1" :disabled="currentPage === 1" @click="$emit('change', currentPage - 1)"><Icon name="chevronLeft" size="sm" />Previous</button>
            </li>
            <li v-for="(item, index) in pages" :key="`${item}-${index}`">
                <span v-if="item === '…'" class="px-2 text-text-muted" aria-hidden="true">…</span>
                <button
                    v-else
                    type="button"
                    class="pager-btn min-w-9"
                    :class="{ 'is-current': item === currentPage }"
                    :aria-current="item === currentPage ? 'page' : undefined"
                    @click="$emit('change', item)"
                >
                    {{ item }}
                </button>
            </li>
            <li>
                <button type="button" class="pager-btn gap-1" :disabled="currentPage === lastPage" @click="$emit('change', currentPage + 1)">Next<Icon name="chevronRight" size="sm" /></button>
            </li>
        </ul>
    </nav>
</template>

<style scoped>
@reference '../../../css/app.css';

.pager-btn {
    @apply inline-flex items-center justify-center rounded-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-text transition duration-150 active:scale-95 hover:border-primary/40 hover:bg-info-bg hover:text-link focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-border disabled:hover:bg-surface disabled:hover:text-text;
}

.pager-btn.is-current {
    @apply border-primary bg-primary text-white hover:bg-primary hover:text-white;
}
</style>
