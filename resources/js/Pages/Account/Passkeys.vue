<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AccountLayout from '../../Layouts/AccountLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import Badge from '../../Components/UI/Badge.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import PasswordInput from '../../Components/Forms/PasswordInput.vue';
import Alert from '../../Components/Feedback/Alert.vue';
import { confirm, toast } from '../../Composables/useDialog';
import { formatDateTime, timeAgo } from '../../Utils/datetime';

defineOptions({ layout: [PortalLayout, AccountLayout] });

/**
 * Account → Fingerprint & passkeys. A passkey is a key kept on a phone or
 * laptop and unlocked by its fingerprint, face or screen-lock PIN; IMIS
 * stores only the public half and never sees the fingerprint. Adding or
 * removing one first asks for the account password (confirmed for a few
 * hours), so a borrowed, unlocked screen cannot add someone's own device.
 */
const props = defineProps({
    passkeys: { type: Array, default: () => [] },
    passwordConfirmed: { type: Boolean, default: false },
});

const confirmForm = useForm({ current_password: '' });
const confirmPassword = () =>
    confirmForm.post('/account/passkeys/confirm', {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset(),
        onError: () => confirmForm.reset('current_password'),
    });

// A name that tells devices apart in the list; the person can change it.
const guessDeviceName = () => {
    const agent = navigator.userAgent;
    if (/iPhone/i.test(agent)) return 'My iPhone';
    if (/iPad/i.test(agent)) return 'My iPad';
    if (/Android/i.test(agent)) return 'My Android phone';
    if (/Windows/i.test(agent)) return 'My Windows computer';
    if (/Mac/i.test(agent)) return 'My Mac';

    return 'My device';
};
const deviceName = ref(guessDeviceName());

const {
    register,
    isLoading: adding,
    error: addError,
    isSupported,
} = usePasskeyRegister({
    onSuccess: () => {
        toast.success('Done. You can now sign in with this device.');
        router.reload({ only: ['passkeys', 'passwordConfirmed'] });
    },
    // The password confirmation may have lapsed between the page loading and the tap.
    onError: () => router.reload({ only: ['passwordConfirmed'] }),
});

const add = () => register(deviceName.value.trim());

const remove = async (passkey) => {
    const ok = await confirm({
        title: `Remove ${passkey.name}?`,
        text: 'You will no longer be able to sign in with this device. You can add it again later.',
        confirmText: 'Remove',
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/user/passkeys/${passkey.id}`, { preserveScroll: true, only: ['passkeys', 'passwordConfirmed'] });
};

const hasPasskeys = computed(() => props.passkeys.length > 0);

const steps = [
    { icon: 'lock', text: 'Confirm your password once.' },
    { icon: 'shield', text: 'Add this phone or computer. It asks for your fingerprint, face or screen-lock PIN.' },
    { icon: 'user', text: 'Next time, tap "Sign in with fingerprint or face" on the login page.' },
];
</script>

<template>
    <Head title="Fingerprint sign-in" />

    <PageHeader
        title="Fingerprint & passkeys"
        description="Sign in with your phone's fingerprint or face instead of typing your password."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Fingerprint & passkeys' }]"
    />

    <div class="max-w-5xl space-y-6">
        <Alert v-if="!isSupported" tone="warning">
            This browser or device cannot create passkeys. Try your phone, or a recent version of Chrome, Edge, Safari or Firefox. Your password still works everywhere.
        </Alert>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_17rem]">
            <div class="space-y-6">
                <!-- Step 1: the password, before a device can be added or removed. -->
                <Card v-if="!passwordConfirmed" title="Confirm it is you" icon="lock" description="We ask for your password before devices can be added or removed.">
                    <form class="space-y-4" @submit.prevent="confirmPassword">
                        <PasswordInput
                            id="current_password"
                            v-model="confirmForm.current_password"
                            label="Current password"
                            icon="key"
                            autocomplete="current-password"
                            required
                            :error="confirmForm.errors.current_password"
                        />
                        <Button type="submit" :loading="confirmForm.processing" :disabled="confirmForm.processing">Continue</Button>
                    </form>
                </Card>

                <!-- Step 2: add this device. -->
                <Card v-else title="Add this device" icon="shield" description="Your device will ask for your fingerprint, face or screen-lock PIN.">
                    <form class="space-y-4" @submit.prevent="add">
                        <TextInput id="device_name" v-model="deviceName" label="Name this device" icon="desktop" maxlength="60" required />
                        <Alert v-if="addError" tone="danger" icon="xCircle" role="alert">{{ addError }}</Alert>
                        <Button type="submit" :loading="adding" :disabled="adding || !isSupported || !deviceName.trim()">
                            <Icon v-if="!adding" name="plus" size="sm" class="mr-2" />
                            {{ adding ? 'Waiting for your device…' : 'Add this device' }}
                        </Button>
                    </form>
                </Card>

                <Card title="Your devices" icon="desktop" :description="hasPasskeys ? 'Devices you can sign in with.' : null">
                    <EmptyState
                        v-if="!hasPasskeys"
                        icon="shield"
                        title="No devices yet"
                        description="Add this phone or computer above to sign in with a fingerprint."
                    />
                    <ul v-else class="divide-y divide-border">
                        <li v-for="passkey in passkeys" :key="passkey.id" class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-info-bg text-link" aria-hidden="true">
                                <Icon name="shield" size="sm" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-text">{{ passkey.name }}</p>
                                <p class="text-xs text-text-muted">
                                    <template v-if="passkey.authenticator">{{ passkey.authenticator }} · </template>
                                    Added <time :datetime="passkey.created_at">{{ formatDateTime(passkey.created_at) }}</time>
                                    ·
                                    <template v-if="passkey.last_used_at">Last used {{ timeAgo(passkey.last_used_at) }}</template>
                                    <template v-else>Not used yet</template>
                                </p>
                            </div>
                            <Button v-if="passwordConfirmed" variant="outline" size="sm" @click="remove(passkey)">
                                <Icon name="trash" size="sm" />
                                Remove
                            </Button>
                            <Badge v-else tone="neutral">Confirm password to remove</Badge>
                        </li>
                    </ul>
                </Card>
            </div>

            <aside class="space-y-3 text-sm text-text-muted">
                <h2 class="text-xs font-semibold tracking-wide text-text-muted uppercase">How it works</h2>
                <ol class="space-y-3">
                    <li v-for="step in steps" :key="step.text" class="flex items-start gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-surface-muted text-link" aria-hidden="true">
                            <Icon :name="step.icon" size="sm" />
                        </span>
                        <span class="pt-1">{{ step.text }}</span>
                    </li>
                </ol>
                <p class="rounded-lg bg-surface-muted p-3 text-xs">
                    Your fingerprint never leaves your phone. IMIS only receives a signature that proves your device unlocked it. If you lose a device, remove it here, or ask the system administrator.
                </p>
            </aside>
        </div>
    </div>
</template>
