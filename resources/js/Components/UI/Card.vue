<script setup>
defineProps({
    title: {
        type: String,
        default: null,
    },
    description: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <section class="rounded-lg border border-border bg-surface p-5">
        <header v-if="title || description || $slots.header" class="mb-4">
            <slot name="header">
                <h2 v-if="title" class="text-sm font-semibold uppercase tracking-wide text-text-muted">
                    {{ title }}
                </h2>
                <p v-if="description" class="mt-1 text-sm text-text-muted">{{ description }}</p>
            </slot>
        </header>

        <div>
            <slot />
        </div>

        <footer v-if="$slots.footer" class="mt-4 border-t border-border pt-4">
            <slot name="footer" />
        </footer>
    </section>
</template>
