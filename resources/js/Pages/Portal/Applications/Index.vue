<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import Icon from '../../../Components/UI/Icon.vue';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import PortalCard from '../../../Components/Portal/PortalCard.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    applications: { type: Array, required: true },
});

// Filter the list you already have as you type.
const search = ref('');
const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle
        ? props.applications.filter((application) => [application.name, application.short_name, application.description].some((text) => String(text ?? '').toLowerCase().includes(needle)))
        : props.applications;
});
</script>

<template>
    <Head title="Systems" />

    <PageHeader
        title="Systems"
        description="CSC RO VIII ITG systems you can access."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Systems' }]"
    />

    <div v-if="applications.length > 6" class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2">
        <div class="relative w-full max-w-md">
            <label for="systems-search" class="sr-only">Search systems</label>
            <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
            <input id="systems-search" v-model="search" type="search" autocomplete="off" placeholder="Search your systems…" class="block w-full form-control py-2.5 pr-3 pl-9" />
        </div>
        <p class="text-sm text-text-muted" role="status">{{ shown.length }} of {{ applications.length }} systems</p>
    </div>

    <div v-if="shown.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <PortalCard
            v-for="application in shown"
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
        :title="applications.length ? 'No system matches your search' : 'No systems available'"
        :description="applications.length ? 'Try a simpler word.' : 'Systems registered in the directory will appear here.'"
    />
</template>
