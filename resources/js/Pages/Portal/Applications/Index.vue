<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import PortalCard from '../../../Components/Portal/PortalCard.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    applications: { type: Array, required: true },
});
</script>

<template>
    <Head title="Applications" />

    <PageHeader
        title="Applications"
        description="CSC RO VIII ITG systems you can access."
        :breadcrumbs="[{ label: 'Applications' }]"
    />

    <div v-if="applications.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <PortalCard
            v-for="application in applications"
            :key="application.id"
            :href="`/applications/${application.id}`"
            :icon="application.icon || 'grid'"
            :title="application.name"
            :maintenance="application.under_maintenance"
            :subtitle="application.short_name"
            :description="application.description"
            :featured="application.featured"
        />
    </div>
    <EmptyState
        v-else
        icon="grid"
        title="No applications available"
        description="Applications registered in the directory will appear here."
    />
</template>
