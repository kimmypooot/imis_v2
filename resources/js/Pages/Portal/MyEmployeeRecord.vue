<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import EmployeeDetails from '../../Components/HR/EmployeeDetails.vue';
import AssignmentTimeline from '../../Components/HR/AssignmentTimeline.vue';
import PlantillaHistory from '../../Components/HR/PlantillaHistory.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-1 self-service: your own employee record, read-only. Corrections go
 * through HR (later phases add update requests).
 */
defineProps({
    employee: { type: Object, default: null },
    // HR-1b: your movements between offices (read-only).
    assignments: { type: Object, default: null },
    // HR-4: the plantilla items you've held.
    plantilla: { type: Array, default: null },
});
</script>

<template>
    <Head title="My Employee Record" />

    <PageHeader
        title="My Employee Record"
        description="Your employment details as kept by HR. If something is wrong, contact the HR office."
        :breadcrumbs="[{ label: 'My Employee Record' }]"
    />

    <template v-if="employee">
        <EmployeeDetails :employee="employee" />
        <PlantillaHistory v-if="plantilla" class="mt-6" :periods="plantilla" />
        <AssignmentTimeline v-if="assignments" class="mt-6" :assignments="assignments" />
    </template>
    <EmptyState
        v-else
        icon="clipboard"
        title="No employee record yet"
        description="HR hasn't set up your employee record. Contact the HR office if you think this is a mistake."
    />
</template>
