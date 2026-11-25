<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import { formatDate } from '../../Utils/datetime';
import { isPreRelease } from './imisHistory';

/**
 * One release note: version, title, date, and its entries grouped by
 * kind (New / Improved / Fixed). Used by the dashboard pop-up and the
 * Changelogs admin preview. With `limit`, only that many entries are
 * listed (new features first) and the rest are left to What's New, so a
 * month's release doesn't fill the pop-up.
 */
const props = defineProps({
    release: { type: Object, required: true },
    headingLevel: { type: String, default: 'h3' },
    limit: { type: Number, default: 0 },
});

const tones = { feature: 'success', improvement: 'info', fix: 'warning' };
const order = ['feature', 'improvement', 'fix'];

const ordered = computed(() => order.flatMap((type) => props.release.items.filter((item) => item.type === type)));
const listed = computed(() => (props.limit ? ordered.value.slice(0, props.limit) : ordered.value));
const hidden = computed(() => ordered.value.length - listed.value.length);

const groups = computed(() =>
    order
        .map((type) => ({ type, items: listed.value.filter((item) => item.type === type) }))
        .filter((group) => group.items.length),
);
</script>

<template>
    <article>
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <component :is="headingLevel" class="text-base font-semibold text-text">{{ release.title }}</component>
            <Badge tone="info" pill>v{{ release.version }}</Badge>
            <Badge v-if="isPreRelease(release.version)" tone="neutral" pill>Development build</Badge>
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

        <p v-if="hidden" class="mt-4 text-sm text-text-muted">
            And {{ hidden }} more {{ hidden === 1 ? 'change' : 'changes' }}.
            <Link href="/whats-new" class="font-medium text-link hover:text-primary-hover">See them all in What's New</Link>
        </p>
    </article>
</template>
