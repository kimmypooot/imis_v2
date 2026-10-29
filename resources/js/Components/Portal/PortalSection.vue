<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * A labeled section of the dashboard/portal ("Quick Access", "Services",
 * ...) with an optional "View all" link — not a bordered Card, since a
 * page built entirely from cards-within-cards gets visually noisy.
 */
defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: null,
    },
    viewAllHref: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <section>
        <div class="flex items-baseline justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-base font-semibold text-text">
                    <span class="h-4 w-1 shrink-0 rounded-full bg-accent" aria-hidden="true" />
                    {{ title }}
                </h2>
                <p v-if="description" class="mt-0.5 text-sm text-text-muted">{{ description }}</p>
            </div>
            <Link
                v-if="viewAllHref"
                :href="viewAllHref"
                class="shrink-0 text-sm font-medium text-primary transition-colors hover:text-primary-hover hover:underline"
            >
                View all →
            </Link>
        </div>

        <div class="mt-3">
            <slot />
        </div>
    </section>
</template>
