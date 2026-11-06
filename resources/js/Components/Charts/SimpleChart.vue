<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

// Tree-shaken: only what these two chart forms need is bundled.
Chart.register(BarController, BarElement, CategoryScale, LinearScale, LineController, LineElement, PointElement, Tooltip);

/**
 * One single-series chart for the admin dashboard — a line (change over
 * time) or a horizontal bar (comparing categories). Follows the dataviz
 * method: one validated hue (--color-csc-blue-light, checked for band,
 * chroma, and contrast), thin marks with 4px rounded-sm ends, hairline grid,
 * no legend for a single series (the card title names it), hover
 * tooltips, and a "View as table" so no value is tooltip-only.
 */
const props = defineProps({
    type: { type: String, required: true, validator: (value) => ['line', 'bar'].includes(value) },
    labels: { type: Array, required: true },
    values: { type: Array, required: true },
    label: { type: String, required: true /** what a value counts, e.g. "Sign-ins" */ },
    summary: { type: String, required: true /** one sentence for screen readers */ },
    height: { type: Number, default: 260 },
});

const id = useId();
const canvasRef = ref(null);
let chart = null;

const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

const build = () => {
    const series = token('--color-csc-blue-light');
    const ink = token('--color-text-muted');
    const hairline = token('--color-border');
    const horizontal = props.type === 'bar';

    chart?.destroy();
    chart = new Chart(canvasRef.value, {
        type: props.type,
        data: {
            labels: props.labels,
            datasets: [
                {
                    label: props.label,
                    data: props.values,
                    backgroundColor: series,
                    borderColor: series,
                    borderWidth: horizontal ? 0 : 2,
                    borderRadius: horizontal ? 4 : 0,
                    borderSkipped: 'start',
                    maxBarThickness: 16,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: series,
                    tension: 0.25,
                },
            ],
        },
        options: {
            indexAxis: horizontal ? 'y' : 'x',
            maintainAspectRatio: false,
            animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 400 },
            interaction: horizontal ? { mode: 'nearest', axis: 'y', intersect: false } : { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: { padding: 10, displayColors: false, callbacks: { label: (item) => `${props.label}: ${item.formattedValue}` } },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { display: horizontal, color: hairline, drawTicks: false },
                    border: { color: hairline },
                    ticks: { color: ink, precision: 0, padding: 6, font: { size: 11 } },
                },
                y: {
                    beginAtZero: true,
                    grid: { display: !horizontal, color: hairline, drawTicks: false },
                    border: { color: hairline },
                    ticks: { color: ink, precision: 0, padding: 6, font: { size: 11 }, autoSkip: !horizontal },
                },
            },
        },
    });
};

onMounted(build);
watch(() => [props.labels, props.values], build, { deep: true });
onBeforeUnmount(() => chart?.destroy());

const empty = computed(() => props.values.every((value) => !value));
</script>

<template>
    <div>
        <div class="relative" :style="{ height: `${height}px` }">
            <canvas ref="canvasRef" role="img" :aria-label="summary" :aria-describedby="`${id}-table`" />
            <p v-if="empty" class="absolute inset-0 flex items-center justify-center text-sm text-text-muted">No data yet.</p>
        </div>
        <details class="mt-2 text-xs">
            <summary class="cursor-pointer text-primary hover:underline">View as table</summary>
            <table :id="`${id}-table`" class="mt-2 w-full">
                <thead class="text-left text-text-muted">
                    <tr>
                        <th scope="col" class="py-1">{{ type === 'line' ? 'Date' : 'Item' }}</th>
                        <th scope="col" class="py-1 text-right">{{ label }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="(name, index) in labels" :key="name">
                        <td class="py-1 text-text">{{ name }}</td>
                        <td class="py-1 text-right tabular-nums text-text">{{ values[index] }}</td>
                    </tr>
                </tbody>
            </table>
        </details>
    </div>
</template>
