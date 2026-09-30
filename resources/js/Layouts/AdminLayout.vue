<script setup>
// Shell for the administrative back-office.
// Sidebar navigation and role-gated sections are introduced in a later phase.
</script>

<template>
    <div class="flex min-h-screen bg-surface-muted">
        <aside class="w-64 shrink-0 border-r border-border bg-csc-blue text-white">
            <div class="px-4 py-5 font-semibold">CSC RO VIII IMIS</div>
        </aside>

        <div class="flex flex-1 flex-col">
            <header class="border-b border-border bg-surface px-6 py-4">
                <slot name="header" />
            </header>

            <main class="flex-1 px-6 py-8">
                <slot />
            </main>
        </div>
    </div>
</template>
