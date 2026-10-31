<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Alert from '../Feedback/Alert.vue';
import Button from '../UI/Button.vue';
import Tabs from '../UI/Tabs.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import TextInput from '../Forms/TextInput.vue';
import InputError from '../Forms/InputError.vue';
import CheckboxGroup from '../Forms/CheckboxGroup.vue';
import PhotoPicker from '../Forms/PhotoPicker.vue';
import PasswordInput from '../Forms/PasswordInput.vue';
import PasswordRequirements from '../Forms/PasswordRequirements.vue';
import Checkbox from '../Forms/Checkbox.vue';
import UserProfileFields from './UserProfileFields.vue';
import { confirm } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The legacy "Edit User" modal (blue header) with its Profile / Account /
 * Security tabs. Each part saves through its own endpoint, exactly as the
 * server authorizes them separately (a protected Super Administrator, for
 * example, can't be edited by a non-super-admin at all — `user.can`).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    user: { type: Object, default: null },
    roleOptions: { type: Array, required: true },
    officeOptions: { type: Array, required: true },
    designationOptions: { type: Array, default: () => [] },
    honorificOptions: { type: Array, default: () => [] },
    plantillaItemOptions: { type: Array, default: () => [] },
    canAppoint: { type: Boolean, default: false },
    passwordRequirements: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const tab = ref('profile');
const tabs = [
    { key: 'profile', label: 'Profile', icon: 'user' },
    { key: 'account', label: 'Account', icon: 'key' },
    { key: 'security', label: 'Security', icon: 'lock' },
];

const profileFields = (user) => ({
    honorific: user?.honorific ?? '',
    first_name: user?.first_name ?? '',
    middle_name: user?.middle_name ?? '',
    name_extension: user?.name_extension ?? '',
    last_name: user?.last_name ?? '',
    email: user?.email ?? '',
    position: user?.position ?? '',
    plantilla_item_id: user?.plantilla_item_id ?? '',
    sex: user?.sex ?? '',
    birthday: user?.birthday ?? '',
    organizational_unit_id: user?.organizational_unit_id ?? '',
    office_designation: user?.office_designation ?? '',
    username: user?.username ?? '',
});

const form = useForm(profileFields(props.user));
const rolesForm = useForm({ role_ids: [] });
// Super Administrator override (user.can.set_password).
const passwordForm = useForm({ password: '', password_confirmation: '', must_change_password: true, admin_password: '' });

// Re-seed only when a different user is opened, so a save that reloads
// the list doesn't wipe what's being typed elsewhere.
watch(
    () => props.user?.id,
    () => {
        if (!props.user) return;
        form.defaults(profileFields(props.user));
        form.reset();
        form.clearErrors();
        rolesForm.defaults({ role_ids: props.user.roles.map((role) => role.id) });
        rolesForm.reset();
        rolesForm.clearErrors();
        passwordForm.reset();
        passwordForm.clearErrors();
        tab.value = 'profile';
    },
    { immediate: true },
);

const canEdit = computed(() => props.user?.can.update);

const saveProfile = () => {
    form.transform((data) => ({
        ...data,
        organizational_unit_id: data.organizational_unit_id || null,
        plantilla_item_id: data.plantilla_item_id || null,
        office_designation: data.office_designation || null,
        sex: data.sex || null,
        birthday: data.birthday || null,
    })).put(`/users/${props.user.id}`, {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
        // The username lives on the Account tab and the rest on Profile, but they
        // save together: show the tab holding the error so it is not missed.
        onError: (errors) => {
            tab.value = Object.keys(errors).some((field) => field !== 'username') ? 'profile' : 'account';
        },
    });
};

const saveRoles = () => {
    rolesForm.put(`/users/${props.user.id}/roles`, { preserveScroll: true, onSuccess: () => rolesForm.defaults() });
};

const superAdminId = computed(() => props.roleOptions.find((role) => role.name === 'super-admin')?.id);
const grantingSuperAdmin = computed(
    () => superAdminId.value && rolesForm.role_ids.includes(superAdminId.value) && !props.user.roles.some((role) => role.id === superAdminId.value),
);

const photoError = ref(null);
const uploadPhoto = (file) => {
    photoError.value = null;
    router.post(`/users/${props.user.id}/photo`, { profile_photo: file }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => (photoError.value = errors.profile_photo),
    });
};

