<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Split-screen guest layout carried over from the legacy IMIS login:
 * a CSC-navy brand panel over the regional office facade (desktop only)
 * and the form card on a light panel. Used by every auth page (login,
 * forgot/reset password, verify email) so they read as one flow.
 */
const page = usePage();
const organization = computed(() => page.props.portal?.organization || 'Civil Service Commission Regional Office VIII');
const year = new Date().getFullYear();

const agencyLogos = [
    { src: '/images/brand/csc-logo.png', alt: 'Civil Service Commission' },
    { src: '/images/brand/bagong_pilipinas.png', alt: 'Bagong Pilipinas' },
    { src: '/images/brand/lingkod_bayani.png', alt: 'Lingkod Bayani' },
];
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-surface lg:grid lg:grid-cols-2">
        <!-- Brand panel (desktop only; decorative, so hidden from screen readers) -->
        <div
            class="relative hidden overflow-hidden bg-csc-blue bg-cover bg-center px-8 py-10 text-center text-white lg:flex lg:flex-col lg:items-center lg:justify-center"
            style="background-image: url('/images/brand/cscbg_facade.jpeg')"
            aria-hidden="true"
        >
            <div class="absolute inset-0 bg-csc-blue/90" />

            <div class="relative flex max-w-sm flex-col items-center">
                <div class="flex items-center justify-center gap-6">
                    <img
                        v-for="logo in agencyLogos"
                        :key="logo.src"
                        :src="logo.src"
                        alt=""
                        class="h-20 w-auto select-none"
                        draggable="false"
                    />
                </div>
                <div class="my-6 h-0.5 w-10 rounded bg-white/20" />
                <p class="text-[1.65rem] leading-snug font-bold tracking-tight">
                    CSC RO VIII<br />Integrated Management<br />Information System
                </p>
                <p class="mt-5 text-sm leading-relaxed text-white/70">
                    The centralized digital hub for the Civil Service Commission Regional Office VIII — streamlining
                    human resources, examinations, and technical support across Eastern Visayas.
                </p>
            </div>

            <p class="absolute inset-x-0 bottom-8 text-[0.66rem] tracking-widest text-white/45 uppercase">
                {{ organization }}
            </p>
        </div>

        <!-- Form panel -->
        <main class="flex flex-1 items-center justify-center bg-surface-muted px-4 py-8 sm:px-6 lg:p-10">
            <div class="w-full max-w-md">
                <div class="rounded-2xl border border-border bg-surface px-6 py-9 shadow-sm sm:px-10">
                    <!-- Agency logos live in the brand panel on desktop -->
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

                    <div class="mb-6 flex items-center gap-4">
                        <img
                            src="/images/brand/CSC-IMIS.png"
                            alt=""
                            class="pointer-events-none h-11 w-auto shrink-0 select-none"
                            draggable="false"
                        />
                        <div>
                            <p class="text-base leading-snug font-semibold text-primary">
                                Integrated Management Information System (IMIS)
                            </p>
                            <p class="text-xs text-text-muted">Civil Service Commission Regional Office VIII</p>
                        </div>
                    </div>

                    <slot />

                    <p class="mt-8 text-center text-xs text-text-muted">CSC RO VIII &copy; {{ year }}</p>
                </div>
            </div>
        </main>
    </div>
</template>
