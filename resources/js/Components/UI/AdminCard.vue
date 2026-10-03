<script setup>
import Icon from './Icon.vue';

/**
 * The legacy admin content card: white, soft shadow, an uppercase blue
 * `card-title` with an optional icon, and actions (e.g. a green pill
 * "Add User" button) on the right.
 */
defineProps({
    title: { type: String, default: null },
    icon: { type: String, default: null },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <section class="rounded-lg bg-surface p-5 shadow-sm ring-1 ring-border/60">
        <div v-if="title || $slots.actions" class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 v-if="title" class="flex items-center gap-2 text-sm font-bold tracking-wide text-primary uppercase">
                    <Icon v-if="icon" :name="icon" size="sm" />
                    {{ title }}
                </h2>
                <p v-if="subtitle" class="mt-0.5 text-xs text-text-muted">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
        <slot />
    </section>
</template>
