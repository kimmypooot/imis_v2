<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import Button from '../../Components/Button.vue';

defineOptions({ layout: AuthLayout });

const props = defineProps({
    status: {
        type: String,
        default: null,
    },
});

const form = useForm({});

const submit = () => {
    form.post('/email/verification-notification');
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <Head title="Verify email" />

    <h2 class="mb-1 text-lg font-semibold text-text">Verify your email address</h2>
    <p class="mb-5 text-sm text-text-muted">
        Thanks for signing up! Before getting started, please verify your email address by
        clicking the link we sent you.
    </p>

    <div v-if="verificationLinkSent" class="mb-4 rounded-md bg-surface-muted p-3 text-sm text-text" role="status">
        A new verification link has been sent to your email address.
    </div>

    <form class="space-y-5" @submit.prevent="submit">
        <Button type="submit" class="w-full" :disabled="form.processing">
            {{ form.processing ? 'Sending…' : 'Resend verification email' }}
        </Button>
    </form>

    <button
        type="button"
        class="mt-4 text-sm font-medium text-text-muted hover:text-text"
        @click="router.post('/logout')"
    >
        Log out
    </button>
</template>
