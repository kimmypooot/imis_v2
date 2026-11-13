<script setup>
import { computed } from 'vue';
import { formatValue } from './format';

/**
 * A trend line with a soft area and a dot on the latest value, for a
 * figure's daily series. Nulls are skipped; fewer than two points draw
 * nothing. `tall` is the larger version used in the detail dialog.
 */
const props = defineProps({
    trend: { type: Array, required: true /** [{ date, value }] */ },
    unit: { type: String, default: 'count' },
    tall: { type: Boolean, default: false },
});

const WIDTH = 100;
const HEIGHT = 32;

const points = computed(() => props.trend.filter((point) => point.value !== null));
const coordinates = computed(() => {
    const values = points.value.map((point) => point.value);
    if (values.length < 2) return [];

    const min = Math.min(...values);
    const span = Math.max(...values) - min || 1;

    return values.map((value, index) => [(index / (values.length - 1)) * WIDTH, HEIGHT - 3 - ((value - min) / span) * (HEIGHT - 8)]);
});
const line = computed(() => coordinates.value.map(([x, y]) => `${x},${y}`).join(' '));
const area = computed(() => (coordinates.value.length ? `0,${HEIGHT} ${line.value} ${WIDTH},${HEIGHT}` : ''));
const summary = computed(() => {
    if (coordinates.value.length < 2) return '';
    const first = points.value[0];
    const latest = points.value[points.value.length - 1];

    return `Trend since ${first.date}: from ${formatValue(first.value, props.unit)} to ${formatValue(latest.value, props.unit)}.`;
});
</script>

<template>
    <svg v-if="coordinates.length" :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" preserveAspectRatio="none" :class="tall ? 'h-32 w-full' : 'h-8 w-24'" role="img" :aria-label="summary">
        <polygon :points="area" class="fill-csc-blue-light/10" />
        <polyline :points="line" fill="none" class="stroke-csc-blue-light" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
    </svg>
</template>
