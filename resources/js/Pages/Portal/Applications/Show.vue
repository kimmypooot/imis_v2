<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Icon from '../../../Components/UI/Icon.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
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
        :breadcrumbs="[{ label: 'Applications', href: '/applications' }, { label: application.name }]"
    />

    <Card class="max-w-2xl">
        <div class="flex items-start gap-4">
            <span
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-surface-muted text-primary"
                aria-hidden="true"
            >
                <Icon :name="application.icon || 'grid'" size="lg" />
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge v-if="application.role" tone="success">Your role: {{ application.role }}</Badge>
                    <Badge v-if="application.featured" tone="info">Featured</Badge>
                    <Badge v-if="application.category" tone="neutral">{{ application.category }}</Badge>
                </div>

                <p class="mt-2 text-sm text-text-muted">
                    {{ application.description || 'No description provided.' }}
                </p>

                <p v-if="application.organizational_unit" class="mt-2 text-sm text-text-muted">
                    Managed by
                    <Link
                        :href="`/organizational-units/${application.organizational_unit.id}`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        {{ application.organizational_unit.name }}
                    </Link>
                </p>

                <Alert v-if="application.under_maintenance" tone="warning" title="Under maintenance" class="mt-4">
                    {{ application.maintenance_message || 'This application is temporarily unavailable. Please check back later.' }}
                    <template v-if="application.url">
                        You can still open it because you manage maintenance for this system.
                    </template>
                </Alert>

                <div class="mt-5">
                    <a
                        v-if="application.url"
                        :href="application.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                    >
                        Open application
                        <ExternalLinkIndicator />
                    </a>
                    <template v-else-if="application.under_maintenance" />
                    <p v-else-if="application.requires_access && !application.role" class="text-sm text-text-muted">
                        Access to this application is assigned by an administrator, and you don't have a role in it
                        yet. Contact the office that manages it if you need access.
                    </p>
                    <p v-else class="text-sm text-text-muted">
                        This application's link has not been configured yet.
                    </p>
                </div>
            </div>
        </div>
    </Card>
</template>
