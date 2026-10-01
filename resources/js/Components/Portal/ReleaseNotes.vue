<script setup>
import { computed } from 'vue';
import Badge from '../UI/Badge.vue';
import { formatDate } from '../../Utils/datetime';

/**
 * One release note: version, title, date, and its entries grouped by
 * kind (New / Improved / Fixed). Shared by the dashboard pop-up and the
 * What's New history page.
 */
const props = defineProps({
    release: { type: Object, required: true },
    headingLevel: { type: String, default: 'h3' },
});

const tones = { feature: 'success', improvement: 'info', fix: 'warning' };
const order = ['feature', 'improvement', 'fix'];

const groups = computed(() =>
    order
        .map((type) => ({ type, items: props.release.items.filter((item) => item.type === type) }))
        .filter((group) => group.items.length),
);
</script>

<template>
    <article>
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <component :is="headingLevel" class="text-base font-semibold text-text">{{ release.title }}</component>
            <span class="rounded-full bg-info-bg px-2.5 py-0.5 text-xs font-medium text-primary">v{{ release.version }}</span>
            <time v-if="release.release_date" :datetime="release.release_date" class="text-xs text-text-muted">
                {{ formatDate(release.release_date) }}
            </time>
        </div>

        <div class="mt-3 space-y-3">
            <section v-for="group in groups" :key="group.type">
                <ul class="space-y-1.5">
                    <li v-for="(item, index) in group.items" :key="index" class="flex items-start gap-2 text-sm text-text">
                        <Badge :tone="tones[item.type]" class="mt-0.5 shrink-0">{{ item.type_label }}</Badge>
                        <span>{{ item.description }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </article>
</template>
