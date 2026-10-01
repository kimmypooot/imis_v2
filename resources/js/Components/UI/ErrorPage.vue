<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Shared full-page presentation for 403/404/419/429/500/503 — kept
 * professional and generic; never renders stack traces, SQL, file paths,
 * or other internal details (those never reach the frontend to begin
 * with — the backend only ever passes a status code and a plain message).
 */
defineProps({
    code: {
        type: [String, Number],
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    message: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-surface-muted px-4">
        <div class="w-full max-w-md rounded-lg border border-border bg-surface p-8 text-center shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wider text-csc-red">Error {{ code }}</p>
            <h1 class="mt-2 text-xl font-semibold text-text">{{ title }}</h1>
            <p class="mt-2 text-sm text-text-muted">{{ message }}</p>
            <Link
                href="/dashboard"
                class="mt-6 inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                Return to dashboard
            </Link>
        </div>
    </div>
</template>
