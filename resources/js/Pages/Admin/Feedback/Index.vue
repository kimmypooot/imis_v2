<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import ActionButton from '../../../Components/UI/ActionButton.vue';
import Select from '../../../Components/Forms/Select.vue';
import { formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * Everything users sent through "Send feedback", newest first. Filter by
 * status and kind, search the text or the sender, open one to reply.
 */
const props = defineProps({
    feedback: { type: Object, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    typeOptions: { type: Array, required: true },
    counts: { type: Object, required: true },
});

const statusTones = { new: 'info', in_review: 'warning', applied: 'success', resolved: 'success', closed: 'neutral' };

const status = ref(props.filters.status ?? '');
const type = ref(props.filters.type ?? '');

const load = (query) =>
    router.get('/admin/feedback', query, { only: ['feedback', 'filters', 'counts'], preserveState: true, replace: true });

const applyFilters = () =>
    load({
        search: props.filters.search || undefined,
        per_page: props.filters.per_page || undefined,
        status: status.value || undefined,
        type: type.value || undefined,
    });

const filterByStatus = (value) => {
    status.value = status.value === value ? '' : value;
    applyFilters();
};

const reset = () => {
    status.value = type.value = '';
    load({});
};

const columns = [
    { key: 'subject', label: 'Subject', sortable: true },
    { key: 'type', label: 'Kind', sortable: true, value: (row) => row.type_label },
    { key: 'sender', label: 'From', sortable: false },
    { key: 'status', label: 'Status', sortable: true, value: (row) => row.status_label },
    { key: 'replies_count', label: 'Replies', sortable: false, align: 'center' },
    { key: 'created_at', label: 'Sent', sortable: true, value: (row) => formatDateTime(row.created_at) },
    { key: 'view', label: '', sortable: false, export: false, align: 'center', width: '3.5rem' },
];
</script>

<template>
    <Head title="User Feedback" />

    <PageHeader
        title="User Feedback"
        description="Feedback, suggestions and recommendations sent in by users."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'User Feedback' }]"
    />

    <Card title="All Feedback" icon="chat">
        <p class="mb-3 text-sm text-text-muted" aria-live="polite">
            <button
                v-for="option in statusOptions"
                :key="option.value"
                type="button"
                class="mr-4 inline-block rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="status === option.value ? 'font-semibold text-primary underline' : 'hover:text-primary'"
                :aria-pressed="status === option.value"
                @click="filterByStatus(option.value)"
            >
                {{ option.label }}: <strong class="text-text">{{ counts[option.value] ?? 0 }}</strong>
            </button>
        </p>

        <form class="mb-4 grid gap-3 rounded-md bg-surface-muted p-3 sm:grid-cols-2 lg:grid-cols-6" @submit.prevent="applyFilters">
            <div class="lg:col-span-2">
                <label for="filter-status" class="mb-1 block text-xs font-medium text-text-muted">Status</label>
                <Select id="filter-status" v-model="status" :options="[{ value: '', label: 'Any status' }, ...statusOptions]" @update:model-value="applyFilters" />
            </div>
            <div class="lg:col-span-2">
                <label for="filter-type" class="mb-1 block text-xs font-medium text-text-muted">Kind</label>
                <Select id="filter-type" v-model="type" :options="[{ value: '', label: 'Any kind' }, ...typeOptions.map(({ value, label }) => ({ value, label }))]" @update:model-value="applyFilters" />
            </div>
            <div class="flex items-end gap-2 lg:col-span-2">
                <button type="button" class="rounded-md px-3 py-2 text-sm text-primary hover:underline" @click="reset">Reset</button>
            </div>
        </form>

        <DataTable
            :columns="columns"
            :paginator="feedback"
            :query="filters"
            url="/admin/feedback"
            :only="['feedback', 'filters', 'counts']"
            caption="User feedback"
            export-name="imis-user-feedback"
            :numbered="false"
        >
            <template #cell-subject="{ row }">
                <Link :href="`/admin/feedback/${row.id}`" class="font-medium text-primary hover:underline" :class="{ 'font-bold': row.is_unseen }">{{ row.subject }}</Link>
            </template>
            <template #cell-status="{ row }">
                <Badge :tone="statusTones[row.status]">{{ row.status_label }}</Badge>
            </template>
            <template #cell-view="{ row }">
                <ActionButton icon="eye" tone="neutral" :label="`Open feedback: ${row.subject}`" :href="`/admin/feedback/${row.id}`" />
            </template>
        </DataTable>
    </Card>
</template>
