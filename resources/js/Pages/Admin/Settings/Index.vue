<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import SettingsGroupForm from '../../../Components/Settings/SettingsGroupForm.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    groups: { type: Array, required: true },
    canUpdate: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="System settings" />

    <PageHeader
        title="System settings"
        description="Portal-wide display and contact settings. Server configuration and credentials are managed outside the application and never appear here."
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'System settings' }]"
    />

    <div class="max-w-3xl space-y-6">
        <Alert v-if="!canUpdate" tone="info">You can view these settings but not change them.</Alert>

        <SettingsGroupForm v-for="group in groups" :key="group.key" :group="group" :can-update="canUpdate" />

        <EmptyState
            v-if="!groups.length"
            icon="cog"
            title="No settings defined"
            description="Settings are created by running the settings seeder (php artisan db:seed --class=SettingSeeder)."
        />
    </div>
</template>
