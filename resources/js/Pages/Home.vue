<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '../Layouts/PublicLayout.vue';
import Button from '../Components/UI/Button.vue';

defineOptions({ layout: PublicLayout });

defineProps({
    appName: String,
});
</script>

<template>
    <div class="rounded-lg border border-border bg-surface p-8">
        <h1 class="text-2xl font-semibold text-text">{{ appName }}</h1>
        <p class="mt-2 text-text-muted">
            The central platform for CSC RO VIII employees to access applications, services, and
            organizational information.
        </p>
        <div class="mt-6 flex gap-3">
            <Link href="/login">
                <Button variant="primary">Sign in</Button>
            </Link>
        </div>
    </div>
</template>
