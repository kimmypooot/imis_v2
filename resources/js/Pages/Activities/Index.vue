<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';

/**
 * Calendar of Activities: placeholder until the month calendar and list
 * are built (Task 7). The controller already sends the month's activities.
 */
defineOptions({ layout: PortalLayout });

defineProps({
    month: { type: String, required: true },
    activities: { type: Array, required: true },
});
</script>

<template>
    <div>
        <Head title="Calendar of Activities" />
        <h1 class="text-xl font-semibold">Calendar of Activities</h1>
    </div>
</template>
