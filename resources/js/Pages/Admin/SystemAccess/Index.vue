<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
import Icon from '../../../Components/UI/Icon.vue';
import AccessGridModal from '../../../Components/Admin/AccessGridModal.vue';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Roles and Permissions" page — who holds which role in each
 * system. "Manage Access" opens the grid for one system; its data loads on
 * demand (?system=ID), so the link can be bookmarked or opened straight
 * from Manage Systems.
 */
const props = defineProps({
    systems: { type: Array, required: true },
    access: { type: Object, default: null },
});

const columns = [
    { key: 'name', label: 'System name' },
    { key: 'description', label: 'Description' },
    { key: 'short_name', label: 'Code', align: 'center' },
    { key: 'roles_count', label: 'Roles', align: 'center' },
    { key: 'users_count', label: 'Users', align: 'center' },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '9rem' },
];

const openId = ref(null);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});

const system = computed(() => props.systems.find((candidate) => candidate.id === openId.value) ?? null);

const visit = (data, onFinish) =>
    router.get('/admin/system-access', data, {
        only: ['access'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish,
    });

const open = (row) => {
    openId.value = row.id;
    errors.value = {};
    loading.value = true;
    visit({ system: row.id }, () => (loading.value = false));
};

const close = () => {
    openId.value = null;
    visit({});
};

// Arriving with ?system=ID (e.g. from Manage Systems) opens that system.
onMounted(() => {
    const id = Number(new URLSearchParams(window.location.search).get('system'));
    if (id && props.systems.some((candidate) => candidate.id === id)) open({ id });
});

const rows = computed(() => {
    if (!props.access || props.access.system_id !== openId.value) return null;

    return props.access.users.map((user) => ({
        key: user.id,
        title: user.name,
        subtitle: [user.position, user.office].filter(Boolean).join(' · ') || `@${user.username}`,
        avatar: true,
        group: user.unit ?? 'No unit assigned',
        groupRank: user.unit_rank,
        grade: user.salary_grade,
        photoUrl: user.profile_photo_url,
        roleId: user.role_id,
        roles: props.access.roles,
        locked: user.locked,
    }));
});

const save = (changes) => {
    saving.value = true;
    errors.value = {};
    router.put(
        `/admin/system-access/${openId.value}`,
        { entries: changes.map((change) => ({ user_id: change.key, application_role_id: change.roleId })) },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (bag) => (errors.value = bag),
            onSuccess: () => visit({ system: openId.value }),
            onFinish: () => (saving.value = false),
        },
    );
};
</script>

<template>
    <Head title="System Access" />

    <PageHeader
        title="System Access Management"
        description="Who holds which role inside each system. Pick a system to assign roles to people."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'System Access' }]"
    />

    <Card title="Systems" icon="key">
        <DataTable :columns="columns" :rows="systems" caption="Systems and their access" export-name="imis-system-access">
            <template #cell-name="{ row }">
                <span class="font-medium text-text">{{ row.name }}</span>
            </template>
            <template #cell-description="{ row }">
                <p class="line-clamp-2 max-w-sm text-text-muted">{{ row.description || '—' }}</p>
            </template>
            <template #cell-short_name="{ row }">
                <span v-if="row.short_name" class="rounded bg-surface-muted px-1.5 py-0.5 font-mono text-xs">{{ row.short_name }}</span>
                <span v-else class="text-text-muted">—</span>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :status="row.under_maintenance ? 'maintenance' : row.status" />
            </template>
            <template #cell-actions="{ row }">
                <Button variant="primary" class="!px-3 !py-1 text-xs" :disabled="row.roles_count === 0" :title="row.roles_count === 0 ? 'Add roles to this system first (Manage Systems)' : undefined" @click="open(row)">
                    <Icon name="key" size="sm" />
                    Manage Access
                </Button>
            </template>
        </DataTable>
    </Card>

    <AccessGridModal
        :open="openId !== null"
        :title="system ? `Manage Access — ${system.name}` : 'Manage Access'"
        row-label="Name"
        :rows="rows"
        :loading="loading"
        :saving="saving"
        :errors="errors"
        :hidden-inactive="access?.hidden_inactive ?? 0"
        @save="save"
        @close="close"
    />
</template>
