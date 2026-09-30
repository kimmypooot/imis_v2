<script setup>
import AuthBrandHeader from '../Components/AuthBrandHeader.vue';
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-surface-muted px-4 py-12">
        <div class="w-full max-w-md rounded-lg border border-border bg-surface p-8 shadow-sm">
            <AuthBrandHeader />
            <slot />
        </div>
    </div>
</template>
