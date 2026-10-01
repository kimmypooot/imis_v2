<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import ConfirmDialog from '../Feedback/ConfirmDialog.vue';
import { useAuthorization } from '../../Composables/useAuthorization';
import { firstName, useSplash } from '../../Composables/useSplash';
import { portalNavItems } from '../../Utils/navigation';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The account menu, following the legacy IMIS profile dropdown: who you
 * are and when you last signed in, then Administration, Change password,
 * What's New, About, and Sign out (confirmed, then the "Signing Out"
 * splash carries you to the login page).
 */
const props = defineProps({
    user: {
        type: Object,
        required: true,
        /** { name, username, email, previous_login_at } */
    },
});

const emit = defineEmits(['about']);

const { canAny } = useAuthorization();
const { show: showSplash, hide: hideSplash } = useSplash();

// Same any-of list as the Administration nav entry (kept in one place).
const adminPermissions = portalNavItems.find((item) => item.href === '/admin')?.permission ?? [];
const canAccessAdmin = computed(() => canAny(adminPermissions));

const open = ref(false);
const rootRef = ref(null);

const toggle = () => {
    open.value = !open.value;
};

const close = () => {
    open.value = false;
};

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) {
        close();
    }
};

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        close();
    }
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

const openAbout = () => {
    close();
    emit('about');
};

const confirmingLogout = ref(false);

const requestLogout = () => {
    close();
    confirmingLogout.value = true;
};

const logout = () => {
    confirmingLogout.value = false;
    showSplash({
        title: 'Signing Out',
        subtitle: 'See you soon, {name}',
        name: firstName(props.user.name),
        note: 'Returning to the login page…',
    });

    // A short hold so the message can be read, then the splash stays up
    // until the login page has rendered underneath it.
    window.setTimeout(() => {
        router.post('/logout', {}, { onFinish: () => hideSplash(500) });
    }, 700);
};

const initials = props.user.name
    .split(' ')
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

const itemClass =
    'flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-text hover:bg-surface-muted focus:bg-surface-muted focus:outline-none';
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            type="button"
            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="toggle"
        >
            <span
                class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-xs font-semibold text-white"
                aria-hidden="true"
            >
                {{ initials }}
            </span>
            <span class="hidden text-text sm:inline">{{ user.name }}</span>
            <Icon name="chevronDown" size="sm" class="text-text-muted" />
        </button>

        <div
            v-if="open"
            role="menu"
            class="absolute right-0 z-50 mt-2 w-64 rounded-md border border-border bg-surface py-1 shadow-lg"
        >
            <div class="border-b border-border px-3 py-2">
                <p class="truncate text-sm font-medium text-text">{{ user.name }}</p>
                <p class="truncate text-xs text-text-muted">@{{ user.username }} · {{ user.email }}</p>
                <p v-if="user.previous_login_at" class="mt-1 text-xs text-text-muted">
                    Last login: <time :datetime="user.previous_login_at">{{ formatDateTime(user.previous_login_at) }}</time>
                </p>
            </div>

            <Link v-if="canAccessAdmin" href="/admin" role="menuitem" :class="itemClass" @click="close">
                <Icon name="shield" size="sm" />
                Administration
            </Link>
            <Link href="/account/password" role="menuitem" :class="itemClass" @click="close">
                <Icon name="lock" size="sm" />
                Change password
            </Link>
            <Link href="/whats-new" role="menuitem" :class="itemClass" @click="close">
                <Icon name="megaphone" size="sm" />
                What's New
            </Link>
            <button type="button" role="menuitem" :class="itemClass" @click="openAbout">
                <Icon name="info" size="sm" />
                About IMIS
            </button>

            <div class="my-1 border-t border-border" />

            <button type="button" role="menuitem" :class="itemClass" @click="requestLogout">
                <Icon name="logout" size="sm" />
                Sign out
            </button>
        </div>

        <ConfirmDialog
            :open="confirmingLogout"
            title="Sign out of IMIS?"
            :danger="false"
            confirm-label="Sign out"
            @confirm="logout"
            @cancel="confirmingLogout = false"
        >
            You will be returned to the login page.
        </ConfirmDialog>
    </div>
</template>
