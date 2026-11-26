<script setup>
// Shell for authenticated general application pages — the IMIS portal,
// services, the systems directory and your own records. People with HR or
// administration access switch to those areas at the top of the sidebar.
import AppShell from './AppShell.vue';
import AreaSwitcher from '../Components/Navigation/AreaSwitcher.vue';
import { portalNavItems } from '../Utils/navigation';
</script>

<template>
    <AppShell :nav-items="portalNavItems">
        <template #sidebar-header>
            <AreaSwitcher />
        </template>
        <slot />
    </AppShell>
</template>
