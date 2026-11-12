<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import InputError from '../../Components/Forms/InputError.vue';
import Button from '../../Components/UI/Button.vue';
import Alert from '../../Components/Feedback/Alert.vue';

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

    <Alert v-if="status" tone="success" class="mb-4">{{ status }}</Alert>

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
                :error="form.errors.email"
            />
        </div>

        <Button type="submit" class="w-full" :disabled="form.processing">
            {{ form.processing ? 'Sending…' : 'Email password reset link' }}
        </Button>
    </form>

    <p class="mt-6 text-center text-sm text-text-muted">
        <Link href="/login" class="font-medium text-link hover:text-primary-hover">
            Back to sign in
        </Link>
    </p>
</template>
