<script setup>
import { computed, useId } from 'vue';

/**
 * One part-to-whole bar (e.g. staff by sex): segments in the fixed chart
 * colors (--color-chart-1, -2, then neutral for "not recorded"), a 2px
 * surface gap between them, 4px rounded-sm ends, a legend with counts and
 * shares (identity is never color alone), a hover title per segment, and
 * a "View as table". Plain HTML; no chart library needed.
 */
const props = defineProps({
    segments: { type: Array, required: true /** [{ label, value, color: 'chart-1' | 'chart-2' | 'chart-neutral' }] */ },
    label: { type: String, required: true /** what a value counts, e.g. "People" */ },
    summary: { type: String, required: true /** one sentence for screen readers */ },
});

// Literal class names, so Tailwind sees them and emits the chart tokens
// (an inline var(--color-…) alone isn't scanned, and the color is dropped).
const fills = { 'chart-1': 'bg-chart-1', 'chart-2': 'bg-chart-2', 'chart-neutral': 'bg-chart-neutral' };

const id = useId();
const total = computed(() => props.segments.reduce((sum, segment) => sum + segment.value, 0));
const shown = computed(() => props.segments.filter((segment) => segment.value > 0));
const percent = (value) => (total.value ? Math.round((value / total.value) * 100) : 0);
</script>

<template>
    <div>
        <div v-if="total" class="flex h-4 w-full gap-[2px]" role="img" :aria-label="summary" :aria-describedby="`${id}-table`">
            <div
                v-for="segment in shown"
                :key="segment.label"
                class="h-full first:rounded-l last:rounded-r"
                :class="fills[segment.color]"
                :style="{ width: `${(segment.value / total) * 100}%` }"
                :title="`${segment.label}: ${segment.value} (${percent(segment.value)}%)`"
            />
        </div>
        <p v-else class="text-sm text-text-muted">No data yet.</p>

        <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm">
            <li v-for="segment in segments" :key="segment.label" class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-sm" :class="fills[segment.color]" aria-hidden="true" />
                <span class="text-text">{{ segment.label }}</span>
                <span class="font-semibold text-text">{{ segment.value }}</span>
                <span class="text-text-muted">{{ percent(segment.value) }}%</span>
            </li>
        </ul>

        <details class="mt-2 text-xs">
            <summary class="cursor-pointer text-primary hover:underline">View as table</summary>
            <table :id="`${id}-table`" class="mt-2 w-full">
                <thead class="text-left text-text-muted">
                    <tr>
                        <th scope="col" class="py-1">Item</th>
                        <th scope="col" class="py-1 text-right">{{ label }}</th>
                        <th scope="col" class="py-1 text-right">Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="segment in segments" :key="segment.label">
                        <td class="py-1 text-text">{{ segment.label }}</td>
                        <td class="py-1 text-right tabular-nums text-text">{{ segment.value }}</td>
                        <td class="py-1 text-right tabular-nums text-text">{{ percent(segment.value) }}%</td>
                    </tr>
                </tbody>
            </table>
        </details>
    </div>
</template>
