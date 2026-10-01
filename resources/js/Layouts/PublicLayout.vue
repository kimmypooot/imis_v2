<script setup>
// Minimal shell for public, unauthenticated pages (the landing page today;
// future public portal content later). Distinct from PortalLayout, which
// is the authenticated application shell with sidebar/user menu.
</script>

<template>
    <div class="flex min-h-screen flex-col bg-surface-muted">
        <header class="border-b border-border bg-surface">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
                <span class="font-semibold text-primary">CSC RO VIII</span>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
            <slot />
        </main>

        <footer class="border-t border-border bg-surface px-4 py-6 text-center text-sm text-text-muted">
            &copy; {{ new Date().getFullYear() }}
            {{ $page.props.portal?.organization || 'Civil Service Commission Regional Office VIII' }}
        </footer>
    </div>
</template>
