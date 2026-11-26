<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import OrbitCircles from '@cscro8/ui/Components/UI/OrbitCircles.vue';
import Skeleton from '@cscro8/ui/Components/UI/Skeleton.vue';
import { greetingName } from '../../Composables/useSplash';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The HR dashboard banner: a short greeting, how many things are waiting on
 * HR, and where this month's HR cycle stands, step by step (App\Support\HR\
 * HrMonth), with buttons for the tasks at hand. `month` is a deferred prop:
 * null until it arrives, so its part of the banner shows a placeholder.
 */
defineProps({
    month: { type: Object, default: null },
    // Where "waiting on HR" leads: the Waiting on HR card, when it is on the page.
    waitingHref: { type: String, default: null },
    generatedAt: { type: String, default: null },
    refreshing: { type: Boolean, default: false },
});

defineEmits(['refresh']);

const page = usePage();
const user = computed(() => page.props.auth.user);

// From the browser's own clock, like the staff home page.
const greeting = computed(() => {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    const name = greetingName(user.value);

    return name ? `${part}, ${name}` : part;
});
const today = new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

const states = {
    done: { icon: 'checkCircle', class: 'text-white', label: 'Done' },
    active: { icon: 'arrowCircleRight', class: 'text-white', label: 'In progress' },
    todo: { icon: 'clock', class: 'text-white/50', label: 'Not yet' },
};
</script>

<template>
    <section class="relative mb-4 overflow-hidden rounded-lg bg-brand px-6 py-5 text-white" aria-labelledby="hr-banner-title">
        <OrbitCircles />

        <div class="relative z-10 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="flex flex-wrap items-center gap-2 text-xs font-semibold tracking-wide text-white/80 uppercase">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 normal-case"><Icon name="clipboard" size="sm" />Human Resources</span>
                        <span class="normal-case">{{ today }}</span>
                    </p>
                    <h1 id="hr-banner-title" class="mt-2 text-xl font-semibold sm:text-2xl">{{ greeting }}</h1>
                </div>
                <p v-if="generatedAt" class="flex flex-wrap items-center gap-2 text-xs text-white/80">
                    Data as of {{ formatDateTime(generatedAt) }}
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md border border-white/30 px-2 py-0.5 font-medium text-white transition-colors hover:bg-white/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring-on-dark disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="refreshing"
                        @click="$emit('refresh')"
                    >
                        <Icon name="refresh" size="sm" :class="{ 'motion-safe:animate-spin': refreshing }" />
                        Refresh
                    </button>
                </p>
            </div>

            <!-- Waiting on HR, and this month step by step. -->
            <div v-if="!month" class="grid gap-4 sm:grid-cols-[10rem_1fr]" role="status" aria-label="Loading this month's HR work">
                <Skeleton class="h-24 w-full opacity-30" />
                <div class="space-y-2">
                    <Skeleton v-for="n in 4" :key="n" class="h-4 opacity-30" :style="{ maxWidth: `${[18, 14, 16, 20][n - 1]}rem` }" />
                </div>
            </div>
            <div v-else class="grid gap-4 sm:grid-cols-[10rem_1fr]">
                <component
                    :is="waitingHref ? 'a' : 'div'"
                    :href="waitingHref ?? undefined"
                    class="flex flex-col items-center justify-center rounded-lg bg-white/10 px-4 py-4 text-center"
                    :class="{ 'transition-colors hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring-on-dark': waitingHref }"
                >
                    <span class="text-stat leading-none font-bold tabular-nums">{{ month.waiting }}</span>
                    <span class="mt-1 text-xs text-white/80">waiting on HR</span>
                </component>

                <div v-if="month.steps.length">
                    <h2 class="text-sm font-semibold">{{ month.month }}, step by step</h2>
                    <ul class="mt-2 gap-x-6 text-sm lg:columns-2">
                        <li v-for="step in month.steps" :key="step.key" class="mb-1.5 break-inside-avoid">
                            <Link :href="step.href" class="group flex items-start gap-2 rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring-on-dark">
                                <Icon :name="states[step.state].icon" size="sm" class="mt-0.5 shrink-0" :class="states[step.state].class" />
                                <span class="sr-only">{{ states[step.state].label }}:</span>
                                <span class="min-w-0">
                                    <span class="font-medium group-hover:underline" :class="{ 'text-white/70': step.state === 'todo' }">{{ step.label }}</span>
                                    <span class="text-white/75"> · {{ step.detail }}</span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
                <p v-else class="self-center text-sm text-white/80">Your HR pages are in the side navigation.</p>
            </div>

            <div v-if="month?.actions.length" class="flex flex-wrap gap-2">
                <Link
                    v-for="action in month.actions"
                    :key="action.href"
                    :href="action.href"
                    class="inline-flex items-center gap-1.5 rounded-md bg-white/15 px-3 py-2 text-sm font-medium transition-colors hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring-on-dark"
                >
                    <Icon :name="action.icon" size="sm" />
                    {{ action.label }}
                </Link>
            </div>
        </div>
    </section>
</template>
