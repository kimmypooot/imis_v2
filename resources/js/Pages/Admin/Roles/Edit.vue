<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import Checkbox from '../../../Components/Forms/Checkbox.vue';
import FormGroup from '../../../Components/Forms/FormGroup.vue';
import Select from '../../../Components/Forms/Select.vue';
import Textarea from '../../../Components/Forms/Textarea.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import { groupPermissionsByResource } from '../../../Utils/permissions';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    role: { type: Object, required: true },
    permissions: { type: Array, required: true },
});

const isSuperAdmin = props.role.name === 'super-admin';

const form = useForm({
    name: props.role.name,
    display_name: props.role.display_name,
    description: props.role.description ?? '',
    status: props.role.status,
});

const submit = () => {
    form.put(`/roles/${props.role.id}`);
};

const assignedPermissionIds = props.role.permissions.map((permission) => permission.id);

const permissionsForm = useForm({
    permission_ids: [...assignedPermissionIds],
});

const togglePermission = (id, checked) => {
    permissionsForm.permission_ids = checked
        ? [...permissionsForm.permission_ids, id]
        : permissionsForm.permission_ids.filter((permissionId) => permissionId !== id);
};

const submitPermissions = () => {
    permissionsForm.put(`/roles/${props.role.id}/permissions`);
};

const search = ref('');

const filteredPermissions = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return props.permissions;
    }

    return props.permissions.filter(
        (permission) =>
            permission.name.toLowerCase().includes(term) || permission.display_name.toLowerCase().includes(term),
    );
});

const groups = computed(() => groupPermissionsByResource(filteredPermissions.value));
</script>

<template>
    <Head :title="`Edit role — ${role.display_name}`" />

    <PageHeader
        :title="`Edit role — ${role.display_name}`"
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Roles', href: '/roles' }, { label: role.display_name }]"
    />

    <Alert v-if="isSuperAdmin" tone="warning" title="System-critical role" class="mb-6">
        This role's name and active status are locked — the system's authorization bypass depends on the exact
        name "super-admin" staying active. Display name, description, and its permission set can still be
        changed.
    </Alert>

    <div class="grid gap-6 lg:grid-cols-2">
        <Card title="Details">
            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <TextInput
                        id="name"
                        v-model="form.name"
                        label="Name (machine-readable)"
                        required
                        :disabled="isSuperAdmin"
                        :invalid="!!form.errors.name"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div>
                    <TextInput
                        id="display_name"
                        v-model="form.display_name"
                        label="Display name"
                        required
                        :invalid="!!form.errors.display_name"
                    />
                    <InputError :message="form.errors.display_name" />
                </div>

                <FormGroup id="description" label="Description" :error="form.errors.description">
                    <Textarea id="description" v-model="form.description" :invalid="!!form.errors.description" />
                </FormGroup>

                <FormGroup id="status" label="Status" required :error="form.errors.status">
                    <Select
                        id="status"
                        v-model="form.status"
                        :disabled="isSuperAdmin"
                        :options="[
                            { value: 'active', label: 'Active' },
                            { value: 'inactive', label: 'Inactive' },
                        ]"
                        :invalid="!!form.errors.status"
                    />
                </FormGroup>

                <Button type="submit" :loading="form.processing">Save role</Button>
            </form>
        </Card>

        <Card title="Permissions">
            <form class="space-y-4" @submit.prevent="submitPermissions">
                <TextInput id="permission-search" v-model="search" label="Search permissions" placeholder="e.g. users, roles.update" />

                <InputError :message="permissionsForm.errors.permission_ids" />

                <div class="max-h-96 space-y-4 overflow-y-auto">
                    <div v-for="group in groups" :key="group.group">
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-text-muted">
                            {{ group.group }}
                        </h3>
                        <div class="space-y-2">
                            <Checkbox
                                v-for="permission in group.items"
                                :key="permission.id"
                                :id="`permission-${permission.id}`"
                                :label="permission.display_name"
                                :model-value="permissionsForm.permission_ids.includes(permission.id)"
                                @update:model-value="(checked) => togglePermission(permission.id, checked)"
                            />
                        </div>
                    </div>
                    <p v-if="groups.length === 0" class="text-sm text-text-muted">No permissions match your search.</p>
                </div>

                <Button type="submit" :loading="permissionsForm.processing">Save permissions</Button>
            </form>
        </Card>
    </div>
</template>
