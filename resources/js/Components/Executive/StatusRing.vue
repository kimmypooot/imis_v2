<script setup>
import { computed } from 'vue';

/**
 * A donut of how many figures are on track, at risk and behind, with the
 * share on track in the middle. Meant for the navy headline band. With no
 * targets set it draws an empty ring and says so.
 */
const props = defineProps({
    onTrack: { type: Number, default: 0 },
    atRisk: { type: Number, default: 0 },
    behind: { type: Number, default: 0 },
});

const RADIUS = 52;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const total = computed(() => props.onTrack + props.atRisk + props.behind);
const percent = computed(() => (total.value ? Math.round((props.onTrack / total.value) * 100) : null));
const segments = computed(() => {
    let offset = 0;

    return [
        { key: 'on', count: props.onTrack, stroke: 'stroke-green-300' },
        { key: 'risk', count: props.atRisk, stroke: 'stroke-amber-300' },
        { key: 'behind', count: props.behind, stroke: 'stroke-red-300' },
    ]
        .filter((segment) => segment.count)
        .map((segment) => {
            const length = (segment.count / total.value) * CIRCUMFERENCE;
            const drawn = { ...segment, length, offset };
            offset += length;

            return drawn;
        });
});
</script>

<template>
    <div class="relative h-36 w-36 shrink-0" role="img" :aria-label="total ? `${onTrack} of ${total} figures on track, ${atRisk} at risk, ${behind} behind` : 'No targets set yet'">
        <svg viewBox="0 0 120 120" class="h-full w-full -rotate-90" aria-hidden="true">
            <circle cx="60" cy="60" :r="RADIUS" fill="none" class="stroke-white/20" stroke-width="12" />
            <circle
                v-for="segment in segments"
                :key="segment.key"
                cx="60"
                cy="60"
                :r="RADIUS"
                fill="none"
                class="transition-all duration-700"
                :class="segment.stroke"
                stroke-width="12"
                :stroke-dasharray="`${Math.max(segment.length - 2, 0)} ${CIRCUMFERENCE}`"
                :stroke-dashoffset="-segment.offset"
            />
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center text-white">
            <span class="text-3xl leading-none font-bold tabular-nums">{{ percent === null ? '—' : `${percent}%` }}</span>
            <span class="mt-1 text-xs text-white/80">{{ percent === null ? 'no targets yet' : 'on track' }}</span>
        </div>
    </div>
</template>
