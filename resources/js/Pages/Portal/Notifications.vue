<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Icon from '../../Components/UI/Icon.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Button from '../../Components/UI/Button.vue';
import { dayGroup, formatDateTime, timeAgo } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * Your notifications (the top-bar bell). Opening one marks it read and
 * takes you to what it's about. Grouped by day, with an Unread filter.
 */
const props = defineProps({
    notifications: { type: Array, required: true },
});

const unread = computed(() => props.notifications.filter((notification) => !notification.read).length);
const readAll = () => router.post('/notifications/read-all', {}, { preserveScroll: true });

// Each kind of notification gets its own icon, so a list scans by shape.
const kindIcons = { leave: 'calendar', executive: 'chartBar', hr: 'users', feedback: 'chat', payslip: 'database', pds: 'clipboard' };
const iconFor = (notification) => kindIcons[notification.kind] ?? 'bell';

const filter = ref('all');
const shown = computed(() => (filter.value === 'unread' ? props.notifications.filter((notification) => !notification.read) : props.notifications));

const order = ['Today', 'Yesterday', 'This week', 'Earlier'];
const groups = computed(() =>
    order
        .map((label) => ({ label, items: shown.value.filter((notification) => dayGroup(notification.created_at) === label) }))
        .filter((group) => group.items.length),
);
</script>

<template>
    <Head title="Notifications" />

    <PageHeader
        title="Notifications"
        description="Updates on your leave and anything waiting for your action."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Notifications' }]"
    >
        <template #actions>
            <Button v-if="unread" variant="secondary" @click="readAll"><Icon name="check" size="sm" />Mark all as read</Button>
            <Button variant="outline" href="/account/notifications"><Icon name="cog" size="sm" />Settings</Button>
        </template>
    </PageHeader>

    <div v-if="notifications.length" class="max-w-4xl space-y-6">
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter notifications">
            <button
                v-for="entry in [{ key: 'all', label: 'All', count: notifications.length }, { key: 'unread', label: 'Unread', count: unread }]"
                :key="entry.key"
                type="button"
                class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="filter === entry.key ? 'border-primary bg-primary font-medium text-white' : 'border-border bg-surface text-text-muted hover:border-primary/40 hover:text-text'"
                :aria-pressed="filter === entry.key"
                @click="filter = entry.key"
            >
                {{ entry.label }}
                <span class="rounded-full px-1.5 text-xs tabular-nums" :class="filter === entry.key ? 'bg-white/20' : 'bg-surface-muted'">{{ entry.count }}</span>
            </button>
        </div>

        <section v-for="group in groups" :key="group.label" :aria-label="group.label">
            <h2 class="mb-2 px-1 text-2xs font-semibold tracking-wide text-text-muted uppercase">{{ group.label }}</h2>
            <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface">
                <li v-for="notification in group.items" :key="notification.id">
                    <a
                        :href="`/notifications/${notification.id}`"
                        class="group flex items-start gap-4 p-4 transition-colors hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring"
                        :class="notification.read ? '' : 'bg-info-bg/40'"
                    >
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                            :class="notification.read ? 'bg-surface-muted text-text-muted' : 'bg-info-bg text-link'"
                            aria-hidden="true"
                        >
                            <Icon :name="iconFor(notification)" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm text-text" :class="notification.read ? '' : 'font-semibold'">
                                {{ notification.title }}<span v-if="!notification.read" class="sr-only"> (unread)</span>
                            </span>
                            <span v-if="notification.message" class="mt-0.5 block text-sm leading-relaxed text-text-muted">{{ notification.message }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1.5">
                            <time :datetime="notification.created_at" :title="formatDateTime(notification.created_at)" class="text-xs text-text-muted">{{ timeAgo(notification.created_at) }}</time>
                            <span v-if="!notification.read" class="h-2 w-2 rounded-full bg-primary" aria-hidden="true" />
                            <Icon v-else name="arrowRight" size="xs" class="text-text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                        </span>
                    </a>
                </li>
            </ul>
        </section>

        <Card v-if="!groups.length">
            <EmptyState icon="check" title="You're all caught up" description="No unread notifications.">
                <template #action>
                    <button type="button" class="text-sm font-medium text-link hover:underline" @click="filter = 'all'">Show all notifications</button>
                </template>
            </EmptyState>
        </Card>

        <p class="text-center text-xs text-text-muted">
            Want some by email too? <Link href="/account/notifications" class="text-link hover:underline">Choose in notification settings</Link>.
        </p>
    </div>

    <Card v-else>
        <EmptyState icon="bell" title="No notifications" description="Updates on your leave applications, and anything waiting for your action, will show here." />
    </Card>
</template>
