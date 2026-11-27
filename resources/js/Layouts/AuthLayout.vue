<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useBranding } from '../Composables/useBranding';

/**
 * The guest layout for every sign-in page (login, forgot / reset password,
 * verify email), so they read as one flow. A light page frame holds, on
 * desktop, a rounded CSC-navy brand panel over the regional office facade
 * (the agency seals, the system's name, who it's for, and the running
 * version), and beside it the page's own card. Each page heads its card
 * with `AuthHeading` (the IMIS logo beside a blue title). On phones the
 * brand panel is left out and the seals sit at the top of the card.
 */
const page = usePage();
const brand = useBranding();
const year = new Date().getFullYear();
const version = computed(() => page.props.app?.version);

const agencyLogos = computed(() => [
    { src: brand.value.logo, alt: 'Civil Service Commission' },
    { src: '/images/brand/bagong_pilipinas.png', alt: 'Bagong Pilipinas' },
    { src: '/images/brand/lingkod_bayani.png', alt: 'Lingkod Bayani' },
]);
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-surface-muted lg:grid lg:grid-cols-2 lg:gap-4 lg:p-4">
        <!-- Brand panel (desktop only) -->
        <div
            class="relative hidden overflow-hidden rounded-[28px] bg-csc-blue bg-cover bg-center px-12 py-11 text-white lg:flex lg:flex-col"
            :style="{ backgroundImage: `url('${brand.login_background}')` }"
        >
            <div class="absolute inset-0 bg-linear-to-br from-csc-blue/85 via-csc-blue/92 to-csc-blue-dark/95" aria-hidden="true" />
            <div class="absolute -inset-x-[10%] -bottom-[35%] h-[70%] rounded-[50%] bg-white/5" aria-hidden="true" />

            <!-- The seals with the name right under them, vertically centred; the version card sits at the bottom. -->
            <div class="relative my-auto max-w-lg py-8 motion-safe:animate-fade-in">
                <div class="flex items-center gap-5">
                    <img
                        v-for="logo in agencyLogos"
                        :key="logo.src"
                        :src="logo.src"
                        alt=""
                        class="h-16 w-auto select-none drop-shadow-lg"
                        draggable="false"
                    />
                </div>

                <p class="mt-10 text-4xl leading-tight font-bold tracking-tight">
                    CSC RO VIII<br />Integrated Management<br />Information System
                </p>
                <p class="mt-5 text-sm leading-relaxed text-white/75">
                    The centralized digital hub for the Civil Service Commission Regional Office VIII — streamlining
                    and integrating services across the Regional Support Units, CSC Field Offices, and Satellite
                    Offices in Region VIII.
                </p>
                <p class="mt-3 text-xs font-semibold tracking-wide text-white/80 uppercase">For authorized CSC personnel only</p>
            </div>

            <!-- The running version; What's New itself opens after sign-in. -->
            <div v-if="version" class="relative flex max-w-md items-center gap-3.5 rounded-2xl border border-white/20 bg-white/10 px-4 py-3.5 backdrop-blur-sm">
                <span class="shrink-0 rounded-full bg-white px-2.5 py-0.5 text-2xs font-bold tracking-wide text-csc-blue">v{{ version }}</span>
                <span class="text-sm leading-snug text-white/85">
                    <span class="font-semibold text-white">What's new</span> shows after you sign in.
                </span>
            </div>
        </div>

        <!-- The page's card -->
        <main class="flex flex-1 items-center justify-center px-4 py-8 sm:px-8 lg:px-10">
            <div class="auth-card w-full max-w-md rounded-[20px] border border-border bg-surface px-6 py-8 shadow-lg sm:px-10">
                <!-- Phones (no brand panel): the agency seals. -->
                <div class="mb-5 flex items-center justify-center gap-5 border-b border-border pb-5 lg:hidden">
                    <img
                        v-for="logo in agencyLogos"
                        :key="logo.src"
                        :src="logo.src"
                        :alt="logo.alt"
                        class="pointer-events-none h-9 w-auto select-none"
                        draggable="false"
                    />
                </div>

                <slot />

                <p class="mt-8 text-center text-xs text-text-muted">
                    CSC RO VIII &copy; {{ year }} ·
                    <a href="/privacy" class="text-link hover:underline">Privacy Notice</a>
                </p>
            </div>
        </main>
    </div>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .auth-card {
        animation: auth-rise 0.4s ease-out both;
    }
}

@keyframes auth-rise {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}
</style>
