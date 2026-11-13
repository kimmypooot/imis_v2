<script setup>
import { ref, onMounted } from 'vue';
import Icon from './Icon.vue';
import { useCountUp } from '../../Composables/useCountUp';

/**
 * The legacy admin "metric-card": a label, one big number, a sub-line,
 * an optional progress bar, and a tinted icon chip, on a flat bordered
 * card. The number is the content — no chart needed for a single figure.
 * Pick `warning` only when the figure calls for attention (vacancies,
 * retirements), not for variety.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], required: true },
    icon: { type: String, required: true },
    tone: { type: String, default: 'primary', validator: (value) => ['primary', 'success', 'warning', 'info'].includes(value) },
    progress: { type: Number, default: null /** 0–100 */ },
});

const shown = useCountUp(() => props.value);

// The bar starts empty and fills on arrival, so progress is seen being made.
const filled = ref(false);
onMounted(() => requestAnimationFrame(() => (filled.value = true)));

const accents = {
    primary: { chip: 'bg-info-bg text-link', bar: 'bg-primary' },
    success: { chip: 'bg-success-bg text-success-fg', bar: 'bg-success' },
    warning: { chip: 'bg-warning-bg text-warning-fg', bar: 'bg-warning' },
    info: { chip: 'bg-info-bg text-csc-blue-light', bar: 'bg-csc-blue-light' },
};
</script>

<template>
    <section class="group/tile h-full rounded-lg border border-border bg-surface p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-xs font-semibold tracking-wide text-text-muted uppercase">{{ label }}</h3>
                <p class="mt-1 text-3xl font-bold text-text tabular-nums">{{ shown }}</p>
                <div class="mt-1 text-xs text-text-muted"><slot /></div>
            </div>
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ring-1 ring-inset ring-current/15 transition-transform duration-200 ease-standard group-hover/tile:scale-110 motion-reduce:transition-none" :class="accents[tone].chip" aria-hidden="true">
                <Icon :name="icon" />
            </span>
        </div>
        <div v-if="progress !== null" class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-muted" role="presentation">
            <div class="h-full rounded-full transition-[width] duration-700 ease-standard motion-reduce:transition-none" :class="accents[tone].bar" :style="{ width: filled ? `${Math.min(100, Math.max(0, progress))}%` : '0%' }" />
        </div>
    </section>
</template>
