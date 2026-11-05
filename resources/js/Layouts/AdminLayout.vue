<script setup>
// Shell for the two management areas: system administration (/admin) and
// Human Resources (/admin/hr). Both render inside this layout; the URL
// picks the sidebar, so each area has its own navigation.
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppShell from './AppShell.vue';
import Icon from '../Components/UI/Icon.vue';
import { useAuthorization } from '../Composables/useAuthorization';
import { adminAccessPermissions, adminNavItems, hrAccessPermissions, hrNavItems } from '../Utils/navigation';

const page = usePage();
const { canAny } = useAuthorization();

const inHr = computed(() => /^\/admin\/hr(\/|\?|$)/.test(page.url));
const navItems = computed(() => (inHr.value ? hrNavItems : adminNavItems));

// People with both areas (e.g. Super Administrator) switch between them here.
const areas = computed(() =>
    canAny(adminAccessPermissions) && canAny(hrAccessPermissions)
        ? [
              { label: 'Administration', short: 'Admin', href: '/admin', icon: 'shield', current: !inHr.value },
              { label: 'Human Resources', short: 'HR', href: '/admin/hr', icon: 'clipboard', current: inHr.value },
          ]
        : [],
);
</script>

<template>
    <AppShell :nav-items="navItems" variant="admin">
        <template v-if="areas.length" #sidebar-header>
            <nav aria-label="Management area" class="mx-3 mb-3 grid grid-cols-2 gap-1 rounded-lg bg-white/10 p-1">
                <Link
                    v-for="area in areas"
                    :key="area.href"
                    :href="area.href"
                    class="flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                    :class="area.current ? 'bg-white text-primary shadow-xs' : 'text-white/80 hover:text-white'"
                    :aria-current="area.current ? 'true' : undefined"
                    :aria-label="area.label"
                    :title="area.label"
                >
                    <Icon :name="area.icon" size="sm" />
                    <span aria-hidden="true">{{ area.short }}</span>
                </Link>
            </nav>
        </template>
        <slot />
    </AppShell>
</template>
