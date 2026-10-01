<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Table from '../../../Components/UI/Table.vue';
import Badge from '../../../Components/UI/Badge.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Button from '../../../Components/UI/Button.vue';
import Pagination from '../../../Components/UI/Pagination.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import Select from '../../../Components/Forms/Select.vue';
import { formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

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
    return Number.isNaN(date.getTime())
        ? ''
        : `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

const search = ref(props.filters.search ?? '');
const event = ref(props.filters.event ?? '');
const actorId = ref(props.filters.actor_id ?? '');
const targetType = ref(props.filters.target_type ?? '');
const targetId = ref(props.filters.target_id ?? '');
const fromDate = ref(isoToLocalDate(props.filters.from));
const toDate = ref(isoToLocalDate(props.filters.to));

const applyFilters = () => {
    router.get(
        '/admin/audit-logs',
        {
            search: search.value,
            event: event.value,
            actor_id: actorId.value,
            target_type: targetType.value,
            target_id: targetId.value,
            // Whole local days: from 00:00:00.000 to 23:59:59.999.
            from: fromDate.value ? new Date(`${fromDate.value}T00:00:00`).toISOString() : '',
            to: toDate.value ? new Date(`${toDate.value}T23:59:59.999`).toISOString() : '',
        },
        { preserveState: true, replace: true },
    );
};

const resetFilters = () => router.get('/admin/audit-logs');

const tone = (eventName) => {
    if (eventName.startsWith('auth.login_') || eventName === 'auth.lockout') return 'warning';
    if (eventName.startsWith('auth.')) return 'neutral';
    if (eventName.endsWith('roles_changed') || eventName.endsWith('permissions_changed')) return 'danger';
    return 'info';
};

const dateInputClass =
    'block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Audit Logs" />

    <PageHeader
        title="Audit Logs"
        description="Sign-ins and administrative changes, newest first. Entries are read-only."
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Audit Logs' }]"
    />

    <form class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters">
        <TextInput id="search" v-model="search" label="Search" placeholder="Description, actor, or target" />
        <div>
            <label for="event-filter" class="block text-sm font-medium text-text">Event</label>
            <div class="mt-1">
                <Select id="event-filter" v-model="event" placeholder="All events" :options="eventOptions" />
            </div>
        </div>
        <div>
            <label for="actor-filter" class="block text-sm font-medium text-text">Actor</label>
            <div class="mt-1">
                <Select id="actor-filter" v-model="actorId" placeholder="Anyone" :options="actorOptions" />
            </div>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label for="target-type-filter" class="block text-sm font-medium text-text">Target</label>
                <div class="mt-1">
                    <Select id="target-type-filter" v-model="targetType" placeholder="Any" :options="targetTypeOptions" />
                </div>
            </div>
            <TextInput id="target-id" v-model="targetId" label="Target ID" type="number" />
        </div>
        <div>
            <label for="from-filter" class="block text-sm font-medium text-text">From</label>
            <input id="from-filter" v-model="fromDate" type="date" :class="['mt-1', dateInputClass]" />
        </div>
        <div>
            <label for="to-filter" class="block text-sm font-medium text-text">To</label>
            <input id="to-filter" v-model="toDate" type="date" :class="['mt-1', dateInputClass]" />
        </div>
        <div class="flex items-end gap-2 lg:col-span-2">
            <Button type="submit" variant="secondary">Filter</Button>
            <Button variant="ghost" @click="resetFilters">Reset</Button>
        </div>
    </form>

    <Table :empty="logs.data.length === 0">
        <template #empty>
            <EmptyState icon="clipboard" title="No audit entries found" description="Try widening the filters." />
        </template>

        <thead>
            <tr>
                <th scope="col">When</th>
                <th scope="col">Event</th>
                <th scope="col">Description</th>
                <th scope="col">Actor</th>
                <th scope="col">IP address</th>
                <th scope="col"><span class="sr-only">Details</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="log in logs.data" :key="log.id">
                <td class="whitespace-nowrap text-text-muted">
                    <time :datetime="log.created_at">{{ formatDateTime(log.created_at) }}</time>
                </td>
                <td><Badge :tone="tone(log.event)">{{ log.event_label }}</Badge></td>
                <td class="text-text">{{ log.description }}</td>
                <td class="text-text-muted">{{ log.actor_label ?? 'System / unauthenticated' }}</td>
                <td class="whitespace-nowrap text-text-muted">{{ log.ip_address ?? '—' }}</td>
                <td class="text-right">
                    <Link :href="`/admin/audit-logs/${log.id}`" class="font-medium text-primary hover:text-primary-hover">
                        View
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>

    <Pagination
        :prev-url="logs.prev_page_url"
        :next-url="logs.next_page_url"
        :current-page="logs.current_page"
        :last-page="logs.last_page"
    />
</template>
