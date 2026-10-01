<script setup>
// Shell for authenticated general application pages — the future IMIS
// portal, services, and systems directory all render inside this layout.
import AppShell from './AppShell.vue';
import { portalNavItems } from '../Utils/navigation';
</script>

<template>
    <AppShell :nav-items="portalNavItems">
        <slot />
    </AppShell>
</template>
