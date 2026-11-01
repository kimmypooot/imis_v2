<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import Spinner from './Spinner.vue';

/**
 * Wraps a list or grid that is refreshed in place (a search, filter, year
 * or month change). While `busy`, the content dims, ignores clicks and a
 * small spinner shows in the corner, but only after a short grace period so
 * a fast reload never flashes. Marks the region busy for screen readers.
 */
const props = defineProps({
    busy: { type: Boolean, default: false },
    // How long to wait before showing the busy look, in ms.
    delay: { type: Number, default: 150 },
});

const showing = ref(false);
let timer = null;

watch(
    () => props.busy,
    (busy) => {
        window.clearTimeout(timer);

        if (busy) {
            timer = window.setTimeout(() => (showing.value = true), props.delay);
        } else {
            showing.value = false;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => window.clearTimeout(timer));
</script>

<template>
    <div class="relative" :aria-busy="busy">
        <div class="transition-opacity duration-150" :class="showing ? 'pointer-events-none opacity-60' : ''">
            <slot />
        </div>

        <Transition enter-active-class="transition duration-150" enter-from-class="scale-90 opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div v-if="showing" class="absolute top-2 right-2 z-10 rounded-full bg-surface p-1.5 text-primary shadow-sm ring-1 ring-border">
                <Spinner size="sm" label="Updating…" />
            </div>
        </Transition>
    </div>
</template>
