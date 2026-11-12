<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useBranding } from '../Composables/useBranding';
import Icon from '../Components/UI/Icon.vue';

/**
 * Split-screen guest layout carried over from the legacy IMIS login:
 * a CSC-navy brand panel over the regional office facade (desktop only)
 * and the form card on a light panel. Used by every auth page (login,
 * forgot/reset password, verify email) so they read as one flow.
 */
const page = usePage();
const brand = useBranding();
const organization = computed(() => page.props.portal?.organization || 'Civil Service Commission Regional Office VIII');
const year = new Date().getFullYear();

const features = [
    { icon: 'users', title: 'One sign-in', text: 'A single account for every CSC RO VIII system.' },
    { icon: 'grid', title: 'All your systems', text: 'Open the applications you have access to from one portal.' },
    { icon: 'shield', title: 'Protected access', text: 'Role-based permissions and a record of every sign-in.' },
];

const FEATURES_KEY = 'auth.showFeatures';

const readFeaturesPreference = () => {
    try {
        return localStorage.getItem(FEATURES_KEY) === '1';
    } catch {
        return false;
    }
};

const showFeatures = ref(readFeaturesPreference());

const toggleFeatures = () => {
    showFeatures.value = !showFeatures.value;
    try {
        localStorage.setItem(FEATURES_KEY, showFeatures.value ? '1' : '0');
    } catch {
        // Storage unavailable (private window); the toggle still works for this visit.
    }
};

const agencyLogos = computed(() => [
    { src: brand.value.logo, alt: 'Civil Service Commission' },
    { src: '/images/brand/bagong_pilipinas.png', alt: 'Bagong Pilipinas' },
    { src: '/images/brand/lingkod_bayani.png', alt: 'Lingkod Bayani' },
]);
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-surface lg:grid lg:grid-cols-2">
        <!-- Brand panel (desktop only; decorative, so hidden from screen readers) -->
        <div
            class="relative hidden overflow-hidden bg-csc-blue bg-cover bg-center px-8 py-10 text-center text-white lg:flex lg:flex-col lg:items-center lg:justify-center"
            :style="{ backgroundImage: `url('${brand.login_background}')` }"
        >
            <div class="absolute inset-0 bg-csc-blue/90" />

            <div class="relative flex w-full max-w-md flex-col items-center motion-safe:animate-fade-in">
                <div class="flex items-center justify-center gap-6">
                    <img
                        v-for="logo in agencyLogos"
                        :key="logo.src"
                        :src="logo.src"
                        alt=""
                        class="h-20 w-auto select-none drop-shadow-lg"
                        draggable="false"
                    />
                </div>
                <div class="my-6 h-0.5 w-10 rounded-sm bg-white/20" />
                <p class="text-stat leading-snug font-bold tracking-tight">
                    CSC RO VIII<br />Integrated Management<br />Information System
                </p>
                <p class="mt-5 text-sm leading-relaxed text-white/70">
                    The centralized digital hub for the Civil Service Commission Regional Office VIII — streamlining
                    and integrating services across the Regional Support Units, CSC Field Offices, and Satellite
                    Offices in Region VIII.
                </p>
                <p class="mt-3 text-xs font-semibold tracking-wide text-white/80 uppercase">
                    For authorized CSC personnel only
                </p>

                <button
                    type="button"
                    class="mt-6 inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-white/70 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    :aria-expanded="showFeatures"
                    aria-controls="auth-features"
                    @click="toggleFeatures"
                >
                    {{ showFeatures ? 'Hide highlights' : 'Show highlights' }}
                    <Icon :name="showFeatures ? 'chevronUp' : 'chevronDown'" size="sm" />
                </button>

                <ul v-show="showFeatures" id="auth-features" class="mt-3 w-full space-y-3 text-left">
                    <li
                        v-for="feature in features"
                        :key="feature.title"
                        class="flex items-start gap-3 rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm"
                    >
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/15">
                            <Icon :name="feature.icon" size="sm" />
                        </span>
                        <span>
                            <span class="block text-sm font-semibold">{{ feature.title }}</span>
                            <span class="block text-xs leading-relaxed text-white/70">{{ feature.text }}</span>
                        </span>
                    </li>
                </ul>
            </div>

            <p class="absolute inset-x-0 bottom-8 text-2xs tracking-widest text-white/45 uppercase">
                {{ organization }}
            </p>
        </div>

        <!-- Form panel -->
        <main class="relative flex flex-1 items-center justify-center overflow-hidden bg-surface-muted px-4 py-8 sm:px-6 lg:p-10">
            <div class="pointer-events-none absolute -top-24 -right-24 h-72 w-72 rounded-full bg-csc-blue/5" aria-hidden="true" />
            <div class="pointer-events-none absolute -bottom-28 -left-20 h-64 w-64 rounded-full bg-csc-red/5" aria-hidden="true" />

            <div class="auth-card relative w-full max-w-md">
                <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-lg">
                    <div class="px-6 py-8 sm:px-10">
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
                            :src="brand.app_logo"
                            alt=""
                            class="pointer-events-none h-11 w-auto shrink-0 select-none"
                            draggable="false"
                        />
                        <div>
                            <p class="text-base leading-snug font-semibold text-link">
                                Integrated Management Information System (IMIS)
                            </p>
                            <p class="text-xs text-text-muted">Civil Service Commission Regional Office VIII</p>
                        </div>
                    </div>

                    <slot />

                    <p class="mt-8 text-center text-xs text-text-muted">
                        CSC RO VIII &copy; {{ year }} ·
                        <a href="/privacy" class="text-link hover:underline">Privacy Notice</a>
                    </p>
                    </div>
                </div>
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
