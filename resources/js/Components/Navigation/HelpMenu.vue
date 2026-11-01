<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';

/**
 * The top-bar help button, beside the bell: a small dropdown to the User
 * Guide, What's New and Send feedback. "Send feedback" opens a dialog that
 * belongs to the shell, so it is emitted rather than handled here.
 */
const emit = defineEmits(['feedback']);

const open = ref(false);
const rootRef = ref(null);
const buttonRef = ref(null);

const close = () => {
    open.value = false;
};

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) close();
};

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

const itemClass =
    'flex w-full items-start gap-3 px-3 py-2.5 text-left hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring';

const sendFeedback = () => {
    close();
    emit('feedback');
};
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            ref="buttonRef"
            type="button"
            class="rounded-md p-2 text-text-muted hover:bg-surface-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :class="{ 'bg-surface-muted text-text': open }"
            aria-label="Help"
            aria-haspopup="true"
            :aria-expanded="open"
            aria-controls="help-menu-panel"
            @click="open = !open"
        >
            <Icon name="help" size="md" />
        </button>

        <div
            v-if="open"
            id="help-menu-panel"
            class="absolute right-0 z-50 mt-2 w-72 overflow-hidden rounded-md border border-border bg-surface shadow-lg"
        >
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
                    <button type="button" :class="itemClass" @click="sendFeedback">
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
        </div>
    </div>
</template>
