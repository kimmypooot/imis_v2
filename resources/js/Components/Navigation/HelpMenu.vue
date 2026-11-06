<script setup>
import { Link } from '@inertiajs/vue3';
import Dropdown from '../UI/Dropdown.vue';
import Icon from '../UI/Icon.vue';

/**
 * The top-bar help button, beside the bell: a small dropdown to the User
 * Guide, What's New and Send feedback. "Send feedback" opens a dialog that
 * belongs to the shell, so it is emitted rather than handled here.
 */
const emit = defineEmits(['feedback']);

const itemClass =
    'flex w-full items-start gap-3 px-3 py-2.5 text-left hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring';

const sendFeedback = (close) => {
    close();
    emit('feedback');
};
</script>

<template>
    <Dropdown panel-class="w-72 overflow-hidden">
        <template #trigger="{ open, toggle, setTrigger, panelId }">
            <button
                :ref="setTrigger"
                type="button"
                class="rounded-md p-2 text-text-muted hover:bg-surface-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="{ 'bg-surface-muted text-text': open }"
                aria-label="Help"
                aria-haspopup="true"
                :aria-expanded="open"
                :aria-controls="panelId"
                @click="toggle"
            >
                <Icon name="help" size="md" />
            </button>
        </template>

        <template #default="{ close }">
            <p class="border-b border-border px-3 py-2 text-sm font-semibold text-text">Help</p>

            <ul class="divide-y divide-border">
                <li>
                    <Link href="/guide" :class="itemClass" @click="close">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                            <Icon name="documentText" size="sm" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-text">User guide</span>
                            <span class="block text-xs text-text-muted">Step-by-step help for common tasks</span>
                        </span>
                    </Link>
                </li>
                <li>
                    <Link href="/whats-new" :class="itemClass" @click="close">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                            <Icon name="megaphone" size="sm" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-text">What's new</span>
                            <span class="block text-xs text-text-muted">Release notes and recent changes</span>
                        </span>
                    </Link>
                </li>
                <li>
                    <button type="button" :class="itemClass" @click="sendFeedback(close)">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                            <Icon name="chat" size="sm" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-text">Send feedback</span>
                            <span class="block text-xs text-text-muted">Report a problem or suggest an idea</span>
                        </span>
                    </button>
                </li>
            </ul>
        </template>
    </Dropdown>
</template>
