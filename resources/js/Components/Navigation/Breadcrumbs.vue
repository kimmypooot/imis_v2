<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';

/**
 * `items`: [{ label, href }]. The last item is treated as the current
 * page (rendered as text, not a link, with aria-current="page").
 */
defineProps({
    items: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1 text-sm text-text-muted">
            <li v-for="(item, index) in items" :key="item.label" class="flex items-center gap-1">
                <Icon v-if="index > 0" name="chevronRight" size="sm" class="shrink-0" />
                <Link
                    v-if="item.href && index < items.length - 1"
                    :href="item.href"
                    class="hover:text-text"
                >
                    {{ item.label }}
                </Link>
                <span v-else class="text-text" :aria-current="index === items.length - 1 ? 'page' : undefined">
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
