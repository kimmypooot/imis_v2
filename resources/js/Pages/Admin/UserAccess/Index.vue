<script setup>
import Badge from '../../../Components/UI/Badge.vue';
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
import UserAvatar from '../../../Components/UI/UserAvatar.vue';
import Icon from '../../../Components/UI/Icon.vue';
import AccessGridModal from '../../../Components/Admin/AccessGridModal.vue';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "User Access" page — one person's role in every system.
 * "Manage Access" opens the grid; its data loads on demand (?user=ID).
 */
const props = defineProps({
    users: { type: Array, required: true },
    hiddenInactive: { type: Number, default: 0 },
    access: { type: Object, default: null },
});

const columns = [
    { key: 'name', label: 'Name', value: (user) => `${user.name} ${user.username}` },
    { key: 'office', label: 'Division / FO', value: (user) => user.office_name ?? '' },
    { key: 'position', label: 'Position' },
    { key: 'systems_count', label: 'Systems', align: 'center' },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '9rem' },
];

const openId = ref(null);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});

const user = computed(() => props.users.find((candidate) => candidate.id === openId.value) ?? null);

const visit = (data, onFinish) =>
    router.get('/admin/user-access', data, { only: ['access'], preserveState: true, preserveScroll: true, replace: true, onFinish });

const open = (row) => {
    openId.value = row.id;
    errors.value = {};
    loading.value = true;
    visit({ user: row.id }, () => (loading.value = false));
};

const close = () => {
    openId.value = null;
    visit({});
};

onMounted(() => {
    const id = Number(new URLSearchParams(window.location.search).get('user'));
    if (id && props.users.some((candidate) => candidate.id === id)) open({ id });
});

const rows = computed(() => {
    if (!props.access || props.access.user_id !== openId.value) return null;

    return props.access.systems.map((system) => ({
        key: system.id,
        title: system.name,
        subtitle: [system.short_name, system.status === 'inactive' ? 'inactive system' : null].filter(Boolean).join(' · '),
        roleId: system.role_id,
        roles: system.roles,
        locked: system.locked,
    }));
});

const save = (changes) => {
    saving.value = true;
    errors.value = {};
    router.put(
        `/users/${openId.value}/system-access`,
        { access: changes.map((change) => ({ application_id: change.key, application_role_id: change.roleId })) },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (bag) => (errors.value = bag),
            onSuccess: () => visit({ user: openId.value }),
            onFinish: () => (saving.value = false),
        },
    );
};
</script>

<template>
    <Head title="User Access" />

    <PageHeader
        title="User Access Management"
        description="Each person's role in every system. Pick a person to set their access."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'User Access' }]"
    />

    <Card title="Users" icon="users">
        <p v-if="hiddenInactive" class="mb-3 text-xs text-text-muted">
            {{ hiddenInactive }} inactive account{{ hiddenInactive === 1 ? ' is' : 's are' }} not listed.
            <Link href="/users?status=inactive" class="font-medium text-link hover:underline">View inactive accounts</Link>
        </p>
        <DataTable :columns="columns" :rows="users" caption="Users and their system access" export-name="imis-user-access" :default-sort="{ key: 'name', direction: 'asc' }">
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <UserAvatar :name="row.name" :photo-url="row.profile_photo_url" size="sm" />
                    <div class="min-w-0">
                        <div class="truncate font-medium text-text">{{ row.name }}</div>
                        <div class="truncate text-xs text-text-muted">@{{ row.username }}</div>
                    </div>
                </div>
            </template>
            <template #cell-office="{ row }">
                <span :title="row.office_name">{{ row.office || '—' }}</span>
            </template>
            <template #cell-systems_count="{ row }">
                <Badge pill :tone="row.systems_count ? 'success' : 'neutral'">
                    {{ row.systems_count }}
                </Badge>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :status="row.status" />
            </template>
            <template #cell-actions="{ row }">
                <Button class="!px-3 !py-1 text-xs" @click="open(row)">
                    <Icon name="key" size="sm" />
                    Manage Access
                </Button>
            </template>
        </DataTable>
    </Card>

    <AccessGridModal
        :open="openId !== null"
        :title="user ? `Manage Access — ${user.name}` : 'Manage Access'"
        row-label="System"
        :rows="rows"
        :loading="loading"
        :saving="saving"
        :errors="errors"
        @save="save"
        @close="close"
    />
</template>
