<script setup>
import { computed } from 'vue';
import { useSplash } from '../../Composables/useSplash';

/**
 * Renders the splash driven by useSplash(): CSC-navy field with a red
 * accent bar, the CSC logo on a white disc inside a pulsing ring, the
 * message, and an indeterminate progress bar — the legacy preloader's
 * look. Announced once through a polite live region; animations stop
 * under prefers-reduced-motion.
 */
const { splash } = useSplash();

// "{name}" in the subtitle becomes the highlighted first name; without a
// name, the placeholder (and its leading comma) is dropped.
const subtitleParts = computed(() => {
    if (!splash.name) {
        return { before: splash.subtitle.replace(/,?\s*\{name\}/, ''), after: '', hasName: false };
    }

    const [before, after = ''] = splash.subtitle.split('{name}');

    return { before, after, hasName: true };
});
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-500"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
    >
        <div
            v-if="splash.visible"
            class="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-csc-blue px-6 text-center text-white"
            role="status"
            aria-live="polite"
        >
            <div class="absolute inset-x-0 top-0 h-1 bg-csc-red" aria-hidden="true" />

            <div class="relative mb-8 flex h-28 w-28 items-center justify-center" aria-hidden="true">
                <span class="absolute inset-3 rounded-full border-4 border-csc-red/60 motion-safe:animate-ping" />
                <span class="absolute inset-4 rounded-full bg-white shadow-lg" />
                <img src="/images/brand/csc-logo.png" alt="" class="relative h-14 w-auto" />
            </div>

            <p class="text-xl font-semibold tracking-tight sm:text-2xl">{{ splash.title }}</p>
            <!-- prettier-ignore -->
            <p v-if="splash.subtitle" class="mt-2 text-base text-white/85">{{ subtitleParts.before }}<span v-if="subtitleParts.hasName" class="font-semibold text-white">{{ splash.name }}</span>{{ subtitleParts.after }}</p>
            <p v-if="splash.note" class="mt-6 text-xs tracking-wide text-white/60 uppercase">{{ splash.note }}</p>

            <div class="mt-4 h-1 w-48 overflow-hidden rounded-full bg-white/15" aria-hidden="true">
                <div class="splash-bar h-full w-1/3 rounded-full bg-white/80" />
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.splash-bar {
    animation: splash-slide 1.1s ease-in-out infinite;
}

@keyframes splash-slide {
    from {
        transform: translateX(-100%);
    }
    to {
        transform: translateX(300%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .splash-bar {
        animation: none;
        width: 100%;
    }
}
</style>
