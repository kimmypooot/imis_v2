<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import PasswordInput from '../../Components/Forms/PasswordInput.vue';
import InputError from '../../Components/Forms/InputError.vue';
import PasswordRequirements from '../../Components/Forms/PasswordRequirements.vue';
import Button from '../../Components/UI/Button.vue';

defineOptions({ layout: AuthLayout });

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
    passwordRequirements: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Reset password" />

    <h2 class="mb-1 text-lg font-semibold text-text">Reset your password</h2>
    <p class="mb-5 text-sm text-text-muted">Choose a new password for your account.</p>

    <form class="space-y-5" @submit.prevent="submit">
        <div>
            <TextInput
                id="email"
                v-model="form.email"
                label="Email address"
                type="email"
                autocomplete="email"
                required
                :invalid="!!form.errors.email"
            />
            <InputError :message="form.errors.email" />
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
            <PasswordRequirements v-if="!form.errors.password" :requirements="passwordRequirements" />
            <InputError :message="form.errors.password" />
        </div>

        <div>
            <PasswordInput
                id="password_confirmation"
                v-model="form.password_confirmation"
                label="Confirm new password"
                autocomplete="new-password"
                required
                :invalid="!!form.errors.password_confirmation"
            />
            <InputError :message="form.errors.password_confirmation" />
        </div>

        <Button type="submit" class="w-full" :disabled="form.processing">
            {{ form.processing ? 'Resetting…' : 'Reset password' }}
        </Button>
    </form>
</template>
