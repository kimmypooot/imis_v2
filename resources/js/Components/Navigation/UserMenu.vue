<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import ConfirmDialog from '../Feedback/ConfirmDialog.vue';
import { useAuthorization } from '../../Composables/useAuthorization';
import { firstName, useSplash } from '../../Composables/useSplash';
import { adminAccessPermissions, hrAccessPermissions } from '../../Utils/navigation';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The account menu, following the legacy IMIS profile dropdown: who you
 * are and when you last signed in, then the links in groups — Management
 * (HR Management and Administration, each only with access to that area),
 * your account (Change password, Who accessed my record), and about IMIS
 * (What's New, Privacy notice, About) — and Sign out (confirmed, then the "Signing Out"
 * splash carries you to the login page).
 */
const props = defineProps({
    user: {
        type: Object,
        required: true,
        /** { name, username, email, position, profile_photo_url, previous_login_at } */
    },
});

const emit = defineEmits(['about']);

const { canAny } = useAuthorization();
const { show: showSplash, hide: hideSplash } = useSplash();

// Same any-of lists as the sidebar's Management entries (kept in one place).
const canAccessAdmin = computed(() => canAny(adminAccessPermissions));
const canAccessHr = computed(() => canAny(hrAccessPermissions));

const open = ref(false);
const rootRef = ref(null);
const buttonRef = ref(null);

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

// Escape closes the menu and, if focus was inside it, hands focus back
// to the button so keyboard users aren't dropped at the top of the page.
const onKeydown = (event) => {
    if (event.key !== 'Escape' || !open.value) return;

    const hadFocus = rootRef.value?.contains(document.activeElement);
    close();
    if (hadFocus) buttonRef.value?.focus();
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
        note: 'Redirecting to the login page…',
    });

    // A short hold so the message can be read, then the splash stays up
    // until the login page has rendered underneath it.
    window.setTimeout(() => {
        router.post('/logout', {}, { onFinish: () => hideSplash(500) });
    }, 700);
};

const itemClass =
    'flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-text hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring';

// Groups are separated by a rule; only Management, the one group whose
// purpose isn't obvious from its links (and admins-only), gets a heading.
const groupClass = 'border-b border-border py-1';
const headingClass = 'px-3 pt-1.5 pb-1 text-xs font-semibold uppercase tracking-wide text-text-muted';
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            ref="buttonRef"
            type="button"
            class="group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :aria-label="`Account menu for ${user.name}`"
            :aria-expanded="open"
            aria-controls="user-menu-panel"
            @click="toggle"
        >
            <UserAvatar :name="user.name" :photo-url="user.profile_photo_url" highlight />
            <!-- Name over position, shown in full. -->
            <span class="hidden flex-col whitespace-nowrap text-left leading-tight sm:flex">
                <span class="font-semibold text-text">{{ user.name }}</span>
                <span v-if="user.position" class="text-xs text-text-muted">{{ user.position }}</span>
            </span>
            <Icon name="chevronDown" size="sm" class="shrink-0 text-text-muted" />
        </button>

        <div
            v-if="open"
            id="user-menu-panel"
            class="absolute right-0 z-50 mt-2 w-64 rounded-md border border-border bg-surface py-1 shadow-lg"
        >
            <div class="border-b border-border px-3 py-2">
                <p class="text-sm font-semibold break-words text-text">{{ user.name }}</p>
                <p v-if="user.position" class="text-xs break-words text-text-muted">{{ user.position }}</p>
                <p class="text-xs break-all text-text-muted">@{{ user.username }} · {{ user.email }}</p>
                <p v-if="user.previous_login_at" class="mt-1 text-xs text-text-muted">
                    Last login: <time :datetime="user.previous_login_at">{{ formatDateTime(user.previous_login_at) }}</time>
                </p>
            </div>

            <div v-if="canAccessHr || canAccessAdmin" role="group" aria-labelledby="user-menu-management" :class="groupClass">
                <p id="user-menu-management" :class="headingClass">Management</p>
                <Link v-if="canAccessHr" href="/admin/hr" :class="itemClass" @click="close">
                    <Icon name="clipboard" size="sm" />
                    HR Management
                </Link>
                <Link v-if="canAccessAdmin" href="/admin" :class="itemClass" @click="close">
                    <Icon name="shield" size="sm" />
                    Administration
                </Link>
            </div>

            <div role="group" aria-label="My account" :class="groupClass">
                <Link href="/account/password" :class="itemClass" @click="close">
                    <Icon name="lock" size="sm" />
                    Change password
                </Link>
                <Link href="/my/record-access" :class="itemClass" @click="close">
                    <Icon name="eye" size="sm" />
                    Who accessed my record
                </Link>
            </div>

            <div role="group" aria-label="About IMIS" :class="groupClass">
                <Link href="/whats-new" :class="itemClass" @click="close">
                    <Icon name="megaphone" size="sm" />
                    What's New
                </Link>
                <Link href="/privacy" :class="itemClass" @click="close">
                    <Icon name="documentText" size="sm" />
                    Privacy notice
                </Link>
                <button type="button" :class="itemClass" @click="openAbout">
                    <Icon name="info" size="sm" />
                    About IMIS
                </button>
            </div>

            <div class="pt-1">
                <button type="button" :class="itemClass" @click="requestLogout">
                    <Icon name="logout" size="sm" />
                    Sign out
                </button>
            </div>
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
