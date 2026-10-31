<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AccountLayout from '../../Layouts/AccountLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import PasswordInput from '../../Components/Forms/PasswordInput.vue';
import InputError from '../../Components/Forms/InputError.vue';
import PasswordRequirements from '../../Components/Forms/PasswordRequirements.vue';
import Alert from '../../Components/Feedback/Alert.vue';

defineOptions({ layout: [PortalLayout, AccountLayout] });

defineProps({
    requirements: { type: Array, default: () => [] },
    // Sent here by password expiry (System Settings → Security).
    expired: { type: Boolean, default: false },
    expiryDays: { type: Number, default: 0 },
    // Sent here because a Super Administrator set their password.
    setByAdmin: { type: Boolean, default: false },
});

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put('/account/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('current_password'),
    });
};
</script>

<template>
    <Head title="Change password" />

    <PageHeader
        title="Change password"
        description="Choose a new password for your IMIS account."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Change password' }]"
    />

    <Alert v-if="setByAdmin" tone="warning" class="mb-4 max-w-lg">
        An administrator set your password. Choose a new one of your own to continue using IMIS; use the one you were given as your current password.
    </Alert>
    <Alert v-else-if="expired" tone="warning" class="mb-4 max-w-lg">
        Your password is more than {{ expiryDays }} days old. Choose a new one to continue using IMIS.
    </Alert>

    <Card class="max-w-lg">
        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <PasswordInput
                    id="current_password"
                    v-model="form.current_password"
                    label="Current password"
                    autocomplete="current-password"
                    required
                    :invalid="!!form.errors.current_password"
                />
                <InputError :message="form.errors.current_password" />
            </div>

            <div>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    label="New password"
                    autocomplete="new-password"
                    required
                    :invalid="!!form.errors.password"
                />
                <PasswordRequirements v-if="!form.errors.password" id="password-helper" :requirements="requirements" />
                <InputError :message="form.errors.password" />
            </div>

            <div>
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    label="Confirm new password"
                    autocomplete="new-password"
                    required
                />
            </div>

            <Button type="submit" :loading="form.processing">Update password</Button>
        </form>
    </Card>
</template>
