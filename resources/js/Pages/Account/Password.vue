<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AccountLayout from '../../Layouts/AccountLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
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

/**
 * A live, advisory strength hint while typing. The server's rules
 * (System Settings → Security) remain the authority; this only nudges
 * toward longer, more varied passwords.
 */
const strength = computed(() => {
    const value = form.password;
    if (!value) {
        return { score: 0, label: '', bar: 'bg-border', text: 'text-text-muted' };
    }

    let score = 0;
    if (value.length >= 8) score++;
    if (value.length >= 12) score++;
    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
    if (/\d/.test(value)) score++;
    if (/[^A-Za-z0-9]/.test(value)) score++;
    score = Math.min(4, Math.max(1, score - (value.length < 8 ? 1 : 0)));

    return [
        null,
        { score, label: 'Weak', bar: 'bg-danger', text: 'text-danger-fg' },
        { score, label: 'Fair', bar: 'bg-warning', text: 'text-warning-fg' },
        { score, label: 'Good', bar: 'bg-info', text: 'text-info-fg' },
        { score, label: 'Strong', bar: 'bg-success', text: 'text-success-fg' },
    ][score];
});

const confirmState = computed(() => {
    if (!form.password_confirmation) return null;

    return form.password_confirmation === form.password ? 'match' : 'mismatch';
});

const tips = [
    { icon: 'key', text: 'Use a passphrase: several unrelated words are long and easy to remember.' },
    { icon: 'shield', text: 'Never reuse a password from another site or service.' },
    { icon: 'eye', text: 'IMIS staff will never ask you for your password, by email or in person.' },
];
</script>

<template>
    <Head title="Change password" />

    <PageHeader
        title="Change password"
        description="Choose a new password for your IMIS account."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Change password' }]"
    />

    <div class="max-w-5xl">
        <Alert v-if="setByAdmin" tone="warning" class="mb-6">
            An administrator set your password. Choose a new one of your own to continue using IMIS; use the one you were given as your current password.
        </Alert>
        <Alert v-else-if="expired" tone="warning" class="mb-6">
            Your password is more than {{ expiryDays }} days old. Choose a new one to continue using IMIS.
        </Alert>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_17rem]">
            <Card title="Update your password" icon="lock" description="You'll stay signed in on this device.">
                <form class="space-y-6" @submit.prevent="submit">
                    <div>
                        <PasswordInput
                            id="current_password"
                            v-model="form.current_password"
                            label="Current password"
                            icon="key"
                            autocomplete="current-password"
                            required
                            :error="form.errors.current_password"
                        />
                    </div>

                    <hr class="border-border" />

                    <div>
                        <PasswordInput
                            id="password"
                            v-model="form.password"
                            label="New password"
                            icon="lock"
                            autocomplete="new-password"
                            required
                            :invalid="!!form.errors.password"
                        />

                        <!-- Strength meter: four segments filling with the score. -->
                        <div v-if="form.password" class="mt-3" aria-live="polite">
                            <div class="flex gap-1" aria-hidden="true">
                                <span
                                    v-for="segment in 4"
                                    :key="segment"
                                    class="h-1.5 flex-1 rounded-full transition-colors duration-300"
                                    :class="segment <= strength.score ? strength.bar : 'bg-border'"
                                />
                            </div>
                            <p class="mt-1 text-xs">
                                <span class="text-text-muted">Strength: </span>
                                <span class="font-semibold" :class="strength.text">{{ strength.label }}</span>
                            </p>
                        </div>

                        <PasswordRequirements v-if="!form.errors.password" id="password-helper" :requirements="requirements" />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div>
                        <PasswordInput
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            label="Confirm new password"
                            icon="check"
                            autocomplete="new-password"
                            required
                            :invalid="confirmState === 'mismatch'"
                        />
                        <p v-if="confirmState" class="mt-2 flex items-center gap-1.5 text-xs" :class="confirmState === 'match' ? 'text-success-fg' : 'text-danger-fg'" role="status">
                            <Icon :name="confirmState === 'match' ? 'check' : 'exclamation'" size="xs" />
                            {{ confirmState === 'match' ? 'Passwords match' : 'Passwords do not match yet' }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 border-t border-border pt-6">
                        <Button type="submit" :loading="form.processing" :disabled="!form.current_password || !form.password || confirmState === 'mismatch'" icon="check">
                            Update password
                        </Button>
                        <p v-if="form.recentlySuccessful" class="flex items-center gap-1.5 text-xs text-success-fg" role="status">
                            <Icon name="check" size="xs" />
                            Password updated
                        </p>
                    </div>
                </form>
            </Card>

            <!-- Side guidance: the brand-tinted tips panel. -->
            <aside class="self-start rounded-lg border border-info-border bg-info-bg p-6" aria-label="Password tips">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-link">
                    <Icon name="shield" size="sm" />
                    Staying secure
                </h2>
                <ul class="mt-4 space-y-4">
                    <li v-for="tip in tips" :key="tip.text" class="flex gap-3 text-sm leading-relaxed text-text-muted">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface text-link" aria-hidden="true">
                            <Icon :name="tip.icon" size="xs" />
                        </span>
                        {{ tip.text }}
                    </li>
                </ul>
            </aside>
        </div>
    </div>
</template>
