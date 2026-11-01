<script setup>
import Button from '../../../Components/UI/Button.vue';
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Icon from '../../../Components/UI/Icon.vue';
import OrbitCircles from '../../../Components/UI/OrbitCircles.vue';
import ExternalLinkIndicator from '../../../Components/Portal/ExternalLinkIndicator.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    service: { type: Object, required: true },
});

// An icon for each kind of detail, and the long ones that get the full width.
const detailIcons = { Purpose: 'info', Requirements: 'clipboard', Procedure: 'activity', Fees: 'database', 'Processing time': 'clock', 'Contact information': 'phone' };
const wide = ['Requirements', 'Procedure'];

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
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Services', href: '/services' }, { label: service.name }]"
    />

    <div class="max-w-5xl space-y-6">
        <section class="relative overflow-hidden rounded-lg bg-linear-to-br from-csc-blue via-csc-blue to-csc-blue-dark p-6 text-white md:p-8">
            <OrbitCircles />
            <div class="relative z-10 flex flex-col gap-5 sm:flex-row sm:items-center">
                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25" aria-hidden="true">
                    <Icon :name="service.icon || 'clipboard'" size="lg" class="h-8 w-8" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span v-if="service.featured" class="rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-medium ring-1 ring-white/25">Featured</span>
                        <span v-if="service.category" class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs text-white/85 ring-1 ring-white/20">{{ service.category.name }}</span>
                    </div>
                    <h2 class="mt-2 text-xl font-bold md:text-2xl">{{ service.name }}</h2>
                    <p v-if="service.organizational_unit" class="mt-1 text-sm text-white/80">
                        Responsible unit:
                        <Link :href="`/organizational-units/${service.organizational_unit.id}`" class="font-medium text-white underline-offset-2 hover:underline">{{ service.organizational_unit.name }}</Link>
                    </p>
                </div>
                <Button v-if="service.external_url" variant="secondary" :href="service.external_url" external class="shrink-0 self-start sm:self-center">
                    Access service
                    <ExternalLinkIndicator />
                </Button>
            </div>
        </section>

        <Card title="About this service" icon="info">
            <p class="text-sm leading-relaxed text-text-muted">{{ service.description || 'No description provided.' }}</p>
            <p v-if="!service.external_url" class="mt-4 rounded-md bg-surface-muted px-4 py-3 text-sm text-text-muted">
                This service does not yet have an online action configured.
            </p>
        </Card>

        <div v-if="details.length" class="grid gap-6 md:grid-cols-2">
            <Card v-for="detail in details" :key="detail.label" :title="detail.label" :icon="detailIcons[detail.label] || 'info'" :class="wide.includes(detail.label) ? 'md:col-span-2' : ''">
                <p class="text-sm leading-relaxed whitespace-pre-line text-text-muted">{{ detail.value }}</p>
            </Card>
        </div>
    </div>
</template>
