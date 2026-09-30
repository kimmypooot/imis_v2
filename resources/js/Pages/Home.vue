<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '../Layouts/PortalLayout.vue';
import Button from '../Components/Button.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    appName: String,
    phase: String,
});
</script>

<template>
    <div class="rounded-lg border border-border bg-surface p-8">
        <h1 class="text-2xl font-semibold text-text">{{ appName }}</h1>
        <p class="mt-2 text-text-muted">{{ phase }}</p>
        <div class="mt-6 flex gap-3">
            <Link href="/login">
                <Button variant="primary">Sign in</Button>
            </Link>
        </div>
    </div>
</template>
