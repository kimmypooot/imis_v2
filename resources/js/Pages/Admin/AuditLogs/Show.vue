<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import { formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    log: { type: Object, required: true },
});

const fields = computed(() => [
    { label: 'When', value: formatDateTime(props.log.created_at) },
    { label: 'Event', value: `${props.log.event_label} (${props.log.event})` },
    { label: 'Action', value: props.log.action },
    {
        label: 'Actor',
        value: props.log.actor_label
            ? `${props.log.actor_label} — user #${props.log.actor_id}`
            : 'System / unauthenticated',
    },
    {
        label: 'Target',
        value: props.log.target_type
            ? `${props.log.target_label ?? '—'} — ${props.log.target_type} #${props.log.target_id}`
            : '—',
    },
    { label: 'IP address', value: props.log.ip_address ?? '—' },
    { label: 'User agent', value: props.log.user_agent ?? '—' },
]);

// Rendered as text inside <pre> (auto-escaped), never as HTML.
const metadata = computed(() => (props.log.metadata ? JSON.stringify(props.log.metadata, null, 2) : null));
</script>

<template>
    <Head :title="`Audit entry #${log.id}`" />

    <PageHeader
        :title="log.description"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Audit Logs', href: '/admin/audit-logs' },
            { label: `Entry #${log.id}` },
        ]"
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <Card title="Entry" class="lg:col-span-1">
            <dl class="space-y-4 text-sm">
                <div v-for="field in fields" :key="field.label">
                    <dt class="font-medium text-text">{{ field.label }}</dt>
                    <dd class="mt-0.5 break-words text-text-muted">{{ field.value }}</dd>
                </div>
            </dl>
            <template #footer>
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link
                        v-if="log.actor_id"
                        :href="`/admin/audit-logs?actor_id=${log.actor_id}`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        More by this actor
                    </Link>
                    <Link
                        v-if="log.target_type"
                        :href="`/admin/audit-logs?target_type=${log.target_type}&target_id=${log.target_id}`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        History of this target
                    </Link>
                </div>
            </template>
        </Card>

        <Card title="Details" class="lg:col-span-2">
            <pre
                v-if="metadata"
                class="overflow-x-auto rounded-md bg-surface-muted p-4 text-xs leading-relaxed text-text"
            >{{ metadata }}</pre>
            <p v-else class="text-sm text-text-muted">No additional details were recorded for this event.</p>
        </Card>
    </div>
</template>
