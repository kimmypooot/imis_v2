<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import { useAuthorization } from '../../Composables/useAuthorization';

/**
 * `items`: a mix of leaf entries ({ label, href, icon, permission? }) and
 * group entries ({ group: label, items: [leaf, ...] }) — see
 * Utils/navigation.js. `permission` may be a single string (must have it)
 * or an array (must have at least one). Leaf entries whose `permission`
 * the user lacks are hidden; a group with no visible children is hidden
 * entirely. This is UX only — every route is independently authorized
 * server-side regardless of what this component renders.
 */
const props = defineProps({
    items: {
        type: Array,
        required: true,
    },
});

defineEmits(['navigate']);

const { can, canAny } = useAuthorization();
const page = usePage();

const isPermitted = (item) => {
    if (!item.permission) {
        return true;
    }

    return Array.isArray(item.permission) ? canAny(item.permission) : can(item.permission);
};

/**
 * Normalizes `items` into a flat list of render entries — either
 * `{ type: 'link', ...leaf }` or `{ type: 'group', label, items }` — so
 * the template doesn't need to branch on shape, and groups with nothing
 * visible inside them are dropped rather than rendered as an empty header.
 */
const entries = computed(() =>
    props.items.reduce((acc, item) => {
        if (item.group) {
            const visible = item.items.filter(isPermitted);

            if (visible.length) {
                acc.push({ type: 'group', label: item.group, items: visible });
            }

            return acc;
        }

        if (isPermitted(item)) {
            acc.push({ type: 'link', ...item });
        }

        return acc;
    }, []),
);

const isActive = (href) => page.url === href || page.url.startsWith(`${href}/`);
</script>

<template>
    <nav class="flex flex-col gap-1 px-2" aria-label="Primary">
        <template v-for="entry in entries" :key="entry.type === 'group' ? entry.label : entry.href">
            <Link
                v-if="entry.type === 'link'"
                :href="entry.href"
                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    isActive(entry.href)
                        ? 'bg-white/15 text-white'
                        : 'text-white/80 hover:bg-white/10 hover:text-white'
                "
                :aria-current="isActive(entry.href) ? 'page' : undefined"
                @click="$emit('navigate')"
            >
                <Icon :name="entry.icon" size="sm" />
                {{ entry.label }}
            </Link>

            <div v-else class="mt-3 first:mt-0">
                <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-white/50">
                    {{ entry.label }}
                </p>
                <Link
                    v-for="item in entry.items"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                    :class="
                        isActive(item.href)
                            ? 'bg-white/15 text-white'
                            : 'text-white/80 hover:bg-white/10 hover:text-white'
                    "
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                    @click="$emit('navigate')"
                >
                    <Icon :name="item.icon" size="sm" />
                    {{ item.label }}
                </Link>
            </div>
        </template>
    </nav>
</template>
