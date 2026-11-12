<script setup>
/**
 * Styled wrapper around a plain <table>. Consumers write normal
 * <thead>/<tbody>/<tr>/<th>/<td> markup into the default slot — this only
 * establishes the shared container and the `data-table` look, so it stays
 * a foundation rather than a data-grid (use DataTable for search, sorting
 * and paging).
 *
 * - `loading` shows shimmering placeholder rows instead of the table.
 * - `empty` shows the `empty` slot (use <EmptyState> in it), falling back
 *   to a plain message.
 * - `density` is "compact", "default" or "comfortable".
 * - `sticky` pins the header; give the wrapper a height with `max-h-*`.
 */
import Skeleton from './Skeleton.vue';

defineProps({
    loading: { type: Boolean, default: false },
    empty: { type: Boolean, default: false },
    emptyText: { type: String, default: 'Nothing to show yet.' },
    density: { type: String, default: 'default', validator: (value) => ['compact', 'default', 'comfortable'].includes(value) },
    sticky: { type: Boolean, default: false },
    loadingRows: { type: Number, default: 5 },
});
</script>

<template>
    <div class="table-card" :class="{ 'max-h-[70vh] overflow-y-auto': sticky }">
        <div v-if="loading" class="space-y-3 p-4" role="status" aria-label="Loading">
            <Skeleton v-for="row in loadingRows" :key="row" class="h-5 w-full" :style="{ opacity: 1 - row * 0.12 }" />
        </div>
        <div v-else-if="empty">
            <slot name="empty">
                <p class="px-4 py-10 text-center text-sm text-text-muted">{{ emptyText }}</p>
            </slot>
        </div>
        <table v-else class="data-table" :data-density="density" :data-sticky="sticky ? '' : undefined">
            <slot />
        </table>
    </div>
</template>
