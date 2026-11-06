<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Dropdown from '../UI/Dropdown.vue';
import Icon from '../UI/Icon.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import { firstName, useSplash } from '../../Composables/useSplash';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The account menu: who you are and when you last signed in, your account
 * and feedback links, and
 * Sign out (confirmed, then the "Signing Out" splash carries you to the
 * login page). HR Management and Administration live in the sidebar.
 */
const props = defineProps({
    user: {
        type: Object,
        required: true,
        /** { name, username, email, position, profile_photo_url, previous_login_at } */
    },
});

const emit = defineEmits(['feedback']);

const { show: showSplash, hide: hideSplash } = useSplash();

const openFeedback = (close) => {
    close();
    emit('feedback');
};

const confirmingLogout = ref(false);

const requestLogout = (close) => {
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
    'flex w-full items-center gap-2 px-3 py-2 text-left text-compact text-text hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring';

const groupClass = 'border-b border-border py-1';
</script>

<template>
    <div>
        <Dropdown panel-class="w-64 py-1">
            <template #trigger="{ open, toggle, setTrigger, panelId }">
                <button
                    :ref="setTrigger"
                    type="button"
                    class="group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :aria-label="`Account menu for ${user.name}`"
                    :aria-expanded="open"
                    :aria-controls="panelId"
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
            </template>

            <template #default="{ close }">
            <div class="border-b border-border px-3 py-2">
                <!-- The trigger already shows name and position from sm up. -->
                <div class="sm:hidden">
                    <p class="text-sm font-semibold break-words text-text">{{ user.name }}</p>
                    <p v-if="user.position" class="text-xs break-words text-text-muted">{{ user.position }}</p>
                </div>
                <p class="text-xs break-all text-text-muted">@{{ user.username }} · {{ user.email }}</p>
                <p v-if="user.previous_login_at" class="text-xs text-text-muted">
                    Last login: <time :datetime="user.previous_login_at">{{ formatDateTime(user.previous_login_at) }}</time>
                </p>
            </div>

            <div role="group" aria-label="My account" :class="groupClass">
                <Link href="/account" :class="itemClass" @click="close">
                    <Icon name="cog" size="sm" />
                    Account settings
                </Link>
                <button type="button" :class="itemClass" @click="openFeedback(close)">
                    <Icon name="chat" size="sm" />
                    Send feedback
                </button>
            </div>

            <div class="pt-1">
                <button type="button" :class="itemClass" @click="requestLogout(close)">
                    <Icon name="logout" size="sm" />
                    Sign out
                </button>
            </div>
            </template>
        </Dropdown>

        <Modal :open="confirmingLogout" title="Sign out of IMIS?" icon="logout" size="sm" @close="confirmingLogout = false">
            <div class="flex flex-col items-center text-center">
                <div class="relative rounded-full">
                    <span class="absolute -inset-2 rounded-full bg-primary/30 blur-md motion-safe:animate-breathe" aria-hidden="true" />
                    <span class="relative flex rounded-full">
                        <UserAvatar :name="user.name" :photo-url="user.profile_photo_url" size="xl" />
                        <span class="pointer-events-none absolute inset-0 rounded-full border border-primary" aria-hidden="true" />
                    </span>
                    <span class="absolute -right-1 -bottom-1 flex h-8 w-8 items-center justify-center rounded-full bg-primary text-white ring-4 ring-surface">
                        <Icon name="logout" size="sm" />
                    </span>
                </div>
                <p class="mt-4 text-base font-semibold break-words text-text">{{ user.name }}</p>
                <p v-if="user.position" class="text-xs break-words text-text-muted">{{ user.position }}</p>
                <p class="mt-4 text-sm text-text-muted">
                    Leaving already, {{ firstName(user.name) }}? Your work is saved and your session will end on this device.
                </p>
            </div>

            <template #footer>
                <div class="flex w-full flex-col gap-3">
                    <Button variant="danger" class="w-full justify-center" @click="logout">
                        <Icon name="logout" size="sm" />
                        Sign out
                    </Button>
                    <Button variant="primary" class="w-full justify-center" data-autofocus @click="confirmingLogout = false">
                        Stay signed in
                    </Button>
                </div>
            </template>
        </Modal>
    </div>
</template>
