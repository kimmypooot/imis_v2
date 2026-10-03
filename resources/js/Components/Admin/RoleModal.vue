<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Alert from '../Feedback/Alert.vue';
import Button from '../UI/Button.vue';
import Tabs from '../UI/Tabs.vue';
import TextInput from '../Forms/TextInput.vue';
import Textarea from '../Forms/Textarea.vue';
import InputError from '../Forms/InputError.vue';
import FormGroup from '../Forms/FormGroup.vue';
import Select from '../Forms/Select.vue';
import Checkbox from '../Forms/Checkbox.vue';
import { useAuthorization } from '../../Composables/useAuthorization';
import { groupPermissionsByResource } from '../../Utils/permissions';

/**
 * Add / edit an IMIS role (platform permissions, not a role inside a
 * system). Tabs: Details · Permissions (editing only). The super-admin
 * role's name and status are locked — every Gate check depends on them.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    role: { type: Object, default: null },
    permissions: { type: Array, required: true },
});

const emit = defineEmits(['close']);

const { can } = useAuthorization();
const isNew = computed(() => props.role === null);
const isSuperAdmin = computed(() => props.role?.name === 'super-admin');

const tab = ref('details');
const tabs = computed(() => [
    { key: 'details', label: 'Details', icon: 'user' },
    ...(isNew.value ? [] : [{ key: 'permissions', label: 'Permissions', icon: 'key', badge: props.role.permission_ids.length || null }]),
]);

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const form = useForm({ name: '', display_name: '', description: '', status: 'active' });
const permissionsForm = useForm({ permission_ids: [] });
const search = ref('');

watch(
    () => [props.open, props.role?.id],
    ([open]) => {
        if (!open) return;
        form.defaults({
            name: props.role?.name ?? '',
            display_name: props.role?.display_name ?? '',
            description: props.role?.description ?? '',
            status: props.role?.status ?? 'active',
        });
        form.reset();
        form.clearErrors();
        permissionsForm.defaults({ permission_ids: [...(props.role?.permission_ids ?? [])] });
        permissionsForm.reset();
        permissionsForm.clearErrors();
        search.value = '';
        tab.value = 'details';
    },
);

const close = () => emit('close');

const saveDetails = () => {
    const options = { preserveScroll: true, onSuccess: () => (isNew.value ? close() : form.defaults()) };
    form.transform((data) => ({ ...data, description: data.description || null }));

    if (isNew.value) form.post('/roles', options);
    else form.put(`/roles/${props.role.id}`, options);
};

const savePermissions = () => {
    permissionsForm.put(`/roles/${props.role.id}/permissions`, { preserveScroll: true, onSuccess: () => permissionsForm.defaults() });
};

const groups = computed(() => {
    const term = search.value.trim().toLowerCase();
    const list = term
        ? props.permissions.filter((permission) => `${permission.name} ${permission.display_name}`.toLowerCase().includes(term))
        : props.permissions;

    return groupPermissionsByResource(list);
});

const toggle = (id, checked) => {
    permissionsForm.permission_ids = checked
        ? [...permissionsForm.permission_ids, id]
        : permissionsForm.permission_ids.filter((permissionId) => permissionId !== id);
};

const groupState = (group) => {
    const selected = group.items.filter((item) => permissionsForm.permission_ids.includes(item.id)).length;

    return selected === 0 ? 'none' : selected === group.items.length ? 'all' : 'some';
};

const toggleGroup = (group) => {
    const ids = group.items.map((item) => item.id);
    permissionsForm.permission_ids =
        groupState(group) === 'all'
            ? permissionsForm.permission_ids.filter((id) => !ids.includes(id))
            : [...new Set([...permissionsForm.permission_ids, ...ids])];
};

const canSaveDetails = computed(() => (isNew.value ? can('roles.create') : can('roles.update')));
</script>

<template>
    <Modal
        :open="open"
        :title="isNew ? 'Add New Role' : `Edit Role — ${role.display_name}`"
        :tone="isNew ? 'success' : 'primary'"
        :icon="isNew ? 'plus' : 'pencil'"
        size="xl"
        @close="close"
    >
        <Tabs v-model="tab" :tabs="tabs">
            <template #details>
                <Alert v-if="isSuperAdmin" tone="warning" class="mb-4">
                    The Super Administrator role's name and status are locked: every authorization check depends on
                    them. Its display name, description, and permissions can still change.
                </Alert>
                <form id="role-form" @submit.prevent="saveDetails">
                    <fieldset :disabled="!canSaveDetails" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <TextInput id="role-name" v-model="form.name" label="Machine name" required :disabled="isSuperAdmin" :invalid="!!form.errors.name" />
                            <p class="mt-1 text-xs text-text-muted">Lowercase letters, numbers, dashes — e.g. <code>hr-officer</code>.</p>
                            <InputError :message="form.errors.name" />
                        </div>
                        <div>
                            <TextInput id="role-display" v-model="form.display_name" label="Display name" required :invalid="!!form.errors.display_name" />
                            <InputError :message="form.errors.display_name" />
                        </div>
                        <FormGroup id="role-status" label="Status" required :error="form.errors.status">
                            <Select id="role-status" v-model="form.status" :options="statusOptions" :disabled="isSuperAdmin" />
                        </FormGroup>
                        <FormGroup id="role-description" label="Description" :error="form.errors.description" class="sm:col-span-2">
                            <Textarea id="role-description" v-model="form.description" :invalid="!!form.errors.description" />
                        </FormGroup>
                    </fieldset>
                </form>
            </template>

            <template v-if="!isNew" #permissions>
                <form id="permissions-form" class="space-y-4" @submit.prevent="savePermissions">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div class="w-full sm:w-72">
                            <TextInput id="permission-search" v-model="search" label="Search permissions" />
                        </div>
                        <p class="text-sm text-text-muted">{{ permissionsForm.permission_ids.length }} of {{ permissions.length }} selected</p>
                    </div>
                    <InputError :message="permissionsForm.errors.permission_ids" />
                    <fieldset :disabled="!can('permissions.manage')" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <div v-for="group in groups" :key="group.group" class="rounded-md border border-border p-3">
                            <label class="mb-2 flex items-center gap-2 text-sm font-semibold text-text">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-border text-primary focus:ring-focus-ring"
                                    :checked="groupState(group) === 'all'"
                                    :indeterminate="groupState(group) === 'some'"
                                    @change="toggleGroup(group)"
                                />
                                {{ group.group }}
                            </label>
                            <div class="space-y-1.5 pl-1">
                                <Checkbox
                                    v-for="permission in group.items"
                                    :id="`permission-${permission.id}`"
                                    :key="permission.id"
                                    :label="permission.display_name"
                                    :model-value="permissionsForm.permission_ids.includes(permission.id)"
                                    @update:model-value="(checked) => toggle(permission.id, checked)"
                                />
                            </div>
                        </div>
                    </fieldset>
                    <p v-if="!groups.length" class="text-sm text-text-muted">No permissions match your search.</p>
                </form>
            </template>
        </Tabs>

        <template #footer>
            <Button variant="secondary" @click="close">Close</Button>
            <Button
                v-if="tab === 'details' && canSaveDetails"
                type="submit"
                form="role-form"
                :variant="isNew ? 'success' : 'primary'"
                :loading="form.processing"
                :disabled="!isNew && !form.isDirty"
            >
                {{ isNew ? 'Add Role' : 'Save changes' }}
            </Button>
            <Button
                v-if="tab === 'permissions' && can('permissions.manage')"
                type="submit"
                form="permissions-form"
                :loading="permissionsForm.processing"
                :disabled="!permissionsForm.isDirty"
            >
                Save permissions
            </Button>
        </template>
    </Modal>
</template>
