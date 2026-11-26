<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import { useAuthorization } from '../../Composables/useAuthorization';
import { adminAccessPermissions, hrAccessPermissions } from '../../Utils/navigation';

/**
 * The switch at the top of the sidebar between your own pages and the
 * management areas you may open: Me (your records, leave, pay), HR and
 * Admin. Each person sees only their own areas; with no area besides
 * their own pages there is nothing to switch, so it isn't shown.
 */
const page = usePage();
const { canAny } = useAuthorization();

const inHr = computed(() => /^\/admin\/hr(\/|\?|$)/.test(page.url));
const inAdmin = computed(() => !inHr.value && /^\/admin(\/|\?|$)/.test(page.url));

const areas = computed(() => {
    const list = [{ label: 'My pages', short: 'Me', href: '/dashboard', icon: 'user', current: !inHr.value && !inAdmin.value }];

    if (canAny(hrAccessPermissions)) {
        list.push({ label: 'Human Resources', short: 'HR', href: '/admin/hr', icon: 'clipboard', current: inHr.value });
    }
    if (canAny(adminAccessPermissions)) {
        list.push({ label: 'Administration', short: 'Admin', href: '/admin', icon: 'shield', current: inAdmin.value });
    }

    return list.length > 1 ? list : [];
});

const columns = { 2: 'grid-cols-2', 3: 'grid-cols-3' };
</script>

<template>
    <nav v-if="areas.length" aria-label="Switch area" class="mx-3 mb-3 grid gap-1 rounded-lg bg-white/10 p-1" :class="columns[areas.length]">
        <Link
            v-for="area in areas"
            :key="area.href"
            :href="area.href"
            class="flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring-on-dark"
            :class="area.current ? 'bg-white text-csc-blue shadow-xs' : 'text-white/80 hover:text-white'"
            :aria-current="area.current ? 'true' : undefined"
            :aria-label="area.label"
            :title="area.label"
        >
            <Icon :name="area.icon" size="sm" />
            <span aria-hidden="true">{{ area.short }}</span>
        </Link>
    </nav>
</template>
