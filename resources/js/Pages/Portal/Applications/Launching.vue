<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useBranding } from '../../../Composables/useBranding';

/**
 * The "Opening <system>" hand-off between IMIS and a system's own sign-in.
 * ApplicationLaunchController has already recorded the launch and issued the
 * one-time sign-in token inside `url`; this page shows the system's name
 * over the IMIS logo in the same splash IMIS uses for signing in (the
 * .imis-tr* styles), holds for a beat so it reads as deliberate rather than
 * a flicker, then sends the browser on. The link is the fallback if the
 * redirect is blocked. app.blade.php draws the same screen before the
 * scripts load, so keep the two in step.
 */
const props = defineProps({
    application: { type: Object, required: true },
    url: { type: String, required: true },
});

const brand = useBranding();
const HOLD_MS = 600;

// The server only ever sends an http(s) URL; checked again here so this page
// can never navigate to a javascript:/data: URI.
const safe = /^https?:\/\//i.test(props.url);

const activeDot = ref(0);
let dotsTimer = null;
let goTimer = null;

onMounted(() => {
    dotsTimer = window.setInterval(() => (activeDot.value = (activeDot.value + 1) % 3), 400);

    if (safe) goTimer = window.setTimeout(() => window.location.replace(props.url), HOLD_MS);
});

onBeforeUnmount(() => {
    window.clearInterval(dotsTimer);
    window.clearTimeout(goTimer);
});

// Ring geometry shared with TransitionSplash.vue (24×24 viewBox).
const rings = [
    { cls: 'imis-tr__ring-outer', arc: 'imis-tr__arc--navy', r: 10, dash: '62.832', offset: '20', width: '2.5' },
    { cls: 'imis-tr__ring-inner', arc: 'imis-tr__arc--red', r: 8, dash: '50.265', offset: '15', width: '1.5' },
];
</script>

<template>
    <Head :title="`Opening ${application.name}`" />

    <div class="imis-tr" role="status" aria-live="polite" aria-busy="true">
        <div class="imis-tr__card">
            <div class="imis-tr__rings" aria-hidden="true">
                <svg v-for="ring in rings" :key="ring.cls" :class="ring.cls" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" :r="ring.r" class="imis-tr__track" :stroke-width="ring.width" />
                    <circle
                        cx="12"
                        cy="12"
                        :r="ring.r"
                        :class="ring.arc"
                        :stroke-width="ring.width"
                        stroke-linecap="round"
                        :stroke-dasharray="ring.dash"
                        :stroke-dashoffset="ring.offset"
                    />
                </svg>
                <img :src="brand.app_logo" alt="" class="imis-tr__logo" draggable="false" />
            </div>

            <div class="imis-tr__msg"><span class="imis-tr__lead">Opening</span> {{ application.name }}</div>
            <div class="imis-tr__sub">Signing you in from IMIS</div>
            <div class="imis-tr__note">
                Not moving?
                <a v-if="safe" :href="url" class="font-medium text-csc-blue underline">Continue</a>
            </div>

            <div class="imis-tr__dots" aria-hidden="true">
                <span v-for="index in 3" :key="index" :class="{ 'is-active': activeDot === index - 1 }" />
            </div>
        </div>
    </div>
</template>
