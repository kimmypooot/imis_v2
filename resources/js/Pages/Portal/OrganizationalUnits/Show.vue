<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import PortalCard from '../../../Components/Portal/PortalCard.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    organizationalUnit: { type: Object, required: true },
    children: { type: Array, required: true },
    applications: { type: Array, required: true },
    services: { type: Array, required: true },
});

const breadcrumbs = computed(() => {
    const items = [{ label: 'Home', href: '/dashboard' }, { label: 'Organizational Units', href: '/organizational-units' }];

    if (props.organizationalUnit.parent) {
        items.push({
            label: props.organizationalUnit.parent.name,
            href: `/organizational-units/${props.organizationalUnit.parent.id}`,
        });
    }

    items.push({ label: props.organizationalUnit.name });

    return items;
});

const hasContactInfo = computed(() =>
    Boolean(
        props.organizationalUnit.email ||
            props.organizationalUnit.telephone ||
            props.organizationalUnit.address ||
            props.organizationalUnit.website_url,
    ),
);
</script>

<template>
    <Head :title="organizationalUnit.name" />

    <PageHeader
        :title="organizationalUnit.name"
        :description="organizationalUnit.short_name"
        :breadcrumbs="breadcrumbs"
    >
        <Badge v-if="organizationalUnit.unit_type_label" tone="neutral">
            {{ organizationalUnit.unit_type_label }}
        </Badge>
    </PageHeader>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <Card title="About">
            <p class="text-sm text-text-muted">
                {{ organizationalUnit.description || 'No description provided.' }}
            </p>
        </Card>

        <Card v-if="hasContactInfo" title="Contact information">
            <dl class="space-y-2 text-sm">
                <div v-if="organizationalUnit.email" class="flex gap-2">
                    <dt class="w-24 shrink-0 text-text-muted">Email</dt>
                    <dd class="text-text">
                        <a :href="`mailto:${organizationalUnit.email}`" class="text-link hover:text-primary-hover">
                            {{ organizationalUnit.email }}
                        </a>
                    </dd>
                </div>
                <div v-if="organizationalUnit.telephone" class="flex gap-2">
                    <dt class="w-24 shrink-0 text-text-muted">Telephone</dt>
                    <dd class="text-text">{{ organizationalUnit.telephone }}</dd>
                </div>
                <div v-if="organizationalUnit.address" class="flex gap-2">
                    <dt class="w-24 shrink-0 text-text-muted">Address</dt>
                    <dd class="text-text">{{ organizationalUnit.address }}</dd>
                </div>
                <div v-if="organizationalUnit.website_url" class="flex gap-2">
                    <dt class="w-24 shrink-0 text-text-muted">Website</dt>
                    <dd class="text-text">
                        <a
                            :href="organizationalUnit.website_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-link hover:text-primary-hover"
                        >
                            {{ organizationalUnit.website_url }}
                        </a>
                    </dd>
                </div>
            </dl>
        </Card>
    </div>

    <div class="space-y-8">
        <section v-if="children.length">
            <h2 class="text-base font-semibold text-text">Sub-units</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <PortalCard
                    v-for="child in children"
                    :key="child.id"
                    :href="`/organizational-units/${child.id}`"
                    icon="building"
                    :title="child.name"
                    :subtitle="child.short_name"
                />
            </div>
        </section>

        <section>
            <h2 class="text-base font-semibold text-text">Systems</h2>
            <div v-if="applications.length" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <PortalCard
                    v-for="application in applications"
                    :key="application.id"
                    :href="`/applications/${application.id}`"
                    :icon="application.icon || 'grid'"
                    :title="application.name"
                    :maintenance="application.under_maintenance"
                    :subtitle="application.short_name"
                />
            </div>
            <EmptyState v-else title="No systems from this unit yet" class="mt-3" />
        </section>

        <section>
            <h2 class="text-base font-semibold text-text">Services</h2>
            <div v-if="services.length" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <PortalCard
                    v-for="service in services"
                    :key="service.id"
                    :href="`/services/${service.id}`"
                    :icon="service.icon || 'clipboard'"
                    :title="service.name"
                />
            </div>
            <EmptyState v-else title="No services from this unit yet" class="mt-3" />
        </section>
    </div>
</template>
