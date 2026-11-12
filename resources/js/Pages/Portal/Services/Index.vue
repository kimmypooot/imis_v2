<script setup>
import InputLabel from '../../../Components/Forms/InputLabel.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import BusyRegion from '../../../Components/UI/BusyRegion.vue';
import { useBusy } from '../../../Composables/useBusy';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import PortalCard from '../../../Components/Portal/PortalCard.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Icon from '../../../Components/UI/Icon.vue';
import Select from '../../../Components/Forms/Select.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    services: { type: Array, required: true },
    filters: { type: Object, required: true },
    categoryOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
});

const { busy, handlers } = useBusy();
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
        { preserveState: true, replace: true, ...handlers },
    );
};

// Filter as you go: selects apply at once, typing a moment after the last key.
let timer = null;
watch([categoryId, organizationalUnitId], applyFilters);
watch(search, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(applyFilters, 350);
});
onBeforeUnmount(() => window.clearTimeout(timer));

const filtering = computed(() => Boolean(props.filters.search || props.filters.category_id || props.filters.organizational_unit_id));
const clear = () => {
    search.value = '';
    categoryId.value = '';
    organizationalUnitId.value = '';
};
</script>

<template>
    <Head title="Services" />

    <PageHeader
        title="Services"
        description="Frontline and administrative services offered by CSC RO VIII."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Services' }]"
    />

    <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
        <div class="w-full max-w-xs">
            <InputLabel for="search">Search</InputLabel>
            <div class="relative mt-1">
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                <input id="search" v-model="search" type="search" autocomplete="off" placeholder="Service name or description" class="block w-full form-control py-2 pr-3 pl-9" />
            </div>
        </div>
        <div class="w-56">
            <InputLabel for="category-filter">Category</InputLabel>
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
            <InputLabel for="unit-filter">Organizational unit</InputLabel>
            <div class="mt-1">
                <Select
                    id="unit-filter"
                    v-model="organizationalUnitId"
                    placeholder="All units"
                    :options="organizationalUnitOptions.map((unit) => ({ value: unit.id, label: unit.name }))"
                />
            </div>
        </div>
        <button v-if="filtering" type="button" class="rounded-sm pb-2 text-sm font-medium text-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="clear">Clear filters</button>
        <p class="pb-2 text-sm text-text-muted sm:ml-auto" role="status">{{ services.length }} service{{ services.length === 1 ? '' : 's' }}</p>
    </form>

    <BusyRegion :busy="busy">
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
    </BusyRegion>
</template>
