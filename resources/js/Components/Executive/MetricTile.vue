<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Badge from '../UI/Badge.vue';
import Icon from '../UI/Icon.vue';
import { formatChange, formatTarget, formatValue, targetStatuses } from './format';

/**
 * One figure on the Executive Dashboard: the number, what period it
 * covers, its change over the last 30 days with a small trend line, where
 * it stands against its target (a word and a tone, once a target is set),
 * a breakdown (usually by office) to open, and a link into the module
 * when the viewer may open it.
 */
const props = defineProps({
    metric: { type: Object, required: true },
});

const status = computed(() => targetStatuses[props.metric.status] ?? null);
const change = computed(() => formatChange(props.metric.change?.value, props.metric.unit));
const since = computed(() =>
    props.metric.change ? new Date(`${props.metric.change.since}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : null,
);
const edge = { success: 'border-l-success', warning: 'border-l-warning', danger: 'border-l-danger' };

// The trend line: a 100 × 28 box, points spread evenly, nulls skipped.
const points = computed(() => props.metric.trend.filter((point) => point.value !== null));
const path = computed(() => {
    const values = points.value.map((point) => point.value);
    if (values.length < 2) return null;

    const min = Math.min(...values);
    const span = Math.max(...values) - min || 1;

    return values.map((value, index) => `${(index / (values.length - 1)) * 100},${26 - ((value - min) / span) * 24}`).join(' ');
});
const trendSummary = computed(() => {
    if (!path.value) return '';
    const first = points.value[0];
    const last = points.value[points.value.length - 1];

    return `Trend since ${first.date}: from ${formatValue(first.value, props.metric.unit)} to ${formatValue(last.value, props.metric.unit)}.`;
});

const largest = computed(() => Math.max(...props.metric.breakdown.map((row) => row.value ?? 0), 0));
</script>

<template>
    <article class="flex h-full flex-col gap-2 rounded-lg border border-border bg-surface p-4 transition duration-200 hover:border-primary/40 hover:shadow-sm" :class="status ? ['border-l-4', edge[status.tone]] : null">
        <div class="flex items-start justify-between gap-2">
            <h3 class="text-sm font-medium text-text-muted">{{ metric.label }}</h3>
            <Badge v-if="status" :tone="status.tone" class="shrink-0">{{ status.label }}</Badge>
        </div>

        <div class="flex items-end justify-between gap-3">
            <p class="text-3xl leading-none font-bold tracking-tight text-primary tabular-nums">{{ formatValue(metric.value, metric.unit) }}</p>
            <svg v-if="path" viewBox="0 0 100 28" preserveAspectRatio="none" class="h-7 w-24 shrink-0" role="img" :aria-label="trendSummary">
                <polyline :points="path" fill="none" class="stroke-csc-blue-light" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            </svg>
        </div>

        <div class="space-y-0.5 text-xs text-text-muted">
            <p v-if="metric.note || metric.period">{{ [metric.note, metric.period].filter(Boolean).join(' · ') }}</p>
            <p v-if="change">{{ change }} since {{ since }}</p>
            <p v-if="metric.target">
                {{ formatTarget(metric.target, metric.unit) }}<template v-if="metric.target.note"> — {{ metric.target.note }}</template>
            </p>
        </div>

        <details v-if="metric.breakdown.length" class="group text-xs">
            <summary class="cursor-pointer font-medium text-primary hover:text-primary-hover">
                Breakdown ({{ metric.breakdown.length }})
            </summary>
            <ul class="mt-2 space-y-1.5">
                <li v-for="row in metric.breakdown" :key="row.label">
                    <div class="flex justify-between gap-2">
                        <span class="text-text">{{ row.label }}</span>
                        <span class="font-semibold text-text">{{ formatValue(row.value, metric.unit === 'percent' ? 'count' : metric.unit) }}</span>
                    </div>
                    <div class="mt-0.5 h-1 rounded-full bg-surface-muted" aria-hidden="true">
                        <div class="h-full rounded-full bg-csc-blue-light" :style="{ width: `${largest ? ((row.value ?? 0) / largest) * 100 : 0}%` }" />
                    </div>
                </li>
            </ul>
        </details>

        <Link v-if="metric.href" :href="metric.href" class="mt-auto inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary hover:text-primary-hover hover:underline">
            Open details
            <Icon name="chevronRight" size="sm" />
        </Link>
    </article>
</template>
