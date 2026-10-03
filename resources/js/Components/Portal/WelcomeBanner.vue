<script setup>
/**
 * The legacy dashboard's welcome banner (old-imis .welcome-banner): solid
 * CSC navy with eight softly orbiting circles, the greeting with the
 * user's division badge, the system title with its version badge, and
 * the welcome message from System Settings. Holds the page's <h1>.
 */
defineProps({
    greeting: { type: String, required: true },
    division: { type: String, default: null },
    version: { type: String, default: null },
    message: { type: String, default: null },
});

// The legacy ::before/::after plus .orbit-circle-1…6: position, size,
// white opacity, orbit period (s), orbit radius (px), and direction.
const circles = [
    { style: 'top:20%;right:15%', size: 60, alpha: 0.12, duration: 10, radius: 25 },
    { style: 'bottom:25%;left:10%', size: 80, alpha: 0.08, duration: 14, radius: -35, reverse: true },
    { style: 'top:60%;right:25%', size: 40, alpha: 0.06, duration: 8, radius: 20 },
    { style: 'top:10%;left:20%', size: 50, alpha: 0.1, duration: 16, radius: -30, reverse: true },
    { style: 'bottom:10%;right:30%', size: 35, alpha: 0.05, duration: 12, radius: 28 },
    { style: 'top:40%;left:5%', size: 45, alpha: 0.07, duration: 18, radius: -25, reverse: true },
    { style: 'bottom:50%;right:5%', size: 30, alpha: 0.09, duration: 6, radius: 18 },
    { style: 'top:30%;left:35%', size: 25, alpha: 0.04, duration: 20, radius: -22, reverse: true },
];

const circleStyle = (circle) =>
    `${circle.style};width:${circle.size}px;height:${circle.size}px;` +
    `background:rgb(255 255 255 / ${circle.alpha});` +
    `--orbit-duration:${circle.duration}s;--orbit-radius:${circle.radius}px;` +
    (circle.reverse ? 'animation-direction:reverse;' : '');
</script>

<template>
    <section class="relative overflow-hidden rounded-xl bg-primary p-6 text-center text-white shadow-card-hover md:p-8">
        <span
            v-for="(circle, index) in circles"
            :key="index"
            class="orbit pointer-events-none absolute rounded-full"
            :style="circleStyle(circle)"
            aria-hidden="true"
        />

        <div class="relative z-10">
            <p class="mb-0.5 text-[0.9rem] tracking-[0.01em] text-white/80">
                {{ greeting }}
                <span v-if="division" class="ml-1 rounded-full bg-white/15 px-2.5 py-px text-[0.78rem] font-medium whitespace-nowrap text-white/75">
                    {{ division }}
                </span>
            </p>
            <h1 class="mb-2 text-[1.4rem] font-bold text-shadow-sm md:text-[1.75rem]">
                CSC RO VIII — Integrated Management Information System
                <span
                    v-if="version"
                    class="relative -top-[0.15em] ml-1.5 inline-block rounded-full border border-white/20 bg-white/10 px-2.5 py-0.5 align-middle text-[0.6rem] font-semibold tracking-[0.07em] text-white/65"
                >
                    v{{ version }}
                </span>
            </h1>
            <p class="text-base text-white/90">
                {{ message || 'Enhancing public service delivery through innovation and digital transformation.' }}
            </p>
        </div>
    </section>
</template>

<style scoped>
/* The legacy orbit: each circle travels a small circle around its spot. */
@media (prefers-reduced-motion: no-preference) {
    .orbit {
        animation: orbit var(--orbit-duration) linear infinite;
        will-change: transform;
    }
}

@keyframes orbit {
    from {
        transform: rotate(0deg) translateX(var(--orbit-radius)) rotate(0deg);
    }
    to {
        transform: rotate(360deg) translateX(var(--orbit-radius)) rotate(-360deg);
    }
}
</style>
