<script setup>
import { computed, ref } from 'vue';
import Icon from '../UI/Icon.vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'info',
        validator: (value) => ['success', 'warning', 'danger', 'info'].includes(value),
    },
    title: {
        type: String,
        default: null,
    },
    // Overrides the tone's default icon.
    icon: {
        type: String,
        default: null,
    },
    dismissible: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['dismiss']);

const visible = ref(true);

const dismiss = () => {
    visible.value = false;
    emit('dismiss');
};

// `chip` tints the icon badge, `panel` the surface. Matches the toasts in DialogHost.
const toneMeta = {
    success: { icon: 'checkCircle', chip: 'bg-success/15 text-success-fg', panel: 'bg-success-bg border-success-border' },
    warning: { icon: 'exclamation', chip: 'bg-warning/15 text-warning-fg', panel: 'bg-warning-bg border-warning-border' },
    danger: { icon: 'xCircle', chip: 'bg-danger/15 text-danger-fg', panel: 'bg-danger-bg border-danger-border' },
    info: { icon: 'info', chip: 'bg-info/15 text-info-fg', panel: 'bg-info-bg border-info-border' },
};

const meta = computed(() => toneMeta[props.tone]);
</script>

<template>
    <div
        v-if="visible"
        role="alert"
        class="flex items-start gap-3 rounded-xl border p-3.5 text-sm"
        :class="meta.panel"
    >
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="meta.chip" aria-hidden="true">
            <Icon :name="icon ?? meta.icon" size="sm" />
        </span>

        <div class="min-w-0 flex-1 self-center text-text">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div :class="title ? 'mt-0.5 text-text-muted' : ''"><slot /></div>
        </div>

        <button
            v-if="dismissible"
            type="button"
            class="shrink-0 rounded-md p-1 text-text-muted hover:bg-black/5 hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            aria-label="Dismiss"
            @click="dismiss"
        >
            <Icon name="close" size="sm" />
        </button>
    </div>
</template>
