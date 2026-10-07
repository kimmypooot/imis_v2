<script setup>
import Button from '../../../Components/UI/Button.vue';
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Icon from '../../../Components/UI/Icon.vue';
import ExternalLinkIndicator from '../../../Components/Portal/ExternalLinkIndicator.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    service: { type: Object, required: true },
});

const details = computed(() =>
    [
        { label: 'Purpose', value: props.service.purpose },
        { label: 'Requirements', value: props.service.requirements },
        { label: 'Procedure', value: props.service.procedure },
        { label: 'Fees', value: props.service.fees },
        { label: 'Processing time', value: props.service.processing_time },
        { label: 'Contact information', value: props.service.contact_information },
    ].filter((detail) => detail.value),
);
</script>

<template>
    <Head :title="service.name" />

    <PageHeader
        :title="service.name"
        :description="service.short_name"
        :breadcrumbs="[{ label: 'Services', href: '/services' }, { label: service.name }]"
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <Card class="lg:col-span-2">
            <div class="flex items-start gap-4">
                <span
                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-surface-muted text-primary"
                    aria-hidden="true"
                >
                    <Icon :name="service.icon || 'clipboard'" size="lg" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge v-if="service.featured" tone="info">Featured</Badge>
                        <Badge v-if="service.category" tone="neutral">{{ service.category.name }}</Badge>
                    </div>

                    <p class="mt-2 text-sm text-text-muted">
                        {{ service.description || 'No description provided.' }}
                    </p>

                    <p v-if="service.organizational_unit" class="mt-2 text-sm text-text-muted">
                        Responsible unit:
                        <Link
                            :href="`/organizational-units/${service.organizational_unit.id}`"
                            class="font-medium text-primary hover:text-primary-hover"
                        >
                            {{ service.organizational_unit.name }}
                        </Link>
                    </p>

                    <div class="mt-5">
                        <Button v-if="service.external_url" :href="service.external_url" external>
                            Access service
                            <ExternalLinkIndicator />
                        </Button>
                        <p v-else class="text-sm text-text-muted">
                            This service does not yet have an online action configured.
                        </p>
                    </div>
                </div>
            </div>
        </Card>

        <Card v-if="details.length" title="Details">
            <dl class="space-y-4 text-sm">
                <div v-for="detail in details" :key="detail.label">
                    <dt class="font-medium text-text">{{ detail.label }}</dt>
                    <dd class="mt-0.5 whitespace-pre-line text-text-muted">{{ detail.value }}</dd>
                </div>
            </dl>
        </Card>
    </div>
</template>
