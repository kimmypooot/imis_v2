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
                <h2 class="text-base font-semibold text-text">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-sm text-text-muted">{{ description }}</p>
            </div>
            <Link
                v-if="viewAllHref"
                :href="viewAllHref"
                class="shrink-0 text-sm font-medium text-primary hover:text-primary-hover"
            >
                View all
            </Link>
        </div>

        <div class="mt-3">
            <slot />
        </div>
    </section>
</template>