const removePhoto = async () => {
    if (await confirm({ title: 'Remove profile photo?', text: `${props.user.name} will show their initials instead.`, tone: 'danger', confirmText: 'Remove' })) {
        router.delete(`/users/${props.user.id}/photo`, { preserveScroll: true });
    }
};

const toggleStatus = async () => {
    const deactivating = props.user.status === 'active';
    const confirmed = await confirm({
        title: deactivating ? 'Deactivate this account?' : 'Activate this account?',
        text: deactivating
            ? `${props.user.name} will no longer be able to sign in. Nothing is deleted.`
            : `${props.user.name} will be able to sign in again.`,
        tone: deactivating ? 'danger' : 'primary',
        confirmText: deactivating ? 'Deactivate' : 'Activate',
    });

    if (confirmed) {
        router.patch(`/users/${props.user.id}/status`, { status: deactivating ? 'inactive' : 'active' }, { preserveScroll: true });
    }
};

const sendReset = async () => {
    const confirmed = await confirm({
        title: 'Send a password reset link?',
        text: `A link to set a new password will be emailed to ${props.user.email}. Their current password keeps working until they use it.`,
        icon: 'question',
        confirmText: 'Send link',
    });

    if (confirmed) router.post(`/users/${props.user.id}/send-password-reset`, {}, { preserveScroll: true });
};

const setPassword = () => {
    passwordForm.put(`/users/${props.user.id}/password`, {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset('admin_password'),
    });
};

const close = () => emit('close');
</script>

