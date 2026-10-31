<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

/**
 * The page footer shared by the portal and admin shells: the information
 * pages (What's New, Privacy notice, About IMIS), help links and the
 * version. "About IMIS" and "Send feedback" open dialogs that belong to
 * the shell, so they are emitted rather than handled here.
 */
const emit = defineEmits(['about', 'feedback']);

const page = usePage();
const version = computed(() => page.props.app?.version);
const signedIn = computed(() => Boolean(page.props.auth?.user));
const year = new Date().getFullYear();

const linkClass =
    'rounded text-xs text-text-muted transition-colors hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <footer class="mt-auto border-t border-border bg-surface">
        <div
            class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-4 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8"
        >
            <p class="text-xs text-text-muted">
                &copy; {{ year }} Civil Service Commission Regional Office VIII
                <span v-if="version" class="whitespace-nowrap">· IMIS v{{ version }}</span>
            </p>

            <nav aria-label="Footer" class="flex flex-wrap gap-x-5 gap-y-1">
                <Link href="/whats-new" :class="linkClass">What's New</Link>
                <Link href="/privacy" :class="linkClass">Privacy notice</Link>
                <button type="button" :class="linkClass" @click="emit('about')">About IMIS</button>
                <button v-if="signedIn" type="button" :class="linkClass" @click="emit('feedback')">Send feedback</button>
                <Link v-if="signedIn" href="/account" :class="linkClass">Account settings</Link>
            </nav>
        </div>
    </footer>
</template>
