<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Button from '../../../Components/UI/Button.vue';
import Checkbox from '../../../Components/Forms/Checkbox.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import FormGroup from '../../../Components/Forms/FormGroup.vue';
import Select from '../../../Components/Forms/Select.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    user: { type: Object, required: true },
    roles: { type: Array, required: true },
    assignedRoleIds: { type: Array, required: true },
    systemAccess: { type: Array, required: true },
    canManageSystemAccess: { type: Boolean, required: true },
});

const { can } = useAuthorization();

const form = useForm({
    role_ids: [...props.assignedRoleIds],
});

const toggleRole = (id, checked) => {
    form.role_ids = checked
        ? [...form.role_ids, id]
        : form.role_ids.filter((roleId) => roleId !== id);
};

const submit = () => {
    form.put(`/users/${props.user.id}/roles`);
};

// One role per system; '' ("No access") revokes.
const accessForm = useForm({
    access: props.systemAccess.map((system) => ({ application_id: system.id, application_role_id: system.role_id ?? '' })),
});

const submitAccess = () => {
    accessForm
        .transform((data) => ({
            access: data.access.map((entry) => ({ ...entry, application_role_id: entry.application_role_id || null })),
        }))
        .put(`/users/${props.user.id}/system-access`, { preserveScroll: true });
};

const roleName = (system) => system.roles.find((role) => role.id === system.role_id)?.name;

const isGrantingSuperAdmin = computed(() => {
    const superAdmin = props.roles.find((role) => role.name === 'super-admin');

    return superAdmin && form.role_ids.includes(superAdmin.id) && !props.assignedRoleIds.includes(superAdmin.id);
});
</script>

<template>
    <Head :title="`User — ${user.name}`" />

    <PageHeader
        :title="user.name"
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Users', href: '/users' }, { label: user.name }]"
    >
        <template #actions>
            <Link href="/users">
                <Button variant="secondary">Back to Users</Button>
            </Link>
        </template>
    </PageHeader>

    <div class="mb-4 flex items-center gap-3">
        <p class="text-sm text-text-muted">@{{ user.username }} · {{ user.email }}</p>
        <Badge :tone="user.status === 'active' ? 'success' : 'neutral'">{{ user.status }}</Badge>
    </div>

    <Card title="Assigned roles" class="max-w-md">
        <form class="space-y-3" @submit.prevent="submit">
            <Checkbox
                v-for="role in roles"
                :key="role.id"
                :id="`role-${role.id}`"
                :label="role.display_name"
                :model-value="form.role_ids.includes(role.id)"
                @update:model-value="(checked) => toggleRole(role.id, checked)"
            />
            <p v-if="roles.length === 0" class="text-sm text-text-muted">No roles available to assign.</p>

            <Alert v-if="isGrantingSuperAdmin" tone="warning">
                Granting Super Administrator access gives unrestricted control over the entire system. This cannot
                be undone by anyone except another Super Administrator.
            </Alert>

            <InputError :message="form.errors.role_ids" />
            <Button type="submit" :loading="form.processing">Save roles</Button>
        </form>
    </Card>

    <Card
        title="System access"
        description="This person's role inside each CSC RO VIII system. Separate from IMIS roles above — it controls which systems they can launch and where they land."
        class="mt-6 max-w-2xl"
    >
        <p v-if="systemAccess.length === 0" class="text-sm text-text-muted">
            No system has roles defined yet. Define them from a system's Roles &amp; access page.
        </p>

        <form v-else-if="canManageSystemAccess" class="space-y-4" @submit.prevent="submitAccess">
            <FormGroup
                v-for="(system, index) in systemAccess"
                :id="`system-${system.id}`"
                :key="system.id"
                :label="system.short_name ? `${system.name} (${system.short_name})` : system.name"
                :error="accessForm.errors[`access.${index}.application_role_id`]"
                :helper-text="system.via_designation ? 'Given by their Division Chief / Field Director designation. Changing it here makes it a regular grant.' : null"
            >
                <Select
                    :id="`system-${system.id}`"
                    v-model="accessForm.access[index].application_role_id"
                    :options="[
                        { value: '', label: 'No access' },
                        ...system.roles.map((role) => ({ value: role.id, label: role.name })),
                    ]"
                />
            </FormGroup>
            <InputError :message="accessForm.errors.access" />
            <Button type="submit" :loading="accessForm.processing">Save system access</Button>
        </form>

        <ul v-else class="divide-y divide-border text-sm">
            <li v-for="system in systemAccess" :key="system.id" class="flex items-center justify-between py-2">
                <span class="text-text">{{ system.name }}</span>
                <Badge v-if="system.role_id" tone="info" :title="system.via_designation ? 'Given by their office designation' : undefined">
                    {{ roleName(system) }}<template v-if="system.via_designation"> · by designation</template>
                </Badge>
                <span v-else class="text-text-muted">No access</span>
            </li>
        </ul>
    </Card>
</template>
