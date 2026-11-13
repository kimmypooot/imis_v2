<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from './Button.vue';
import Icon from './Icon.vue';
import { useBranding } from '../../Composables/useBranding';

/**
 * Shared full-page presentation for 403/404/419/429/500/503, kept
 * professional and generic; never renders stack traces, SQL, file paths,
 * or other internal details (those never reach the frontend to begin
 * with — the backend only ever passes a status code and a plain message).
 *
 * The status code is the one big thing on the page; an icon says what kind
 * of problem it is, and the actions say what to do next: always a way home,
 * "Go back" for a missing or forbidden page, and "Try again" when waiting or
 * reloading can fix it (expired session, rate limit, server error, downtime).
 * Other hub systems copy this component as is.
 */
const props = defineProps({
    code: { type: [String, Number], required: true },
    title: { type: String, required: true },
    message: { type: String, required: true },
    /** Where "home" goes. */
    homeHref: { type: String, default: '/dashboard' },
    homeLabel: { type: String, default: 'Return to dashboard' },
});

const kinds = {
    403: { icon: 'lock', retry: false },
    404: { icon: 'search', retry: false },
    419: { icon: 'clock', retry: true },
    429: { icon: 'clock', retry: true },
    500: { icon: 'exclamation', retry: true },
    503: { icon: 'cog', retry: true },
};

const kind = computed(() => kinds[Number(props.code)] ?? kinds[500]);
const brand = useBranding();

const reload = () => window.location.reload();
const goBack = () => (window.history.length > 1 ? window.history.back() : (window.location.href = props.homeHref));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-surface-muted">
        <header class="px-4 py-4 sm:px-8">
            <Link :href="homeHref" class="inline-flex items-center gap-2 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
                <img :src="brand.logo" alt="" class="h-9 w-auto" />
                <span class="text-base font-semibold text-link">{{ brand.header_name }}</span>
            </Link>
        </header>

        <main class="flex flex-1 items-center justify-center px-4 pb-16">
            <div class="stagger flex w-full max-w-lg flex-col items-center text-center">
                <div class="relative">
                    <p
                        class="text-8xl font-bold tracking-tight text-link tabular-nums select-none sm:text-9xl"
                        aria-hidden="true"
                    >
                        {{ code }}
                    </p>
                    <span
                        class="absolute -right-3 -bottom-2 flex h-12 w-12 animate-pop items-center justify-center rounded-full border-4 border-surface-muted bg-accent text-white motion-reduce:animate-none sm:h-14 sm:w-14"
                        aria-hidden="true"
                    >
                        <Icon :name="kind.icon" size="lg" />
                    </span>
                </div>

                <h1 class="mt-6 text-2xl font-semibold text-text">
                    <span class="sr-only">Error {{ code }}: </span>{{ title }}
                </h1>
                <p class="mt-2 max-w-md text-sm text-text-muted">{{ message }}</p>

                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <Button v-if="kind.retry" icon="refresh" @click="reload">Try again</Button>
                    <Button :href="homeHref" :variant="kind.retry ? 'secondary' : 'primary'" icon="home">{{ homeLabel }}</Button>
                    <Button v-if="!kind.retry" variant="secondary" icon="arrowLeft" @click="goBack">Go back</Button>
                </div>

                <slot />
            </div>
        </main>
    </div>
</template>
