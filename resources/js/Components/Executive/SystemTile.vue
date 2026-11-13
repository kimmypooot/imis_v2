<script setup>
import { computed } from 'vue';
import Icon from '../UI/Icon.vue';
import { formatAge, systemStates } from './format';

/**
 * One system on the status board: an icon chip tinted by its state, its
 * name, a status word (color is never the only signal) and a one-line
 * meaning with when its figures last arrived. Compact so all systems fit
 * on one screen.
 */
const props = defineProps({
    system: { type: Object, required: true },
});

const state = computed(() => systemStates[props.system.state] ?? systemStates.waiting);
const chip = {
    success: 'bg-success-bg text-success-fg',
    warning: 'bg-warning-bg text-warning-fg',
    danger: 'bg-danger-bg text-danger-fg',
    neutral: 'bg-surface-muted text-text-muted',
};
const word = {
    success: 'text-success-fg',
    warning: 'text-warning-fg',
    danger: 'text-danger-fg',
    neutral: 'text-text-muted',
};
</script>

<template>
    <article class="flex items-center gap-3 rounded-xl border border-border bg-surface px-3 py-2.5 transition duration-200 hover:shadow-sm" :title="`${system.fullName}: ${system.detail}`">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" :class="chip[state.tone] ?? chip.neutral" aria-hidden="true">
            <Icon :name="system.icon" size="sm" />
        </span>
        <div class="min-w-0">
            <h3 class="truncate text-sm font-semibold text-text">{{ system.name }}</h3>
            <p class="truncate text-xs">
                <span class="font-semibold" :class="word[state.tone] ?? word.neutral">{{ state.label }}</span>
                <span v-if="system.updatedAt" class="text-text-muted"> · {{ formatAge(system.updatedAt) }}</span>
            </p>
        </div>
    </article>
</template>
