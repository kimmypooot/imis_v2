<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import RecordSummary from '../../Components/HR/RecordSummary.vue';
import Card from '../../Components/UI/Card.vue';
import EmployeeDetails from '../../Components/HR/EmployeeDetails.vue';
import AssignmentTimeline from '../../Components/HR/AssignmentTimeline.vue';
import PlantillaHistory from '../../Components/HR/PlantillaHistory.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-1 self-service: your own employee record, read-only. Corrections go
 * through HR (later phases add update requests).
 */
const props = defineProps({
    employee: { type: Object, default: null },
    // HR-1b: your movements between offices (read-only).
    assignments: { type: Object, default: null },
    // HR-4: the plantilla items you've held.
    plantilla: { type: Array, default: null },
});

const summary = computed(() => (props.employee ? [
    { icon: 'user', label: 'Position', value: props.employee.user.position },
    { icon: 'building', label: 'Office', value: props.employee.user.unit },
    { icon: 'shield', label: 'Employment status', value: props.employee.employment_status_label },
    { icon: 'clock', label: 'Years in the CSC', value: props.employee.csc_service?.label },
] : []));
</script>

<template>
    <Head title="My Employee Record" />

    <PageHeader
        title="My Employee Record"
        description="Your employment details as kept by HR. If something is wrong, contact the HR office."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Employee Record' }]"
    />

    <div v-if="employee" class="space-y-6">
        <RecordSummary :items="summary" />
        <EmployeeDetails :employee="employee" />
        <PlantillaHistory v-if="plantilla" :periods="plantilla" />
        <AssignmentTimeline v-if="assignments" :assignments="assignments" />
    </div>
    <Card v-else>
        <EmptyState
            icon="clipboard"
            title="No employee record yet"
            description="HR hasn't set up your employee record. Contact the HR office if you think this is a mistake."
        />
    </Card>
</template>
