<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import Icon from '../../../Components/UI/Icon.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import Modal from '../../../Components/Feedback/Modal.vue';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Manage Dashboard" page. One table per dashboard category
 * (Regional Support Units, Field Offices, …, Committees); "Manage Systems"
 * sets which systems appear under that division card. A division's own
 * systems (it manages them) are always shown and locked here.
 */
const props = defineProps({
    categories: { type: Array, required: true },
    systems: { type: Array, required: true },
});

const systemsById = computed(() => Object.fromEntries(props.systems.map((system) => [system.id, system])));
const unplaced = computed(() => props.systems.filter((system) => system.unplaced));

const columns = [
    { key: 'name', label: 'Division name' },
    { key: 'short_name', label: 'Code', align: 'center' },
    { key: 'systems', label: 'Systems', align: 'center', value: (division) => division.owned_ids.length + division.listed_ids.length },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '10rem' },
];

const editing = ref(null);
const search = ref('');
const form = useForm({ application_ids: [] });

const open = (division) => {
    editing.value = division;
    search.value = '';
    form.application_ids = [...division.listed_ids];
    form.defaults();
    form.clearErrors();
};

const isOwned = (system) => editing.value?.owned_ids.includes(system.id);
const isOn = (system) => isOwned(system) || form.application_ids.includes(system.id);
const toggle = (system) => {
    form.application_ids = form.application_ids.includes(system.id)
        ? form.application_ids.filter((id) => id !== system.id)
        : [...form.application_ids, system.id];
};

const modalSystems = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.systems
        .filter((system) => !term || `${system.name} ${system.short_name ?? ''}`.toLowerCase().includes(term))
        .sort((a, b) => Number(isOn(b)) - Number(isOn(a)));
});

const save = () => {
    form.put(`/admin/dashboard-layout/${editing.value.type}/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
};
</script>

<template>
    <Head title="Manage Dashboard" />

    <PageHeader
        title="Manage Dashboard"
        description="Which systems appear under each division card of the dashboard's Systems Directory. Divisions are your offices and committees; edit them on their own pages."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Manage Dashboard' }]"
    />

    <div class="space-y-6">
        <Alert v-if="unplaced.length" tone="info">
            <strong>{{ unplaced.length }} {{ unplaced.length === 1 ? 'system is' : 'systems are' }} not placed under any division</strong>
            and {{ unplaced.length === 1 ? 'appears' : 'appear' }} under “Other Systems”: {{ unplaced.map((system) => system.short_name || system.name).join(', ') }}.
        </Alert>

        <Card v-for="category in categories" :key="category.key" :title="category.label" icon="building">
            <template #actions>
                <Link
                    :href="category.key === 'committees' ? '/admin/committees' : '/admin/organizational-units'"
                    class="text-sm font-medium text-link hover:text-primary-hover"
                >
                    Edit {{ category.key === 'committees' ? 'committees' : 'offices' }}
                </Link>
            </template>

            <DataTable :columns="columns" :rows="category.divisions" :caption="category.label" row-key="key" :exportable="false" :searchable="category.divisions.length > 10" :default-page-size="25">
                <template #cell-name="{ row }">
                    <div class="font-medium text-text">{{ row.name }}</div>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <span
                            v-for="id in [...row.owned_ids, ...row.listed_ids]"
                            :key="id"
                            class="rounded-sm bg-surface-muted px-1.5 py-0.5 text-xs text-text-muted"
                        >
                            {{ systemsById[id]?.short_name || systemsById[id]?.name }}
                        </span>
                    </div>
                </template>
                <template #cell-systems="{ row }">{{ row.owned_ids.length + row.listed_ids.length }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-actions="{ row }">
                    <Button class="!px-3 !py-1 text-xs" @click="open(row)">
                        <Icon name="grid" size="sm" />
                        Manage Systems
                    </Button>
                </template>
            </DataTable>
        </Card>
    </div>

    <Modal :open="editing !== null" :title="editing ? `Systems under ${editing.name}` : ''" tone="primary" icon="grid" size="lg" @close="editing = null">
        <template #summary>
            <label for="layout-search" class="sr-only">Search systems</label>
            <input
                id="layout-search"
                v-model="search"
                type="search"
                placeholder="Search systems…"
                class="form-control block w-full px-3 py-1.5"
            />
        </template>

        <ul class="divide-y divide-border rounded-md border border-border">
            <li v-for="system in modalSystems" :key="system.id">
                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-surface-muted" :class="{ 'cursor-not-allowed opacity-80': isOwned(system) }">
                    <input
                        type="checkbox"
                        class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-focus-ring"
                        :checked="isOn(system)"
                        :disabled="isOwned(system)"
                        @change="toggle(system)"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-text">{{ system.name }}</span>
                        <span v-if="isOwned(system)" class="block text-xs text-text-muted">Managed by this office — always shown (change it in Manage Systems)</span>
                    </span>
                    <span v-if="system.short_name" class="rounded-sm bg-surface-muted px-1.5 py-0.5 font-mono text-xs text-text-muted">{{ system.short_name }}</span>
                    <StatusBadge v-if="system.status !== 'active'" :status="system.status" />
                </label>
            </li>
        </ul>
        <p v-if="form.errors.application_ids" class="mt-2 text-sm text-danger-fg">{{ form.errors.application_ids }}</p>

        <template #footer>
            <Button variant="secondary" @click="editing = null" icon="close">Cancel</Button>
            <Button variant="success" :loading="form.processing" :disabled="!form.isDirty" @click="save" icon="check">Save</Button>
        </template>
    </Modal>
</template>
