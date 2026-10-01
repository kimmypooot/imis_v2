<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Button from '../../../Components/UI/Button.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Table from '../../../Components/UI/Table.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import HelperText from '../../../Components/Forms/HelperText.vue';
import FormGroup from '../../../Components/Forms/FormGroup.vue';
import Select from '../../../Components/Forms/Select.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import ConfirmDialog from '../../../Components/Feedback/ConfirmDialog.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    application: { type: Object, required: true },
    roles: { type: Array, required: true },
    grants: { type: Array, required: true },
    userOptions: { type: Array, required: true },
    statusOptions: { type: Array, required: true },
});

const { can, hasRole } = useAuthorization();
const currentUserId = computed(() => usePage().props.auth?.user?.id);

const base = `/admin/applications/${props.application.id}`;
const canManageRoles = computed(() => can('applications.update'));
const canManageAccess = computed(() => can('applications.manage_access'));

const activeRoleOptions = computed(() =>
    props.roles.filter((role) => role.status === 'active').map((role) => ({ value: role.id, label: role.name })),
);

// ── Roles ────────────────────────────────────────────────────────────
const emptyRole = { name: '', description: '', launch_url: '', sort_order: 0 };
const roleForm = useForm({ ...emptyRole });
const editingRoleId = ref(null);

const startEditRole = (role) => {
    editingRoleId.value = role.id;
    roleForm.clearErrors();
    roleForm.name = role.name;
    roleForm.description = role.description ?? '';
    roleForm.launch_url = role.launch_url ?? '';
    roleForm.sort_order = role.sort_order;
};

const resetRoleForm = () => {
    editingRoleId.value = null;
    roleForm.reset();
    roleForm.clearErrors();
};

const submitRole = () => {
    const options = { preserveScroll: true, onSuccess: resetRoleForm };

    if (editingRoleId.value) {
        roleForm.put(`${base}/roles/${editingRoleId.value}`, options);
    } else {
        roleForm.post(`${base}/roles`, options);
    }
};

const roleStatusTarget = ref(null);
const roleStatusForm = useForm({ status: '' });

const confirmRoleStatus = () => {
    const role = roleStatusTarget.value;
    roleStatusForm.status = role.status === 'active' ? 'inactive' : 'active';
    // Closed either way: a refusal (role still held) shows under the table.
    roleStatusForm.patch(`${base}/roles/${role.id}/status`, {
        preserveScroll: true,
        onFinish: () => (roleStatusTarget.value = null),
    });
};

// ── Access grants ────────────────────────────────────────────────────
const grantForm = useForm({ user_id: '', application_role_id: '' });

const grantAccess = () => {
    grantForm.post(`${base}/access`, { preserveScroll: true, onSuccess: () => grantForm.reset() });
};

const filter = ref('');
const visibleGrants = computed(() => {
    const term = filter.value.trim().toLowerCase();

    return term
        ? props.grants.filter((grant) =>
              [grant.user.name, grant.user.email, grant.role.name].some((value) => value.toLowerCase().includes(term)),
          )
        : props.grants;
});

// A non-super-admin can't change their own access (enforced server-side
// by UserPolicy::manageSystemAccess); this only avoids offering it.
const mayChange = (grant) =>
    canManageAccess.value && (grant.user.id !== currentUserId.value || hasRole('super-admin'));

const changeForm = useForm({ application_role_id: '' });
const changeRole = (grant, roleId) => {
    changeForm.application_role_id = roleId;
    changeForm.put(`${base}/access/${grant.id}`, { preserveScroll: true });
};

