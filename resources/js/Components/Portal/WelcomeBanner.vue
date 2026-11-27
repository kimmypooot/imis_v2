<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import OrbitCircles from '@cscro8/ui/Components/UI/OrbitCircles.vue';
import { useBranding } from '../../Composables/useBranding';

/**
 * The legacy dashboard's welcome banner (old-imis .welcome-banner): the
 * greeting with the user's division badge and the running version (a link
 * to What's New), the system title, and the welcome message from System Settings, on flat
 * CSC navy with the legacy's orbiting circles (OrbitCircles, shared with
 * the admin / HR dashboard banners). The office facade photo (the sign-in
 * background from Admin → Branding) shows faintly through the navy tint.
 * The text sits on the left; the quick-access shortcuts (QuickAccess) are
 * a row of round icons at the bottom right, like a dock, moving below the
 * text on small screens. Holds the page's <h1>.
 */
defineProps({
    greeting: { type: String, required: true },
    division: { type: String, default: null },
    version: { type: String, default: null },
    message: { type: String, default: null },
    // [{ key, label, href, icon }]
    shortcuts: { type: Array, default: () => [] },
});

const brand = useBranding();
</script>

<template>
    <section class="relative overflow-hidden rounded-lg bg-brand px-5 py-5 text-white shadow-md md:px-7 md:py-6">
        <!-- Decorative photo under a navy tint, so the text keeps its contrast. -->
        <div
            class="absolute inset-0 bg-cover bg-center"
            :style="{ backgroundImage: `url('${brand.login_background}')` }"
            aria-hidden="true"
        />
        <div class="absolute inset-0 bg-csc-blue/90" aria-hidden="true" />

        <OrbitCircles />

        <div class="relative z-10 flex flex-col gap-5 lg:flex-row lg:items-end lg:gap-6">
            <div class="min-w-0 flex-1 text-left">
                <p class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-base text-white/80">
                    {{ greeting }}
                    <span v-if="division" class="rounded-full bg-white/15 px-2.5 py-px text-xs font-medium whitespace-nowrap text-white/85">
                        {{ division }}
                    </span>
                    <!-- The running version, as a way into its release notes. -->
                    <Link
                        v-if="version"
                        href="/whats-new"
                        class="inline-flex items-center gap-1.5 rounded-full border border-white/20 px-2.5 py-px text-xs font-medium whitespace-nowrap text-white/85 transition-colors hover:bg-white/15 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                    >
                        v{{ version }}
                        <span aria-hidden="true">·</span>
                        What's new
                    </Link>
                </p>
                <h1 class="text-xl font-bold md:text-2xl">CSC RO VIII — Integrated Management Information System</h1>
                <p class="mt-1.5 text-base text-white/90">
                    {{ message || 'Enhancing public service delivery through innovation and digital transformation.' }}
                </p>
            </div>

            <nav v-if="shortcuts.length" aria-label="Shortcuts" class="-mx-1 shrink-0 overflow-x-auto px-1 pb-1">
                <ul class="flex gap-2">
                    <li v-for="shortcut in shortcuts" :key="shortcut.key">
                        <Link
                            :href="shortcut.href"
                            :aria-label="shortcut.label"
                            class="group flex w-[4.25rem] flex-col items-center gap-1.5 rounded-md text-center text-xs leading-tight text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                        >
                            <span class="flex h-11 w-11 items-center justify-center rounded-full border border-white/25 bg-white/15 transition-colors duration-200 group-hover:bg-white/25" aria-hidden="true">
                                <Icon :name="shortcut.icon" />
                            </span>
                            <span aria-hidden="true">{{ shortcut.label }}</span>
                        </Link>
                    </li>
                </ul>
            </nav>
        </div>
    </section>
</template>
