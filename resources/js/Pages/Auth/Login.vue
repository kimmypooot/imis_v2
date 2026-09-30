<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextInput from '../../Components/TextInput.vue';
import PasswordInput from '../../Components/PasswordInput.vue';
import Checkbox from '../../Components/Checkbox.vue';
import InputError from '../../Components/InputError.vue';
import Button from '../../Components/Button.vue';

defineOptions({ layout: AuthLayout });

defineProps({
    status: {
        type: String,
        default: null,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        remember: data.remember ? 'on' : '',
    })).post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <h2 class="mb-1 text-lg font-semibold text-text">Sign in</h2>
    <p class="mb-5 text-sm text-text-muted">Access your CSC RO VIII IMIS account.</p>

    <div v-if="status" class="mb-4 rounded-md bg-surface-muted p-3 text-sm text-text" role="status">
        {{ status }}
    </div>

    <form class="space-y-5" @submit.prevent="submit">
        <div>
            <TextInput
                id="email"
                v-model="form.email"
                label="Email address"
                type="email"
                autocomplete="email"
                required
                autofocus
                :invalid="!!form.errors.email"
            />
            <InputError :message="form.errors.email" />
        </div>

        <div>
            <PasswordInput
                id="password"
                v-model="form.password"
                label="Password"
                autocomplete="current-password"
                required
                :invalid="!!form.errors.password"
            />
            <InputError :message="form.errors.password" />
        </div>

        <div class="flex items-center justify-between">
            <Checkbox id="remember" v-model="form.remember" label="Remember me" />

            <Link
                href="/forgot-password"
                class="text-sm font-medium text-primary hover:text-primary-hover"
            >
                Forgot password?
            </Link>
        </div>

        <Button type="submit" class="w-full" :disabled="form.processing">
            {{ form.processing ? 'Signing in…' : 'Sign in' }}
        </Button>
    </form>
</template>
