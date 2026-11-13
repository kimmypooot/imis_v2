<script setup>
import { computed } from 'vue';
import Badge from '../UI/Badge.vue';
import Icon from '../UI/Icon.vue';
import Sparkline from './Sparkline.vue';
import { changeTone, formatChange, formatValue, targetProgress, targetStatuses } from './format';

/**
 * One figure on the Executive Dashboard: the label, the number large, how
 * it moved over the last 30 days (an arrow, green or red only once a target
 * says which way is better), a trend line, and a progress bar toward its
 * target. The whole tile opens the figure's detail dialog; the star adds it
 * to the viewer's watch list. `featured` is the bigger tile used for the
 * overview and the watch list.
 */
const props = defineProps({
    metric: { type: Object, required: true },
    sectionLabel: { type: String, default: null },
    pinned: { type: Boolean, default: false },
    featured: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'toggle-pin']);

const status = computed(() => targetStatuses[props.metric.status] ?? null);
const change = computed(() => formatChange(props.metric.change?.value, props.metric.unit));
const tone = computed(() => changeTone(props.metric));
const progress = computed(() => targetProgress(props.metric));
const arrow = computed(() => (props.metric.change?.value > 0 ? '▲' : props.metric.change?.value < 0 ? '▼' : '●'));

const changeClasses = {
    success: 'bg-success-bg text-success-fg',
    danger: 'bg-danger-bg text-danger-fg',
    neutral: 'bg-surface-muted text-text-muted',
};
const barClasses = { on_track: 'bg-success', at_risk: 'bg-warning', behind: 'bg-danger' };
</script>

<template>
    <article
        class="group relative flex h-full flex-col rounded-xl border border-border bg-surface transition duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
        :class="featured ? 'p-5' : 'p-4'"
    >
        <!-- The tile's main action: the stretched button makes the whole card open the detail. -->
        <button
            type="button"
            class="absolute inset-0 z-0 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring"
            :aria-label="`${metric.label}: ${formatValue(metric.value, metric.unit)}. Open details`"
            @click="emit('open', metric)"
        />

        <div class="pointer-events-none flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p v-if="sectionLabel" class="mb-0.5 text-xs font-semibold tracking-wide text-text-muted uppercase">{{ sectionLabel }}</p>
                <h3 class="text-sm font-medium text-text">{{ metric.label }}</h3>
            </div>
            <Badge v-if="status" :tone="status.tone" class="shrink-0">{{ status.label }}</Badge>
        </div>

        <div class="pointer-events-none mt-3 flex items-end justify-between gap-3">
            <p class="leading-none font-bold tracking-tight text-text tabular-nums" :class="featured ? 'text-4xl' : 'text-3xl'">
                {{ formatValue(metric.value, metric.unit) }}
            </p>
            <Sparkline :trend="metric.trend" :unit="metric.unit" />
        </div>

        <p class="pointer-events-none mt-2 min-h-5 text-xs text-text-muted">
            <span v-if="change" class="mr-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold" :class="changeClasses[tone]">
                <span aria-hidden="true">{{ arrow }}</span>
                {{ change }}
            </span>
            <span v-if="change">in 30 days</span>
            <span v-else-if="metric.note || metric.period">{{ [metric.note, metric.period].filter(Boolean).join(' · ') }}</span>
        </p>

        <div v-if="progress !== null" class="pointer-events-none mt-3">
            <div class="h-2 overflow-hidden rounded-full bg-surface-muted" role="presentation">
                <div class="h-full rounded-full transition-all duration-700" :class="barClasses[metric.status] ?? 'bg-primary'" :style="{ width: `${progress}%` }" />
            </div>
            <p class="mt-1 text-xs text-text-muted">
                {{ progress }}% of target ({{ metric.target.direction === 'lower' ? 'at most' : 'at least' }} {{ formatValue(metric.target.value, metric.unit) }})
            </p>
        </div>

        <div class="pointer-events-none mt-auto flex items-center justify-between pt-3 text-xs font-medium text-link">
            <span class="inline-flex items-center gap-1 opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">
                View details
                <Icon name="chevronRight" size="sm" />
            </span>
        </div>

        <button
            type="button"
            class="absolute right-3 bottom-3 z-10 flex h-8 w-8 items-center justify-center rounded-full text-text-muted transition hover:bg-surface-muted hover:text-text focus-visible:outline-2 focus-visible:outline-focus-ring"
            :class="pinned ? 'text-warning-fg' : ''"
            :aria-pressed="pinned"
            :aria-label="pinned ? `Remove ${metric.label} from my watch list` : `Add ${metric.label} to my watch list`"
            @click="emit('toggle-pin', metric)"
        >
            <svg viewBox="0 0 24 24" class="h-5 w-5" :fill="pinned ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3.5l2.6 5.3 5.9.9-4.25 4.15 1 5.85L12 16.9l-5.25 2.8 1-5.85L3.5 9.7l5.9-.9L12 3.5z" />
            </svg>
        </button>
    </article>
</template>
