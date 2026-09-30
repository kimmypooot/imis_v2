<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextInput from '../../Components/TextInput.vue';
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
});

const submit = () => {
    form.post('/forgot-password');
};
</script>

<template>
    <Head title="Forgot password" />

    <h2 class="mb-1 text-lg font-semibold text-text">Forgot your password?</h2>
    <p class="mb-5 text-sm text-text-muted">
        Enter your email address and we'll send you a link to reset your password.
    </p>

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

        <Button type="submit" class="w-full" :disabled="form.processing">
            {{ form.processing ? 'Sending…' : 'Email password reset link' }}
        </Button>
    </form>

    <p class="mt-6 text-center text-sm text-text-muted">
        <Link href="/login" class="font-medium text-primary hover:text-primary-hover">
            Back to sign in
        </Link>
    </p>
</template>
