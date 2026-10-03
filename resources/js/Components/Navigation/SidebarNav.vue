<script setup>
import { computed, reactive } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import { useAuthorization } from '../../Composables/useAuthorization';

/**
 * `items`: a mix of leaf entries ({ label, href, icon, permission? }) and
 * group entries ({ group: label, icon?, items: [leaf, ...] }) — see
 * Utils/navigation.js. `permission` may be a single string (must have it)
 * or an array (must have at least one). Leaf entries whose `permission`
 * the user lacks are hidden; a group with no visible children is hidden
 * entirely. This is UX only — every route is independently authorized
 * server-side regardless of what this component renders.
 *
 * `variant`:
 * - `portal`: navy sidebar, groups as plain headings.
 * - `admin`: the legacy NiceAdmin sidebar — white, with collapsible groups
 *   ("User Management", "System Administration") whose children are
 *   marked with a dot, expanded automatically when they hold the current
 *   page.
 */
const props = defineProps({
    items: {
        type: Array,
        required: true,
    },
    variant: {
        type: String,
        default: 'portal',
        validator: (value) => ['portal', 'admin'].includes(value),
    },
});

defineEmits(['navigate']);

const { can, canAny } = useAuthorization();
const page = usePage();

const isPermitted = (item) => {
    // `flag`: shown only when the server says so (e.g. leave approvers).
    if (item.flag && !page.props.flags?.[item.flag]) {
        return false;
    }

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
 * A `localOnly` group (Developer) shows only when the app runs locally.
 */
const entries = computed(() =>
    props.items.reduce((acc, item) => {
        if (item.group) {
            const visible = item.localOnly && !page.props.app?.local ? [] : item.items.filter(isPermitted);

            if (visible.length) {
                acc.push({ type: 'group', label: item.group, icon: item.icon, items: visible });
            }

            return acc;
        }

        if (isPermitted(item)) {
            acc.push({ type: 'link', ...item });
        }

        return acc;
    }, []),
);

// Stable id for a portal group heading, so the group is labelled by it.
const groupId = (group) => `nav-group-${group.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

const currentPath = computed(() => page.url.split('?')[0]);

// Exact match for section roots (e.g. /admin), prefix match otherwise.
const isActive = (href) =>
    currentPath.value === href || (href !== '/admin' && href !== '/dashboard' && currentPath.value.startsWith(`${href}/`));

const expanded = reactive({});
const isExpanded = (group) => expanded[group.label] ?? group.items.some((item) => isActive(item.href));
const toggle = (group) => (expanded[group.label] = !isExpanded(group));

const linkClass = (href) => {
    if (props.variant === 'admin') {
        return isActive(href) ? 'bg-info-bg text-primary' : 'text-text hover:bg-info-bg/60 hover:text-primary';
    }

    return isActive(href) ? 'bg-white/15 text-white' : 'text-white/80 hover:bg-white/10 hover:text-white';
};
</script>

<template>
    <nav class="flex flex-col gap-1 px-3" aria-label="Primary">
        <template v-for="entry in entries" :key="entry.type === 'group' ? entry.label : entry.href">
            <Link
                v-if="entry.type === 'link'"
                :href="entry.href"
                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                :class="linkClass(entry.href)"
                :aria-current="isActive(entry.href) ? 'page' : undefined"
                @click="$emit('navigate')"
            >
                <Icon :name="entry.icon" size="sm" />
                {{ entry.label }}
            </Link>

            <!-- Admin: collapsible group -->
            <div v-else-if="variant === 'admin'">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-medium text-text transition-colors hover:bg-info-bg/60 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :aria-expanded="isExpanded(entry)"
                    @click="toggle(entry)"
                >
                    <Icon :name="entry.icon || 'grid'" size="sm" />
                    <span class="flex-1">{{ entry.label }}</span>
                    <Icon name="chevronDown" size="sm" class="transition-transform" :class="{ 'rotate-180': isExpanded(entry) }" />
                </button>
                <ul v-show="isExpanded(entry)" class="mt-0.5 space-y-0.5">
                    <li v-for="item in entry.items" :key="item.href">
                        <Link
                            :href="item.href"
                            class="flex items-center gap-3 rounded-md py-1.5 pr-3 pl-9 text-sm transition-colors"
                            :class="linkClass(item.href)"
                            :aria-current="isActive(item.href) ? 'page' : undefined"
                            @click="$emit('navigate')"
                        >
                            <span
                                class="h-1.5 w-1.5 shrink-0 rounded-full border"
                                :class="isActive(item.href) ? 'border-primary bg-primary' : 'border-current'"
                                aria-hidden="true"
                            />
                            {{ item.label }}
                        </Link>
                    </li>
                </ul>
            </div>

            <!-- Portal: group as a heading -->
            <div v-else role="group" :aria-labelledby="groupId(entry)" class="mt-4 first:mt-0">
                <p :id="groupId(entry)" class="px-3 pb-1 text-xs font-semibold tracking-wide text-white/50 uppercase">
                    {{ entry.label }}
                </p>
                <Link
                    v-for="item in entry.items"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                    :class="linkClass(item.href)"
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