const revokeTarget = ref(null);
const revokeForm = useForm({});
const confirmRevoke = () => {
    revokeForm.delete(`${base}/access/${revokeTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (revokeTarget.value = null),
    });
};
</script>

<template>
    <Head :title="`Access — ${application.name}`" />

    <PageHeader
        :title="`Access — ${application.name}`"
        description="The roles that exist inside this system and who holds each. These are separate from IMIS roles — they record a person's role in this system and where they launch into."
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Systems / Applications', href: '/admin/applications' },
            { label: application.name },
        ]"
    >
        <template v-if="can('applications.update')" #actions>
            <Link :href="`${base}/edit`">
                <Button variant="secondary">Edit system</Button>
            </Link>
        </template>
    </PageHeader>

    <div class="mb-6 flex flex-wrap items-center gap-2 text-sm text-text-muted">
        <Badge :tone="application.status === 'active' ? 'success' : 'neutral'">{{ application.status }}</Badge>
        <Badge v-if="application.under_maintenance" tone="warning">Under maintenance</Badge>
        <Badge :tone="application.requires_access ? 'warning' : 'neutral'">
            {{ application.requires_access ? 'Restricted — only people with a role can launch it' : 'Open — anyone who can see it can launch it' }}
        </Badge>
    </div>

    <h2 class="mb-3 text-sm font-semibold text-text">Roles in this system</h2>

    <div class="mb-8 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <Table :empty="roles.length === 0">
                <template #empty>
                    <EmptyState
                        title="No roles defined"
                        description="Define at least one role (for example, User or Administrator) before granting access."
                    />
                </template>

                <thead>
                    <tr>
                        <th scope="col">Role</th>
                        <th scope="col">Launches into</th>
                        <th scope="col">People</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="role in roles" :key="role.id">
                        <td>
                            <div class="font-medium text-text">{{ role.name }}</div>
                            <div v-if="role.description" class="text-text-muted">{{ role.description }}</div>
                        </td>
                        <td class="max-w-48 truncate text-text-muted">{{ role.launch_url || 'System URL' }}</td>
                        <td class="text-text-muted">{{ role.access_grants_count }}</td>
                        <td>
                            <Badge :tone="role.status === 'active' ? 'success' : 'neutral'">{{ role.status }}</Badge>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <template v-if="canManageRoles">
                                <button
                                    type="button"
                                    class="font-medium text-primary hover:text-primary-hover"
                                    @click="startEditRole(role)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="ml-3 font-medium text-primary hover:text-primary-hover"
                                    @click="roleStatusTarget = role"
                                >
                                    {{ role.status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </Table>
            <InputError :message="roleStatusForm.errors.status" />
        </div>

        <Card v-if="canManageRoles" :title="editingRoleId ? 'Edit role' : 'Add role'">
            <form class="space-y-4" @submit.prevent="submitRole">
                <div>
                    <TextInput
                        id="role-name"
                        v-model="roleForm.name"
                        label="Name"
                        placeholder="e.g. Administrator"
                        required
                        :invalid="!!roleForm.errors.name"
                    />
                    <InputError :message="roleForm.errors.name" />
                </div>
                <div>
                    <TextInput
                        id="role-description"
                        v-model="roleForm.description"
                        label="Description"
                        :invalid="!!roleForm.errors.description"
                    />
                    <InputError :message="roleForm.errors.description" />
                </div>
                <div>
                    <TextInput
                        id="role-launch_url"
                        v-model="roleForm.launch_url"
                        type="url"
                        label="Launch URL"
                        :invalid="!!roleForm.errors.launch_url"
                    />
                    <HelperText v-if="!roleForm.errors.launch_url" id="role-launch_url-helper">
                        Optional. Where people with this role land, e.g. the system's admin page. Leave blank to use
                        the system URL.
                    </HelperText>
                    <InputError :message="roleForm.errors.launch_url" />
                </div>
                <div>
                    <TextInput
                        id="role-sort_order"
                        v-model="roleForm.sort_order"
                        type="number"
                        label="Sort order"
                        required
                        :invalid="!!roleForm.errors.sort_order"
                    />
                    <InputError :message="roleForm.errors.sort_order" />
                </div>
                <div class="flex gap-2">
                    <Button type="submit" :loading="roleForm.processing">
                        {{ editingRoleId ? 'Save role' : 'Add role' }}
                    </Button>
                    <Button v-if="editingRoleId" variant="secondary" @click="resetRoleForm">Cancel</Button>
                </div>
            </form>
        </Card>
    </div>

    <h2 class="mb-3 text-sm font-semibold text-text">People with access</h2>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
            <div v-if="grants.length > 5" class="max-w-xs">
                <TextInput id="grant-filter" v-model="filter" label="Filter" placeholder="Name, email, or role" />
            </div>

            <InputError :message="changeForm.errors.application_role_id" />

            <Table :empty="visibleGrants.length === 0">
                <template #empty>
                    <EmptyState
                        :title="grants.length ? 'No matches' : 'Nobody has access yet'"
                        :description="grants.length ? 'Try a different filter.' : 'Grant access using the form.'"
                    />
                </template>

                <thead>
                    <tr>
                        <th scope="col">Person</th>
                        <th scope="col">Role</th>
                        <th scope="col">Last set by</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="grant in visibleGrants" :key="grant.id">
                        <td>
                            <Link :href="`/users/${grant.user.id}`" class="font-medium text-text hover:text-primary">
                                {{ grant.user.name }}
                            </Link>
                            <div class="text-text-muted">{{ grant.user.email }}</div>
                            <Badge v-if="grant.user.status !== 'active'" tone="neutral">{{ grant.user.status }}</Badge>
                        </td>
                        <td class="w-48">
                            <Select
                                v-if="mayChange(grant)"
                                :id="`grant-role-${grant.id}`"
                                :model-value="grant.role.id"
                                :options="activeRoleOptions"
                                :disabled="changeForm.processing"
                                :aria-label="`Role of ${grant.user.name}`"
                                @update:model-value="(roleId) => changeRole(grant, roleId)"
                            />
                            <Badge v-else tone="info">{{ grant.role.name }}</Badge>
                        </td>
                        <td class="text-text-muted">{{ grant.granted_by ?? '—' }}</td>
                        <td class="text-right">
                            <Button v-if="mayChange(grant)" variant="danger" @click="revokeTarget = grant">Revoke</Button>
                        </td>
                    </tr>
                </tbody>
            </Table>
        </div>

        <Card v-if="canManageAccess" title="Grant access">
            <Alert v-if="activeRoleOptions.length === 0" tone="info">
                Add an active role to this system before granting access.
            </Alert>
            <p v-else-if="userOptions.length === 0" class="text-sm text-text-muted">
                Every active account already has access to this system.
            </p>
            <form v-else class="space-y-4" @submit.prevent="grantAccess">
                <FormGroup id="grant-user_id" label="Person" required :error="grantForm.errors.user_id">
                    <Select
                        id="grant-user_id"
                        v-model="grantForm.user_id"
                        placeholder="Select a person"
                        :options="userOptions.map((option) => ({ value: option.id, label: `${option.name} (${option.email})` }))"
                    />
                </FormGroup>
                <FormGroup id="grant-role" label="Role" required :error="grantForm.errors.application_role_id">
                    <Select
                        id="grant-role"
                        v-model="grantForm.application_role_id"
                        placeholder="Select a role"
                        :options="activeRoleOptions"
                    />
                </FormGroup>
                <Button type="submit" :loading="grantForm.processing">Grant access</Button>
            </form>
        </Card>
    </div>

    <ConfirmDialog
        :open="!!roleStatusTarget"
        :title="roleStatusTarget?.status === 'active' ? 'Deactivate this role?' : 'Activate this role?'"
        :danger="roleStatusTarget?.status === 'active'"
        :confirm-label="roleStatusTarget?.status === 'active' ? 'Deactivate' : 'Activate'"
        :processing="roleStatusForm.processing"
        @confirm="confirmRoleStatus"
        @cancel="roleStatusTarget = null"
    >
        {{
            roleStatusTarget?.status === 'active'
                ? `${roleStatusTarget?.name} will no longer be offered when granting access. A role still held by someone can't be deactivated.`
                : `${roleStatusTarget?.name} can be granted again.`
        }}
    </ConfirmDialog>

    <ConfirmDialog
        :open="!!revokeTarget"
        title="Revoke access?"
        danger
        confirm-label="Revoke"
        :processing="revokeForm.processing"
        @confirm="confirmRevoke"
        @cancel="revokeTarget = null"
    >
        {{ revokeTarget?.user.name }} will no longer hold the {{ revokeTarget?.role.name }} role in {{ application.name }}.
    </ConfirmDialog>
</template>
