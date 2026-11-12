<script setup>
import { Head, router, usePoll } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import Icon from '../../../Components/UI/Icon.vue';
import UserAvatar from '../../../Components/UI/UserAvatar.vue';
import { confirm } from '../../../Composables/useDialog';
import { formatDateTime, timeAgo } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Active Sessions" page: who is signed in right now, with a
 * "Force logout" per session (it also blocks a silent "remember me"
 * sign-back-in).
 */
const props = defineProps({
    sessions: { type: Array, required: true },
    canRevoke: { type: Boolean, required: true },
});

const columns = [
    { key: 'user', label: 'User', value: (row) => `${row.user?.name ?? ''} ${row.user?.username ?? ''}` },
    { key: 'roles', label: 'Role' },
    { key: 'ip_address', label: 'IP address' },
    // ISO strings sort correctly; the cells below show them readably.
    { key: 'logged_in_at', label: 'Login time', value: (row) => row.logged_in_at ?? '' },
    { key: 'last_activity', label: 'Last activity', value: (row) => row.last_activity },
    { key: 'browser', label: 'Browser', value: (row) => row.user_agent ?? '' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '13rem' },
];

const refresh = () => router.reload({ only: ['sessions'] });

// Keeps the list current; Inertia pauses polling while the tab is hidden.
usePoll(30000, { only: ['sessions'] });

// Someone with more than one sign-in can be signed out of all the others at once.
const countFor = (session) => props.sessions.filter((other) => other.user?.id === session.user?.id).length;
const multiple = (session) => countFor(session) > 1;

const forceLogout = async (session) => {
    const confirmed = await confirm({
        title: 'Force logout?',
        text: `${session.user?.name} will be signed out of this browser immediately and must sign in again.`,
        tone: 'danger',
        confirmText: 'Force logout',
    });

    if (confirmed) router.delete(`/admin/sessions/${session.key}`, { preserveScroll: true });
};

const forceLogoutAll = async (session) => {
    const confirmed = await confirm({
        title: 'Sign out everywhere?',
        text: `${session.user?.name} will be signed out of every browser except the one you are using now.`,
        tone: 'danger',
        confirmText: 'Sign out all',
    });

    if (confirmed) router.delete(`/admin/sessions/user/${session.user.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Active Sessions" />

    <PageHeader title="Active Sessions" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Active Sessions' }]" />

    <Card :title="`Signed in now (${sessions.length})`" icon="desktop">
        <template #actions>
            <Button variant="secondary" @click="refresh">
                <Icon name="refresh" size="sm" />
                Refresh
            </Button>
        </template>

        <DataTable :columns="columns" :rows="props.sessions" row-key="key" caption="Active sessions" export-name="imis-active-sessions" :default-sort="{ key: 'last_activity', direction: 'desc' }">
            <template #cell-user="{ row }">
                <div class="flex items-center gap-3">
                    <UserAvatar :name="row.user?.name ?? '?'" :photo-url="row.profile_photo_url" size="sm" />
                    <div class="min-w-0">
                        <div class="truncate font-medium text-text">
                            {{ row.user?.name }}
                            <span v-if="row.is_current" class="ml-1 rounded-full bg-success-bg px-2 py-0.5 text-xs text-success-fg">You</span>
                        </div>
                        <div class="truncate text-xs text-text-muted">@{{ row.user?.username }}</div>
                    </div>
                </div>
            </template>
            <template #cell-browser="{ row }">
                <span :title="row.user_agent">{{ row.browser }}</span>
            </template>
            <template #cell-logged_in_at="{ row }">
                <span v-if="row.logged_in_at">{{ formatDateTime(row.logged_in_at) }}</span>
            </template>
            <template #cell-last_activity="{ row }">
                <div :title="formatDateTime(row.last_activity)">{{ timeAgo(row.last_activity) }}</div>
                <span
                    class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="row.is_active ? 'bg-success-bg text-success-fg' : 'bg-surface-muted text-text-muted'"
                >{{ row.is_active ? 'Active' : 'Idle' }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="canRevoke && row.can_end" class="flex flex-col items-center gap-1">
                    <Button v-if="!row.is_current" variant="danger" class="!px-3 !py-1 text-xs" @click="forceLogout(row)">
                        <Icon name="power" size="sm" />
                        Force logout
                    </Button>
                    <button
                        v-if="multiple(row)"
                        type="button"
                        class="text-xs text-danger-fg hover:underline"
                        @click="forceLogoutAll(row)"
                    >
                        Sign out all ({{ countFor(row) }})
                    </button>
                </div>
            </template>
        </DataTable>
    </Card>
</template>
