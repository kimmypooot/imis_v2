<script setup>
import OrbitCircles from '../UI/OrbitCircles.vue';
import { useBranding } from '../../Composables/useBranding';

/**
 * The legacy dashboard's welcome banner (old-imis .welcome-banner): the
 * greeting with the user's division badge, the system title with its
 * version badge, and the welcome message from System Settings, on flat
 * CSC navy with the legacy's orbiting circles (OrbitCircles, shared with
 * the admin / HR dashboard banners). The office facade photo (the sign-in
 * background from Admin → Branding) shows faintly through the navy tint.
 * Holds the page's <h1>.
 */
defineProps({
    greeting: { type: String, required: true },
    division: { type: String, default: null },
    version: { type: String, default: null },
    message: { type: String, default: null },
});

const brand = useBranding();
</script>

<template>
    <section class="relative overflow-hidden rounded-lg bg-linear-to-br from-csc-blue via-csc-blue to-csc-blue-dark px-5 py-4 text-white shadow-md md:px-7 md:py-5">
        <!-- Decorative photo under a navy tint, so the text keeps its contrast. -->
        <div
            class="absolute inset-0 bg-cover bg-center"
            :style="{ backgroundImage: `url('${brand.login_background}')` }"
            aria-hidden="true"
        />
        <div class="absolute inset-0 bg-linear-to-br from-csc-blue/92 via-csc-blue/90 to-csc-blue-dark/95" aria-hidden="true" />

        <OrbitCircles />

        <div class="relative z-10">
            <p class="mb-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-white/80">
                {{ greeting }}
                <span v-if="division" class="rounded-full bg-white/15 px-2.5 py-px text-xs font-medium whitespace-nowrap text-white/85">
                    {{ division }}
                </span>
            </p>
            <h1 class="text-lg font-bold md:text-xl">
                CSC RO VIII — Integrated Management Information System
                <span
                    v-if="version"
                    class="relative -top-[0.15em] ml-1.5 inline-block rounded-full border border-white/20 bg-white/10 px-2.5 py-0.5 align-middle text-xs font-semibold tracking-wide text-white/75"
                >
                    v{{ version }}
                </span>
            </h1>
            <p class="mt-1 line-clamp-2 text-sm text-white/85">
                {{ message || 'Enhancing public service delivery through innovation and digital transformation.' }}
            </p>
        </div>
    </section>
</template>
