<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import AdminCard from '../../Components/UI/AdminCard.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Button from '../../Components/UI/Button.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * Your notifications (the top-bar bell). Opening one marks it read and
 * takes you to what it's about.
 */
const props = defineProps({
    notifications: { type: Array, required: true },
});

const unread = computed(() => props.notifications.filter((notification) => !notification.read).length);
const readAll = () => router.post('/notifications/read-all', {}, { preserveScroll: true });
</script>

<template>
    <Head title="Notifications" />

    <PageHeader title="Notifications" :breadcrumbs="[{ label: 'Notifications' }]">
        <template v-if="unread" #actions>
            <Button variant="secondary" @click="readAll">Mark all as read</Button>
        </template>
    </PageHeader>

    <AdminCard v-if="notifications.length" icon="bell" :title="unread ? `${unread} unread` : 'All read'">
        <ul class="divide-y divide-border">
            <li v-for="notification in notifications" :key="notification.id">
                <a :href="`/notifications/${notification.id}`" class="flex gap-3 px-1 py-3 hover:bg-surface-muted">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="notification.read ? 'bg-transparent' : 'bg-primary'" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="block text-sm text-text" :class="notification.read ? '' : 'font-semibold'">
                            {{ notification.title }}<span v-if="!notification.read" class="sr-only"> (unread)</span>
                        </span>
                        <span class="block text-sm text-text-muted">{{ notification.message }}</span>
                        <span class="block text-xs text-text-muted">{{ formatDateTime(notification.created_at) }}</span>
                    </span>
                </a>
            </li>
        </ul>
    </AdminCard>
    <EmptyState v-else icon="bell" title="No notifications" description="Updates on your leave applications, and anything waiting for your action, will show here." />
</template>
