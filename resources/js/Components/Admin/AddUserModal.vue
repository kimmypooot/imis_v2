<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Alert from '../Feedback/Alert.vue';
import Button from '../UI/Button.vue';
import TextInput from '../Forms/TextInput.vue';
import InputError from '../Forms/InputError.vue';
import CheckboxGroup from '../Forms/CheckboxGroup.vue';
import PhotoPicker from '../Forms/PhotoPicker.vue';
import UserProfileFields from './UserProfileFields.vue';

/**
 * The legacy "Add New User" modal (green header). No password field: the
 * new account gets a set-password link by email (the legacy birthday
 * password is not carried over).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    roleOptions: { type: Array, required: true },
    officeOptions: { type: Array, required: true },
    designationOptions: { type: Array, default: () => [] },
    honorificOptions: { type: Array, default: () => [] },
    canDesignate: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const blank = () => ({
    office_designation: '',
    honorific: '',
    first_name: '',
    middle_name: '',
    name_extension: '',
    last_name: '',
    email: '',
    position: '',
    sex: '',
    birthday: '',
    organizational_unit_id: '',
    username: '',
    role_ids: [],
    profile_photo: null,
});

const form = useForm(blank());

const clean = (value) => (value ?? '').toString().toLowerCase().normalize('NFD').replace(/[^a-z0-9]/g, '');

// What the server will generate if Username is left blank (legacy rule).
const suggestedUsername = computed(() => {
    const base = clean(form.first_name).slice(0, 1) + clean(form.middle_name).slice(0, 1) + clean(form.last_name);

    return base || null;
});

const superAdminId = computed(() => props.roleOptions.find((role) => role.name === 'super-admin')?.id);
const grantsSuperAdmin = computed(() => superAdminId.value && form.role_ids.includes(superAdminId.value));

const close = () => {
    form.reset();
    form.clearErrors();
    emit('close');
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        organizational_unit_id: data.organizational_unit_id || null,
        office_designation: data.office_designation || null,
        sex: data.sex || null,
        birthday: data.birthday || null,
        username: data.username || null,
    })).post('/users', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: close,
    });
};
</script>

<template>
    <Modal :open="open" title="Add New User" tone="success" icon="userPlus" size="lg" @close="close">
        <form id="add-user-form" class="space-y-5" @submit.prevent="submit">
            <PhotoPicker
                :name="[form.first_name, form.last_name].join(' ').trim() || 'New user'"
                :error="form.errors.profile_photo"
                @select="(file) => (form.profile_photo = file)"
            />

            <UserProfileFields
                :form="form"
                :office-options="officeOptions"
                :designation-options="designationOptions"
                :honorific-options="honorificOptions"
                :can-designate="canDesignate"
                id-prefix="add"
            />

            <div>
                <TextInput id="add-username" v-model="form.username" label="Username" autocomplete="off" :invalid="!!form.errors.username" />
                <p v-if="!form.errors.username" class="mt-1 text-xs text-text-muted">
                    Used to sign in.
                    <template v-if="suggestedUsername">Leave blank to use <strong>{{ suggestedUsername }}</strong> (or a numbered variant if taken).</template>
                    <template v-else>Leave blank to generate one from the name.</template>
                </p>
                <InputError :message="form.errors.username" />
            </div>

            <CheckboxGroup
                id="add-roles"
                v-model="form.role_ids"
                legend="IMIS roles"
                helper-text="What this person can do in IMIS itself. Their roles inside each system are set under User Access."
                :options="roleOptions.map((role) => ({ value: role.id, label: role.display_name }))"
                :error="form.errors.role_ids"
            />

            <Alert v-if="grantsSuperAdmin" tone="warning">
                Super Administrator access gives unrestricted control over IMIS.
            </Alert>

            <Alert tone="info">
                No password is set here. A secure link to set a password is emailed to the new account right away.
            </Alert>
        </form>

        <template #footer>
            <Button variant="secondary" @click="close">Cancel</Button>
            <Button type="submit" form="add-user-form" variant="success" :loading="form.processing">Add User</Button>
        </template>
    </Modal>
</template>
