<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import ConfirmDialog from '../../../Components/Feedback/ConfirmDialog.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    user: { type: Object, required: true },
});

const { can } = useAuthorization();

const form = useForm({
    name: props.user.name,
    username: props.user.username,
    email: props.user.email,
});

const submit = () => {
    form.put(`/users/${props.user.id}`);
};

const isActive = computed(() => props.user.status === 'active');
const confirmOpen = ref(false);
const statusForm = useForm({ status: '' });

const toggleStatus = () => {
    statusForm.status = isActive.value ? 'inactive' : 'active';
    statusForm.patch(`/users/${props.user.id}/status`, {
        onSuccess: () => (confirmOpen.value = false),
    });
};

const passwordResetForm = useForm({});
const sendPasswordReset = () => {
    passwordResetForm.post(`/users/${props.user.id}/send-password-reset`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Edit user — ${user.name}`" />

    <PageHeader
        :title="`Edit user — ${user.name}`"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Users', href: '/users' },
            { label: user.name },
        ]"
    >
        <template v-if="can('users.delete')" #actions>
            <Button :variant="isActive ? 'danger' : 'primary'" @click="confirmOpen = true">
                {{ isActive ? 'Deactivate' : 'Activate' }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-6 lg:grid-cols-2">
        <Card title="Account information">
            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <TextInput id="name" v-model="form.name" label="Full name" required :invalid="!!form.errors.name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div>
                    <TextInput
                        id="username"
                        v-model="form.username"
                        label="Username"
                        autocomplete="off"
                        required
                        :invalid="!!form.errors.username"
                    />
                    <InputError :message="form.errors.username" />
                </div>

                <div>
                    <TextInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        label="Email address"
                        required
                        :invalid="!!form.errors.email"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <Button type="submit" :loading="form.processing">Save</Button>
            </form>
        </Card>

        <Card title="Password">
            <p class="mb-4 text-sm text-text-muted">
                Passwords are never set or viewed here. Sending a reset link emails the account a secure link to
                choose their own password — the same flow as "forgot password".
            </p>
            <Button variant="secondary" :loading="passwordResetForm.processing" @click="sendPasswordReset">
                Send password reset link
            </Button>
        </Card>
    </div>

    <ConfirmDialog
        :open="confirmOpen"
        :title="isActive ? 'Deactivate this account?' : 'Activate this account?'"
        :danger="isActive"
        :confirm-label="isActive ? 'Deactivate' : 'Activate'"
        :processing="statusForm.processing"
        @confirm="toggleStatus"
        @cancel="confirmOpen = false"
    >
        {{
            isActive
                ? `${user.name} will no longer be able to sign in.`
                : `${user.name} will be able to sign in again.`
        }}
    </ConfirmDialog>
</template>
