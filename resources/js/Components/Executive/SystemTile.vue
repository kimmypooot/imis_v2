<script setup>
import { computed } from 'vue';
import Badge from '../UI/Badge.vue';
import Icon from '../UI/Icon.vue';
import { formatAge, systemStates } from './format';

/**
 * One system on the Executive Dashboard: its name, a status word with its
 * tone, what that means, and when its figures last arrived.
 */
const props = defineProps({
    system: { type: Object, required: true },
});

const state = computed(() => systemStates[props.system.state] ?? systemStates.waiting);
const edge = { success: 'border-l-success', warning: 'border-l-warning', danger: 'border-l-danger', neutral: 'border-l-border' };
</script>

<template>
    <article class="flex h-full flex-col gap-2 rounded-lg border border-l-4 border-border bg-surface p-3 transition duration-200 hover:shadow-sm" :class="edge[state.tone]">
        <div class="flex items-start justify-between gap-2">
            <h3 class="flex min-w-0 items-center gap-2 text-sm font-semibold text-text" :title="system.fullName">
                <Icon :name="system.icon" size="sm" class="shrink-0 text-primary" />
                <span class="break-words">{{ system.name }}</span>
            </h3>
            <Badge :tone="state.tone" class="shrink-0">{{ state.label }}</Badge>
        </div>
        <p class="text-xs text-text-muted">{{ system.detail }}</p>
        <p v-if="system.updatedAt" class="mt-auto text-xs text-text-muted">Updated {{ formatAge(system.updatedAt) }}</p>
    </article>
</template>
