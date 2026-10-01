<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import PortalCard from '../../../Components/Portal/PortalCard.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    organizationalUnits: { type: Array, required: true },
});
</script>

<template>
    <Head title="Organizational Units" />

    <PageHeader
        title="Organizational Units"
        description="Regional Support Units, field offices, and other CSC RO VIII units."
        :breadcrumbs="[{ label: 'Organizational Units' }]"
    />

    <div v-if="organizationalUnits.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <PortalCard
            v-for="unit in organizationalUnits"
            :key="unit.id"
            :href="`/organizational-units/${unit.id}`"
            icon="building"
            :title="unit.name"
            :subtitle="
                unit.children_count
                    ? `${unit.short_name ? unit.short_name + ' · ' : ''}${unit.children_count} sub-unit${unit.children_count === 1 ? '' : 's'}`
                    : unit.short_name
            "
            :description="unit.description"
        />
    </div>
    <EmptyState
        v-else
        icon="building"
        title="No organizational units available"
        description="Organizational units registered in the directory will appear here."
    />
</template>
