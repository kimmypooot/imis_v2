<script setup>
import Button from '../../../Components/UI/Button.vue';
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import ActionButton from '../../../Components/UI/ActionButton.vue';
import Select from '../../../Components/Forms/Select.vue';
import { formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Admin Audit Log" page: every administrative change and
 * sign-in event, read-only, newest first. Filters narrow it on the server.
 */
const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    eventOptions: { type: Array, required: true },
    actorOptions: { type: Array, required: true },
    targetTypeOptions: { type: Array, required: true },
});

const pad = (value) => String(value).padStart(2, '0');

// The URL carries ISO instants; the date inputs show the viewer's local date.
const isoToLocalDate = (iso) => {
    if (!iso) return '';
    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '' : `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

const event = ref(props.filters.event ?? '');
const actorId = ref(props.filters.actor_id ?? '');
const targetType = ref(props.filters.target_type ?? '');
const fromDate = ref(isoToLocalDate(props.filters.from));
const toDate = ref(isoToLocalDate(props.filters.to));

const applyFilters = () => {
    router.get(
        '/admin/audit-logs',
        {
            search: props.filters.search || undefined,
            per_page: props.filters.per_page || undefined,
            event: event.value || undefined,
            actor_id: actorId.value || undefined,
            target_type: targetType.value || undefined,
            target_id: props.filters.target_id || undefined,
            // Whole local days: from 00:00:00.000 to 23:59:59.999.
            from: fromDate.value ? new Date(`${fromDate.value}T00:00:00`).toISOString() : undefined,
            to: toDate.value ? new Date(`${toDate.value}T23:59:59.999`).toISOString() : undefined,
        },
        { only: ['logs', 'filters'], preserveState: true, replace: true },
    );
};

const reset = () => {
    event.value = actorId.value = targetType.value = fromDate.value = toDate.value = '';
    router.get('/admin/audit-logs', {}, { only: ['logs', 'filters'], preserveState: true, replace: true });
};

const columns = [
    { key: 'event', label: 'Action', sortable: false, value: (row) => row.event_label },
    { key: 'actor_label', label: 'Performed by', sortable: false, value: (row) => row.actor_label ?? 'System' },
    { key: 'target_label', label: 'Target', sortable: false },
    { key: 'ip_address', label: 'IP address', sortable: false },
    { key: 'description', label: 'Details', sortable: false },
    { key: 'created_at', label: 'Timestamp', sortable: false, value: (row) => formatDateTime(row.created_at) },
    { key: 'view', label: '', sortable: false, export: false, align: 'center', width: '3.5rem' },
];
</script>

<template>
    <Head title="Admin Audit Log" />

    <PageHeader
        title="Admin Audit Log"
        description="Every administrative change and sign-in event. Entries can't be edited or deleted from IMIS."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Admin Audit Log' }]"
    />

    <Card title="All Admin Actions" icon="clipboard">
        <form class="mb-4 grid gap-3 rounded-md bg-surface-muted p-3 sm:grid-cols-2 lg:grid-cols-6" @submit.prevent="applyFilters">
            <div class="lg:col-span-2">
                <label for="filter-event" class="mb-1 block text-xs font-medium text-text-muted">Action</label>
                <Select id="filter-event" v-model="event" :options="[{ value: '', label: 'All actions' }, ...eventOptions]" />
            </div>
            <div class="lg:col-span-2">
                <label for="filter-actor" class="mb-1 block text-xs font-medium text-text-muted">Performed by</label>
                <Select id="filter-actor" v-model="actorId" :options="[{ value: '', label: 'Anyone' }, ...actorOptions]" />
            </div>
            <div class="lg:col-span-2">
                <label for="filter-target" class="mb-1 block text-xs font-medium text-text-muted">Target type</label>
                <Select id="filter-target" v-model="targetType" :options="[{ value: '', label: 'Any' }, ...targetTypeOptions]" />
            </div>
            <div class="lg:col-span-2">
                <label for="filter-from" class="mb-1 block text-xs font-medium text-text-muted">From</label>
                <input id="filter-from" v-model="fromDate" type="date" class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm" />
            </div>
            <div class="lg:col-span-2">
                <label for="filter-to" class="mb-1 block text-xs font-medium text-text-muted">To</label>
                <input id="filter-to" v-model="toDate" type="date" class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm" />
            </div>
            <div class="flex items-end gap-2 lg:col-span-2">
                <Button type="submit">Apply filters</Button>
                <button type="button" class="rounded-md px-3 py-2 text-sm text-primary hover:underline" @click="reset">Reset</button>
            </div>
        </form>

        <DataTable
            :columns="columns"
            :paginator="logs"
            :query="filters"
            url="/admin/audit-logs"
            :only="['logs', 'filters']"
            caption="Admin audit log"
            export-name="imis-admin-audit-log"
            :numbered="false"
        >
            <template #cell-event="{ row }">
                <span class="rounded-full bg-info-bg px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-primary">{{ row.event_label }}</span>
            </template>
            <template #cell-description="{ row }">
                <span class="line-clamp-2 max-w-sm text-text-muted">{{ row.description }}</span>
            </template>
            <template #cell-view="{ row }">
                <ActionButton icon="eye" tone="neutral" :label="`View entry ${row.id}`" :href="`/admin/audit-logs/${row.id}`" />
            </template>
        </DataTable>
    </Card>
</template>
