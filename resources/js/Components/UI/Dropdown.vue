<script setup>
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';

/**
 * The one popover/menu behavior for the app: a trigger and a floating
 * panel that opens under it. It closes on an outside click and on Escape
 * (handing focus back to the trigger if it was inside the panel), and
 * fades in. It knows nothing about what the panel holds.
 *
 * Slots:
 *  - `trigger` ({ open, toggle, close, setTrigger, panelId }): render the
 *    button yourself with `@click="toggle"`, `:aria-expanded="open"`,
 *    `:aria-controls="panelId"` and `:ref="setTrigger"` so focus can return
 *    to it after Escape.
 *  - default ({ close, panelId }): the panel content.
 *
 * `panelClass` sets the panel's width and any extras; it always gets the
 * shared menu surface (border, radius, `shadow-menu`).
 */
const props = defineProps({
    align: { type: String, default: 'right', validator: (value) => ['left', 'right'].includes(value) },
    panelClass: { type: String, default: 'w-64' },
});

const emit = defineEmits(['open', 'close']);

const open = ref(false);
const rootRef = ref(null);
const triggerRef = ref(null);
const panelId = `dropdown-${useId()}`;

const close = () => {
    if (!open.value) return;

    open.value = false;
    emit('close');
};

const toggle = () => {
    open.value = !open.value;
    emit(open.value ? 'open' : 'close');
};

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) close();
};

const onKeydown = (event) => {
    if (event.key !== 'Escape' || !open.value) return;

    const hadFocus = rootRef.value?.contains(document.activeElement);
    close();
    if (hadFocus) triggerRef.value?.focus();
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

defineExpose({ close, open });
</script>

<template>
    <div ref="rootRef" class="relative">
        <slot name="trigger" :open="open" :toggle="toggle" :close="close" :set-trigger="(el) => (triggerRef = el)" :panel-id="panelId" />

        <Transition
            enter-active-class="transition duration-150 ease-standard motion-reduce:transition-none"
            enter-from-class="-translate-y-1 opacity-0"
            leave-active-class="transition duration-100 ease-standard motion-reduce:transition-none"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                :id="panelId"
                class="absolute z-50 mt-2 max-h-[calc(100dvh-5rem)] overflow-y-auto rounded-lg border border-border bg-surface shadow-menu"
                :class="[props.align === 'right' ? 'right-0' : 'left-0', props.panelClass]"
            >
                <slot :close="close" :panel-id="panelId" />
            </div>
        </Transition>
    </div>
</template>
