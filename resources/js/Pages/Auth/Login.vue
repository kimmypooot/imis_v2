<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import PasswordInput from '../../Components/Forms/PasswordInput.vue';
import Checkbox from '../../Components/Forms/Checkbox.vue';
import InputError from '../../Components/Forms/InputError.vue';
import Button from '../../Components/UI/Button.vue';
import Alert from '../../Components/Feedback/Alert.vue';
import Icon from '../../Components/UI/Icon.vue';
import UserAvatar from '../../Components/UI/UserAvatar.vue';
import { firstName } from '../../Composables/useSplash';
import { useRememberedAccounts } from '../../Composables/useRememberedAccounts';

defineOptions({ layout: AuthLayout });

const props = defineProps({
    status: { type: String, default: null },
    // Seconds left on a sign-in lockout (flashed by LoginRequest).
    lockoutSeconds: { type: Number, default: null },
    // Cloudflare Turnstile site key; null when the security check is off.
    turnstileSiteKey: { type: String, default: null },
    // System Settings → Security: whether saved-account cards are offered.
    rememberAccounts: { type: Boolean, default: false },
});

const form = useForm({
    username: '',
    password: '',
    remember: false,
    'cf-turnstile-response': '',
});

// ── Saved accounts (this browser only; name + username, never a password) ──
const { accounts, remember: rememberAccount, forget: forgetAccount, forgetAll } = useRememberedAccounts();
const saveAccount = ref(false);

// With saved accounts the page opens on the list; the form comes after a pick.
const selected = ref(null);
const choosing = ref(props.rememberAccounts && accounts.value.length > 0);

const pickAccount = (account) => {
    selected.value = account;
    form.username = account.username;
    form.clearErrors();
    saveAccount.value = true;
    choosing.value = false;
    nextTick(() => document.getElementById('password')?.focus());
};

const backToList = () => {
    selected.value = null;
    form.reset('username', 'password');
    form.clearErrors();
    saveAccount.value = false;
    choosing.value = props.rememberAccounts && accounts.value.length > 0;
};

const useAnotherAccount = () => {
    selected.value = null;
    form.reset('username', 'password');
    form.clearErrors();
    choosing.value = false;
    nextTick(() => document.getElementById('username')?.focus());
};

const removeAccount = (username) => {
    forgetAccount(username);
    if (accounts.value.length === 0) choosing.value = false;
};

// ── Caps Lock warning (legacy login parity) ──────────────────────────
const capsLockOn = ref(false);
const trackCapsLock = (event) => {
    if (typeof event.getModifierState === 'function') {
        capsLockOn.value = event.getModifierState('CapsLock');
    }
};

