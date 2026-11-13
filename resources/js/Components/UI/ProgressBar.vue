<script setup>
import { computed } from 'vue';

/**
 * A progress bar. With `value` (0–100) it is determinate and the fill glides
 * to its width; without one it is indeterminate, a segment sweeping across
 * the track (still, and full-width, for people who prefer reduced motion).
 * `label` names it for screen readers and, with `showValue`, is shown above.
 * Tones follow the shared palette; pick `warning`/`danger` only when the
 * figure calls for it (a quota nearly used up).
 */
const props = defineProps({
    value: { type: Number, default: null },
    label: { type: String, default: null },
    showValue: { type: Boolean, default: false },
    tone: { type: String, default: 'primary', validator: (value) => ['primary', 'success', 'warning', 'danger'].includes(value) },
    size: { type: String, default: 'md', validator: (value) => ['sm', 'md', 'lg'].includes(value) },
});

const fills = { primary: 'bg-primary', success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger' };
const heights = { sm: 'h-1', md: 'h-1.5', lg: 'h-2.5' };

const determinate = computed(() => props.value !== null);
const percent = computed(() => Math.min(100, Math.max(0, props.value ?? 0)));
</script>

<template>
    <div>
        <div v-if="showValue && label" class="mb-1 flex items-center justify-between text-xs text-text-muted">
            <span>{{ label }}</span>
            <span v-if="determinate" class="font-medium text-text tabular-nums">{{ Math.round(percent) }}%</span>
        </div>
        <div
            role="progressbar"
            :aria-label="label ?? undefined"
            :aria-valuemin="determinate ? 0 : undefined"
            :aria-valuemax="determinate ? 100 : undefined"
            :aria-valuenow="determinate ? Math.round(percent) : undefined"
            class="relative w-full overflow-hidden rounded-full bg-surface-muted ring-1 ring-inset ring-border"
            :class="heights[size]"
        >
            <div
                v-if="determinate"
                class="h-full rounded-full transition-[width] duration-500 ease-standard motion-reduce:transition-none"
                :class="fills[tone]"
                :style="{ width: `${percent}%` }"
            />
            <div v-else class="absolute inset-y-0 left-0 w-1/3 origin-left animate-indeterminate rounded-full motion-reduce:w-full motion-reduce:animate-none" :class="fills[tone]" />
        </div>
    </div>
</template>
