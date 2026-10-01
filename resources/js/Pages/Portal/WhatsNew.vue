<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import ReleaseNotes from '../../Components/Portal/ReleaseNotes.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    releases: { type: Array, required: true },
});
</script>

<template>
    <Head title="What's New" />

    <PageHeader
        title="What's New"
        description="Release notes for IMIS, newest first."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: `What's New` }]"
    />

    <EmptyState
        v-if="releases.length === 0"
        icon="megaphone"
        title="No release notes yet"
        description="Notes about new features and fixes will appear here."
    />

    <div v-else class="max-w-3xl space-y-4">
        <Card v-for="release in releases" :key="release.id">
            <ReleaseNotes :release="release" heading-level="h2" />
        </Card>
    </div>
</template>
