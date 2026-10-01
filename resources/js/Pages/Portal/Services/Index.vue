<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import PortalCard from '../../../Components/Portal/PortalCard.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import Select from '../../../Components/Forms/Select.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    services: { type: Array, required: true },
    filters: { type: Object, required: true },
    categoryOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
});

const search = ref(props.filters.search ?? '');
const categoryId = ref(props.filters.category_id ?? '');
const organizationalUnitId = ref(props.filters.organizational_unit_id ?? '');

const applyFilters = () => {
    router.get(
        '/services',
        {
            search: search.value,
            category_id: categoryId.value,
            organizational_unit_id: organizationalUnitId.value,
        },
        { preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Services" />

    <PageHeader
        title="Services"
        description="Frontline and administrative services offered by CSC RO VIII."
        :breadcrumbs="[{ label: 'Services' }]"
    />

    <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
        <div class="w-full max-w-xs">
            <TextInput id="search" v-model="search" label="Search" placeholder="Service name or description" />
        </div>
        <div class="w-56">
            <label for="category-filter" class="block text-sm font-medium text-text">Category</label>
            <div class="mt-1">
                <Select
                    id="category-filter"
                    v-model="categoryId"
                    placeholder="All categories"
                    :options="categoryOptions.map((category) => ({ value: category.id, label: category.name }))"
                />
            </div>
        </div>
        <div class="w-56">
            <label for="unit-filter" class="block text-sm font-medium text-text">Organizational unit</label>
            <div class="mt-1">
                <Select
                    id="unit-filter"
                    v-model="organizationalUnitId"
                    placeholder="All units"
                    :options="organizationalUnitOptions.map((unit) => ({ value: unit.id, label: unit.name }))"
                />
            </div>
        </div>
        <Button type="submit" variant="secondary">Filter</Button>
    </form>

    <div v-if="services.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <PortalCard
            v-for="service in services"
            :key="service.id"
            :href="`/services/${service.id}`"
            :icon="service.icon || 'clipboard'"
            :title="service.name"
            :subtitle="service.category?.name"
            :description="service.description"
            :featured="service.featured"
        />
    </div>
    <EmptyState
        v-else
        icon="clipboard"
        title="No services found"
        description="Try a different search or filter, or check back later."
    />
</template>