// ── Lockout countdown ────────────────────────────────────────────────
const lockoutRemaining = ref(props.lockoutSeconds ?? 0);
let lockoutTimer = null;
const lockoutClock = computed(() => {
    const minutes = Math.floor(lockoutRemaining.value / 60);
    const seconds = String(lockoutRemaining.value % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});

const startLockoutCountdown = () => {
    window.clearInterval(lockoutTimer);
    if (lockoutRemaining.value <= 0) return;

    lockoutTimer = window.setInterval(() => {
        lockoutRemaining.value = Math.max(0, lockoutRemaining.value - 1);
        if (lockoutRemaining.value === 0) {
            window.clearInterval(lockoutTimer);
            form.clearErrors('username');
        }
    }, 1000);
};

// A failed attempt returns to this same page instance with new props.
watch(
    () => props.lockoutSeconds,
    (seconds) => {
        lockoutRemaining.value = seconds ?? 0;
        startLockoutCountdown();
    },
);

// ── Cloudflare Turnstile (explicit render) ───────────────────────────
const turnstileRef = ref(null);
let turnstileWidgetId = null;

const renderTurnstile = () => {
    if (!window.turnstile || !turnstileRef.value || turnstileWidgetId !== null) return;

    turnstileWidgetId = window.turnstile.render(turnstileRef.value, {
        sitekey: props.turnstileSiteKey,
        theme: 'light',
        callback: (token) => (form['cf-turnstile-response'] = token),
        'expired-callback': () => (form['cf-turnstile-response'] = ''),
        'error-callback': () => (form['cf-turnstile-response'] = ''),
    });
};

const loadTurnstile = () => {
    if (window.turnstile) {
        renderTurnstile();

        return;
    }

    const script = document.createElement('script');
    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    script.async = true;
    script.addEventListener('load', renderTurnstile);
    document.head.appendChild(script);
};

// Tokens are single-use, so every failed attempt needs a fresh one.
const resetTurnstile = () => {
    if (window.turnstile && turnstileWidgetId !== null) {
        window.turnstile.reset(turnstileWidgetId);
        form['cf-turnstile-response'] = '';
    }
};

onMounted(() => {
    // An administrator turning the feature off also clears what's saved here.
    if (!props.rememberAccounts) forgetAll();
    startLockoutCountdown();
    if (props.turnstileSiteKey) loadTurnstile();
});

onBeforeUnmount(() => {
    window.clearInterval(lockoutTimer);
    if (window.turnstile && turnstileWidgetId !== null) window.turnstile.remove(turnstileWidgetId);
});

const isLockedOut = computed(() => lockoutRemaining.value > 0);

// The wrong-credentials (or inactive-account) message; a lockout has its own alert.
const credentialError = computed(() => (isLockedOut.value ? null : form.errors.username || null));

// Each failure re-keys the alert (so it shakes again) and returns the cursor to the password.
const failureCount = ref(0);
watch(
    () => form.errors.username,
    (message) => {
        if (!message) return;
        failureCount.value++;
        nextTick(() => document.getElementById(selected.value ? 'password' : 'username')?.focus());
    },
);

const submit = () => {
    form.transform((data) => ({
        ...data,
        remember: data.remember ? 'on' : '',
    })).post('/login', {
        onSuccess: (page) => {
            const user = page.props.auth?.user;
            if (props.rememberAccounts && saveAccount.value && user) {
                rememberAccount({ name: user.name, username: user.username, photoUrl: user.profile_photo_url });
            }
        },
        onFinish: () => form.reset('password'),
        onError: () => resetTurnstile(),
    });
};
</script>

<template>
    <Head title="Log in" />

    <div class="mb-6 text-center">
        <span class="inline-flex items-center gap-2 rounded-full bg-info-bg px-4 py-1.5 text-xs font-medium text-primary">
            <Icon name="user" size="sm" />
            Login as CSC RO VIII Employee
        </span>
    </div>

    <Alert v-if="status" tone="success" icon="checkCircle" class="mb-4">{{ status }}</Alert>

    <Alert v-if="isLockedOut" tone="warning" class="mb-4">
        Too many failed attempts. Please wait <strong class="tabular-nums">{{ lockoutClock }}</strong> before trying
        again.
    </Alert>

    <div v-if="choosing">
        <div class="mb-4 text-center">
            <span class="mx-auto mb-2 flex h-11 w-11 items-center justify-center rounded-full bg-info-bg text-primary">
                <Icon name="users" />
            </span>
            <p class="text-base font-semibold">Choose an account</p>
            <p class="text-xs text-muted">Pick who is signing in on this device.</p>
        </div>
        <ul class="space-y-2">
            <li
                v-for="account in accounts"
                :key="account.username"
                class="group flex items-center gap-2 rounded-lg border border-border px-3 py-2 transition-colors hover:border-primary hover:bg-info-bg"
            >
                <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="pickAccount(account)">
                    <UserAvatar :name="account.name" :photo-url="account.photoUrl" size="lg" highlight />
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium">{{ account.name }}</span>
                        <span class="block truncate text-xs text-muted">{{ account.username }}</span>
                    </span>
                </button>
                <button
                    type="button"
                    class="rounded-full p-1 text-muted transition-colors hover:text-danger"
                    :title="`Remove ${account.name} from this device`"
                    :aria-label="`Remove ${account.name} from this device`"
                    @click="removeAccount(account.username)"
                >
                    <Icon name="xCircle" />
                </button>
            </li>
        </ul>
        <button
            type="button"
            class="mt-4 flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-border px-3 py-2.5 text-sm font-medium text-primary hover:border-primary hover:bg-info-bg"
            @click="useAnotherAccount"
        >
            <Icon name="plus" size="sm" />
            Use another account
        </button>
    </div>

    <form
        v-show="!choosing"
        class="space-y-5"
        :class="{ '[&_input]:border-danger': credentialError }"
        @submit.prevent="submit"
    >
        <!-- Re-keyed on every failure so it shakes again, even for the same message. -->
        <Alert
            v-if="credentialError"
            :key="failureCount"
            tone="danger"
            icon="xCircle"
            class="motion-safe:animate-shake"
        >
            <p class="font-medium">{{ credentialError }}</p>
            <p v-if="credentialError.startsWith('Invalid')" class="mt-0.5 text-xs">
                Check them and try again, or
                <Link href="/forgot-password" class="font-medium underline">reset your password</Link>.
            </p>
        </Alert>

        <div v-if="selected" class="relative rounded-xl bg-info-bg px-4 py-5 text-center">
            <button
                type="button"
                class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center rounded-full bg-surface text-primary shadow-xs ring-1 ring-border transition-colors hover:bg-primary hover:text-white"
                title="Switch account"
                aria-label="Switch account"
                @click="backToList"
            >
                <Icon name="users" size="sm" />
            </button>
            <UserAvatar :name="selected.name" :photo-url="selected.photoUrl" size="xl" highlight class="mx-auto" />
            <p class="mt-3 text-xs font-medium text-primary">Welcome back, {{ firstName(selected.name) }}</p>
            <p class="truncate text-base font-semibold">{{ selected.name }}</p>
            <p class="truncate text-xs text-muted">{{ selected.username }}</p>
        </div>

        <button
            v-if="!selected && rememberAccounts && accounts.length"
            type="button"
            class="flex items-center gap-1 text-sm font-medium text-primary hover:text-primary-hover"
            @click="backToList"
        >
            <Icon name="chevronLeft" size="sm" />
            Back to saved accounts
        </button>

        <!-- Lets password managers pair the password with the picked account. -->
        <input
            v-if="selected"
            v-model="form.username"
            type="text"
            name="username"
            autocomplete="username"
            tabindex="-1"
            aria-hidden="true"
            class="sr-only"
        />

        <div>
            <TextInput
                v-if="!selected"
                id="username"
                v-model="form.username"
                label="Username"
                icon="user"
                autocomplete="username"
                required
                autofocus
                :invalid="!!form.errors.username"
            />
        </div>

        <div @keydown="trackCapsLock" @keyup="trackCapsLock" @focusout="capsLockOn = false">
            <PasswordInput
                id="password"
                v-model="form.password"
                label="Password"
                icon="lock"
                autocomplete="current-password"
                required
                :invalid="!!form.errors.password"
            />
            <p v-if="capsLockOn" class="mt-1 flex items-center gap-1 text-xs font-medium text-warning" role="status">
                <Icon name="exclamation" size="sm" />
                Caps Lock is on
            </p>
            <InputError :message="form.errors.password" />
        </div>

        <div v-if="turnstileSiteKey">
            <div ref="turnstileRef" class="flex justify-center" />
            <InputError :message="form.errors['cf-turnstile-response']" class="text-center" />
        </div>

        <div v-if="rememberAccounts && !selected">
            <Checkbox id="save-account" v-model="saveAccount" label="Save my account on this device" />
        </div>

        <div class="flex items-center justify-between">
            <Checkbox id="remember" v-model="form.remember" label="Keep me signed in" />

            <Link href="/forgot-password" class="text-sm font-medium text-primary hover:text-primary-hover">
                Forgot password?
            </Link>
        </div>

        <Button type="submit" class="w-full" :class="{ 'bg-csc-red!': form.processing }" :loading="form.processing" :disabled="form.processing || isLockedOut">
            <Icon v-if="!form.processing" name="lock" size="sm" class="mr-2" />
            {{ form.processing ? 'Logging in…' : 'Login' }}
        </Button>
    </form>
</template>
