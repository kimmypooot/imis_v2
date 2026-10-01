<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import PasswordInput from '../../Components/Forms/PasswordInput.vue';
import Checkbox from '../../Components/Forms/Checkbox.vue';
import InputError from '../../Components/Forms/InputError.vue';
import Button from '../../Components/UI/Button.vue';
import Alert from '../../Components/Feedback/Alert.vue';
import Icon from '../../Components/UI/Icon.vue';

defineOptions({ layout: AuthLayout });

const props = defineProps({
    status: { type: String, default: null },
    // Seconds left on a sign-in lockout (flashed by LoginRequest).
    lockoutSeconds: { type: Number, default: null },
    // Cloudflare Turnstile site key; null when the security check is off.
    turnstileSiteKey: { type: String, default: null },
});

const form = useForm({
    username: '',
    password: '',
    remember: false,
    mode: 'employee',
    'cf-turnstile-response': '',
});

const adminMode = computed({
    get: () => form.mode === 'admin',
    set: (value) => (form.mode = value ? 'admin' : 'employee'),
});

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
    startLockoutCountdown();
    if (props.turnstileSiteKey) loadTurnstile();
});

onBeforeUnmount(() => {
    window.clearInterval(lockoutTimer);
    if (window.turnstile && turnstileWidgetId !== null) window.turnstile.remove(turnstileWidgetId);
});

const isLockedOut = computed(() => lockoutRemaining.value > 0);

const submit = () => {
    form.transform((data) => ({
        ...data,
        remember: data.remember ? 'on' : '',
    })).post('/login', {
        onFinish: () => form.reset('password'),
        onError: () => resetTurnstile(),
    });
};
</script>

<template>
    <Head title="Log in" />

    <div class="mb-6 text-center">
        <span class="inline-flex items-center gap-2 rounded-full bg-info-bg px-4 py-1.5 text-xs font-medium text-primary">
            <Icon :name="adminMode ? 'shield' : 'user'" size="sm" />
            {{ adminMode ? 'Login as Administrator' : 'Login as CSC RO VIII Employee' }}
        </span>
    </div>

    <Alert v-if="status" tone="success" class="mb-4">{{ status }}</Alert>

    <Alert v-if="isLockedOut" tone="warning" class="mb-4">
        Too many failed attempts. Please wait <strong class="tabular-nums">{{ lockoutClock }}</strong> before trying
        again.
    </Alert>

    <form class="space-y-5" @submit.prevent="submit">
        <div>
            <TextInput
                id="username"
                v-model="form.username"
                label="Username"
                autocomplete="username"
                required
                autofocus
                :invalid="!!form.errors.username"
            />
            <InputError v-if="!isLockedOut" :message="form.errors.username" />
        </div>

        <div @keydown="trackCapsLock" @keyup="trackCapsLock" @focusout="capsLockOn = false">
            <PasswordInput
                id="password"
                v-model="form.password"
                label="Password"
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

        <div class="flex items-center justify-between">
            <Checkbox id="remember" v-model="form.remember" label="Remember me" />

            <Link href="/forgot-password" class="text-sm font-medium text-primary hover:text-primary-hover">
                Forgot password?
            </Link>
        </div>

        <Button type="submit" class="w-full" :loading="form.processing" :disabled="form.processing || isLockedOut">
            {{ form.processing ? 'Logging in…' : 'Login' }}
        </Button>
    </form>

    <!-- The legacy "Switch to Superadmin Login" toggle. It only chooses
         where you land; the admin console still checks your access. -->
    <div class="mt-6 flex items-center justify-between gap-3 border-t border-border pt-4">
        <span id="admin-mode-label" class="text-xs text-text-muted">Open the admin console after login</span>
        <button
            type="button"
            role="switch"
            :aria-checked="adminMode"
            aria-labelledby="admin-mode-label"
            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            :class="adminMode ? 'bg-primary' : 'bg-border'"
            @click="adminMode = !adminMode"
        >
            <span
                class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform"
                :class="adminMode ? 'translate-x-5' : 'translate-x-0.5'"
                aria-hidden="true"
            />
        </button>
    </div>
</template>