<template>
    <Modal :open="open && !!user" :title="user ? `Edit User — ${user.name}` : 'Edit User'" tone="primary" icon="pencil" size="lg" @close="close">
        <template v-if="user" #summary>
            <div class="flex items-center gap-3">
                <UserAvatar :name="user.name" :photo-url="user.profile_photo_url" size="lg" />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold text-text">{{ user.name }}</p>
                    <p class="truncate text-xs text-text-muted">@{{ user.username }} · {{ user.position || 'No position set' }}</p>
                    <p class="text-xs text-text-muted">
                        Last login: {{ user.last_login_at ? formatDateTime(user.last_login_at) : 'Never' }}
                    </p>
                </div>
                <StatusBadge :status="user.status" />
            </div>
        </template>

        <template v-if="user">
            <Alert v-if="!canEdit" tone="warning" class="mb-4">
                Only a Super Administrator can change this account.
            </Alert>

            <Tabs v-model="tab" :tabs="tabs">
                <template #profile>
                    <form id="edit-user-form" class="space-y-5" novalidate @submit.prevent="saveProfile">
                        <fieldset :disabled="!canEdit" class="space-y-5">
                            <PhotoPicker :name="user.name" :photo-url="user.profile_photo_url" :error="photoError" @select="uploadPhoto">
                                <button
                                    v-if="user.profile_photo_url && canEdit"
                                    type="button"
                                    class="text-xs font-medium text-danger hover:underline"
                                    @click="removePhoto"
                                >
                                    Remove photo
                                </button>
                            </PhotoPicker>

                            <UserProfileFields
                                :form="form"
                                :office-options="officeOptions"
                                :designation-options="designationOptions"
                                :honorific-options="honorificOptions"
                                :plantilla-item-options="plantillaItemOptions"
                                :can-appoint="canAppoint"
                                :can-designate="user.can.designation"
                                id-prefix="edit"
                            />
                        </fieldset>
                    </form>
                </template>

                <template #account>
                    <div class="space-y-6">
                        <div>
                            <TextInput
                                id="edit-username"
                                v-model="form.username"
                                label="Username"
                                required
                                autocomplete="off"
                                :disabled="!canEdit"
                                :invalid="!!form.errors.username"
                            />
                            <p class="mt-1 text-xs text-text-muted">Used to sign in. Saved with the Profile changes.</p>
                            <InputError :message="form.errors.username" />
                        </div>

                        <form class="space-y-3 rounded-md border border-border p-4" @submit.prevent="saveRoles">
                            <fieldset :disabled="!user.can.roles" class="space-y-3">
                                <CheckboxGroup
                                    id="edit-roles"
                                    v-model="rolesForm.role_ids"
                                    legend="IMIS roles"
                                    :options="roleOptions.map((role) => ({ value: role.id, label: role.display_name }))"
                                    :error="rolesForm.errors.role_ids"
                                />
                                <Alert v-if="grantingSuperAdmin" tone="warning">
                                    Granting Super Administrator gives unrestricted control over IMIS.
                                </Alert>
                                <Button type="submit" :loading="rolesForm.processing" :disabled="!rolesForm.isDirty">Save roles</Button>
                            </fieldset>
                        </form>

                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-4">
                            <div>
                                <p class="text-sm font-medium text-text">Account status</p>
                                <p class="text-xs text-text-muted">Inactive accounts can't sign in. Nothing is deleted.</p>
                            </div>
                            <Button :variant="user.status === 'active' ? 'danger' : 'success'" :disabled="!user.can.status" @click="toggleStatus">
                                {{ user.status === 'active' ? 'Deactivate account' : 'Activate account' }}
                            </Button>
                        </div>
                    </div>
                </template>

                <template #security>
                    <div class="space-y-4">
                        <div class="rounded-md border border-border p-4">
                            <p class="text-sm font-medium text-text">Password</p>
                            <p class="mt-1 text-xs text-text-muted">
                                Send the user a secure link to choose a new password. Their current one keeps working until they use it.
                            </p>
                            <Button class="mt-3" variant="secondary" :disabled="!canEdit" @click="sendReset">Send password reset link</Button>
                        </div>
                        <form v-if="user.can.set_password" class="space-y-3 rounded-md border border-warning-border p-4" @submit.prevent="setPassword">
                            <div>
                                <p class="text-sm font-medium text-text">Set password (Super Administrator override)</p>
                                <p class="mt-1 text-xs text-text-muted">
                                    For when a reset link can't be used. Their open sessions are signed out, and the change is logged (never the password).
                                </p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <PasswordInput
                                        id="set-password"
                                        v-model="passwordForm.password"
                                        label="New password"
                                        autocomplete="new-password"
                                        required
                                        :invalid="!!passwordForm.errors.password"
                                    />
                                    <InputError :message="passwordForm.errors.password" />
                                </div>
                                <div>
                                    <PasswordInput
                                        id="set-password-confirmation"
                                        v-model="passwordForm.password_confirmation"
                                        label="Confirm new password"
                                        autocomplete="new-password"
                                        required
                                    />
                                </div>
                            </div>
                            <PasswordRequirements v-if="!passwordForm.errors.password" id="set-password-helper" :requirements="passwordRequirements" />
                            <Checkbox id="set-password-must-change" v-model="passwordForm.must_change_password" label="Require them to choose a new password at their next sign-in" />
                            <div>
                                <PasswordInput
                                    id="set-password-admin"
                                    v-model="passwordForm.admin_password"
                                    label="Your password, to confirm"
                                    autocomplete="current-password"
                                    required
                                    :invalid="!!passwordForm.errors.admin_password"
                                />
                                <InputError :message="passwordForm.errors.admin_password" />
                            </div>
                            <Button type="submit" variant="danger":loading="passwordForm.processing">Set password</Button>
                        </form>
                        <dl class="grid gap-3 rounded-md border border-border p-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-text-muted">Last login</dt>
                                <dd class="text-text">{{ user.last_login_at ? formatDateTime(user.last_login_at) : 'Never' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-muted">Systems with access</dt>
                                <dd class="text-text">{{ user.systems_count }}</dd>
                            </div>
                        </dl>
                    </div>
                </template>
            </Tabs>
        </template>

        <template #footer>
            <Button variant="secondary" @click="close">Close</Button>
            <Button
                v-if="tab !== 'security'"
                type="submit"
                form="edit-user-form"
                :loading="form.processing"
                :disabled="!canEdit || !form.isDirty"
            >
                Save changes
            </Button>
        </template>
    </Modal>
</template>
