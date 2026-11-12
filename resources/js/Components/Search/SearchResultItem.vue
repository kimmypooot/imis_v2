<script setup>
import { Link } from '@inertiajs/vue3';
import Badge from '../UI/Badge.vue';
import Icon from '../UI/Icon.vue';
import { formatDate } from '../../Utils/datetime';

/**
 * One search result, the same shape for every type (see
 * App\Support\Search\PortalSearch::present()). The type label is always
 * shown as text so a result's kind never depends on color or position.
 */
defineProps({
    result: { type: Object, required: true },
});

const icons = {
    services: 'clipboard',
    applications: 'grid',
    organizational_units: 'building',
    announcements: 'megaphone',
};
</script>

<template>
    <li>
        <Link
            :href="result.href"
            class="flex gap-3 rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
        >
            <span
                class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-md bg-surface-muted text-link sm:flex"
                aria-hidden="true"
            >
                <Icon :name="icons[result.type] ?? 'search'" />
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <Badge tone="neutral">{{ result.type_label }}</Badge>
                    <h3 class="min-w-0 break-words font-medium text-text">{{ result.title }}</h3>
                </div>
                <p v-if="result.subtitle || result.date" class="mt-0.5 text-xs text-text-muted">
                    {{ result.subtitle }}
                    <time v-if="result.date" :datetime="result.date">{{ formatDate(result.date) }}</time>
                </p>
                <p v-if="result.excerpt" class="mt-1 line-clamp-2 break-words text-sm text-text-muted">
                    {{ result.excerpt }}
                </p>
            </div>
        </Link>
    </li>
</template>
