<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import AuthHeading from '../../Components/UI/AuthHeading.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';

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

    <AuthHeading title="Verify your email" subtitle="Click the link we sent to your email address." />

    <Alert v-if="verificationLinkSent" tone="success" class="mb-4">
        A new verification link has been sent to your email address.
    </Alert>

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
