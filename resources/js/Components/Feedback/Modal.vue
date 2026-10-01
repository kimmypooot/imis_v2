<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../UI/Icon.vue';

/**
 * Generic accessible dialog. Covers the confirmation/form/information use
 * cases via slots — it does not know what content it holds.
 */
const props = defineProps({
    open: {
        type: Boolean,
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value),
    },
});

const emit = defineEmits(['close']);

const dialogRef = ref(null);
let triggerElement = null;

const sizeClasses = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-2xl', xl: 'max-w-4xl' };

const close = () => emit('close');

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        close();
    }
};

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            triggerElement = document.activeElement;
            await nextTick();
            dialogRef.value?.querySelector('[data-autofocus]')?.focus();
            dialogRef.value?.focus();
            document.addEventListener('keydown', onKeydown);
            document.body.style.overflow = 'hidden';
        } else {
            document.removeEventListener('keydown', onKeydown);
            document.body.style.overflow = '';
            triggerElement?.focus?.();
        }
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-text/50" aria-hidden="true" @click="close" />

            <div
                ref="dialogRef"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="`modal-title`"
                tabindex="-1"
                class="relative max-h-[90dvh] w-full overflow-y-auto rounded-lg bg-surface p-6 shadow-lg focus:outline-none"
                :class="sizeClasses[size]"
            >
                <div class="mb-4 flex items-start justify-between gap-4">
                    <h2 id="modal-title" class="text-base font-semibold text-text">{{ title }}</h2>
                    <button
                        type="button"
                        class="shrink-0 rounded-md p-1 text-text-muted hover:bg-surface-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        aria-label="Close dialog"
                        @click="close"
                    >
                        <Icon name="close" size="sm" />
                    </button>
                </div>

                <div>
                    <slot />
                </div>

                <div v-if="$slots.footer" class="mt-6 flex justify-end gap-3">
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
