<script setup>
import Button from '../../../Components/UI/Button.vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Icon from '../../../Components/UI/Icon.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import OrbitCircles from '../../../Components/UI/OrbitCircles.vue';
import ExternalLinkIndicator from '../../../Components/Portal/ExternalLinkIndicator.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    application: { type: Object, required: true },
});
</script>

<template>
    <Head :title="application.name" />

    <PageHeader
        :title="application.name"
        :description="application.short_name"
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Systems', href: '/applications' }, { label: application.name }]"
    />

    <div class="max-w-4xl space-y-6">
        <!-- The system and the way in: icon, who you are in it, and the launch button. -->
        <section class="relative overflow-hidden rounded-lg bg-brand p-6 text-white md:p-8">
            <OrbitCircles />
            <div class="relative z-10 flex flex-col gap-5 sm:flex-row sm:items-center">
                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25" aria-hidden="true">
                    <Icon :name="application.icon || 'grid'" size="lg" class="h-8 w-8" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span v-if="application.role" class="rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-medium ring-1 ring-white/25">Your role: {{ application.role }}</span>
                        <span v-if="application.featured" class="rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-medium ring-1 ring-white/25">Featured</span>
                        <span v-if="application.category" class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs text-white/85 ring-1 ring-white/20">{{ application.category }}</span>
                    </div>
                    <h2 class="mt-2 text-xl font-bold md:text-2xl">{{ application.name }}</h2>
                    <p v-if="application.organizational_unit" class="mt-1 text-sm text-white/80">
                        Managed by
                        <Link :href="`/organizational-units/${application.organizational_unit.id}`" class="font-medium text-white underline-offset-2 hover:underline">{{ application.organizational_unit.name }}</Link>
                    </p>
                </div>
                <Button v-if="application.url" variant="secondary" :href="`/applications/${application.id}/launch`" external class="shrink-0 self-start sm:self-center">
                    Open application
                    <ExternalLinkIndicator />
                </Button>
            </div>
        </section>

        <Alert v-if="application.under_maintenance" tone="warning" title="Under maintenance">
            {{ application.maintenance_message || 'This application is temporarily unavailable. Please check back later.' }}
            <template v-if="application.url">
                You can still open it because you manage maintenance for this system.
            </template>
        </Alert>

        <Card title="About this system" icon="info">
            <p class="text-sm leading-relaxed text-text-muted">
                {{ application.description || 'No description provided.' }}
            </p>

            <p v-if="!application.url && !application.under_maintenance" class="mt-4 rounded-md bg-surface-muted px-4 py-3 text-sm text-text-muted">
                <template v-if="application.requires_access && !application.role">
                    Access to this application is assigned by an administrator, and you don't have a role in it
                    yet. Contact the office that manages it if you need access.
                </template>
                <template v-else>This application's link has not been configured yet.</template>
            </p>
        </Card>
    </div>
</template>
