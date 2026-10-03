<script setup>
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import LeaveCard from '../../Components/HR/LeaveCard.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-6a self-service: your leave credits and leave card, read-only.
 * Filing leave arrives with HR-6b.
 */
defineProps({
    hasRecord: { type: Boolean, required: true },
    year: { type: Number, default: null },
    years: { type: Array, default: () => [] },
    balances: { type: Array, default: () => [] },
    card: { type: Object, default: null },
});

const changeYear = (value) => router.get('/my/leave', { year: value }, { preserveScroll: true });
</script>

<template>
    <Head title="My Leave" />

    <PageHeader
        title="My Leave"
        description="Your leave credits as HR keeps them. If something looks wrong, contact the HR office."
        :breadcrumbs="[{ label: 'My Leave' }]"
    />

    <LeaveCard v-if="hasRecord" :year="year" :years="years" :balances="balances" :card="card" @year="changeYear" />
    <EmptyState
        v-else
        icon="clipboard"
        title="No employee record yet"
        description="HR hasn't set up your employee record. Contact the HR office if you think this is a mistake."
    />
</template>
