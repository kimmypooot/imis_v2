<script setup>
// Shell for administrative functionality — future user management, system
// administration, and configuration all render inside this layout.
import AppShell from './AppShell.vue';
import { adminNavItems } from '../Utils/navigation';
</script>

<template>
    <AppShell :nav-items="adminNavItems">
        <slot />
    </AppShell>
</template>
