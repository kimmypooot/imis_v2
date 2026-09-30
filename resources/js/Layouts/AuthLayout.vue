<script setup>
// Shell for unauthenticated flows (login, password reset, etc.).
// Business logic for authentication is introduced in a later phase.
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-surface-muted px-4">
        <div class="w-full max-w-md rounded-lg border border-border bg-surface p-8 shadow-sm">
            <slot />
        </div>
    </div>
</template>
