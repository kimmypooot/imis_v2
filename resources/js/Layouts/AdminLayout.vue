<script setup>
// Shell for the two management areas: system administration (/admin) and
// Human Resources (/admin/hr). Both render inside this layout; the URL
// picks the sidebar, so each area has its own navigation. The switch at the
// top of the sidebar moves between them and back to your own pages.
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppShell from './AppShell.vue';
import AreaSwitcher from '../Components/Navigation/AreaSwitcher.vue';
import { adminNavItems, hrNavItems } from '../Utils/navigation';

const page = usePage();

const inHr = computed(() => /^\/admin\/hr(\/|\?|$)/.test(page.url));
const navItems = computed(() => (inHr.value ? hrNavItems : adminNavItems));
</script>

<template>
    <AppShell :nav-items="navItems" variant="admin">
        <template #sidebar-header>
            <AreaSwitcher />
        </template>
        <slot />
    </AppShell>
</template>
