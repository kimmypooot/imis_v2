<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Button from '../Components/Button.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex min-h-screen items-center justify-center bg-surface-muted px-4">
        <div class="w-full max-w-md rounded-lg border border-border bg-surface p-8 text-center shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wider text-csc-red">
                Authenticated area
            </p>
            <h1 class="mt-2 text-xl font-semibold text-text">Welcome, {{ user?.name }}</h1>
            <p class="mt-1 text-sm text-text-muted">{{ user?.email }}</p>
            <p class="mt-4 text-sm text-text-muted">
                This is a minimal protected page proving that authentication works. The full
                portal and administrative dashboard are built in later phases.
            </p>
            <Button class="mt-6 w-full" @click="logout">Log out</Button>
        </div>
    </div>
</template>
