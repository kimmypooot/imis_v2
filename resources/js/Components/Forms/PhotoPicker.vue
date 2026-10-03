<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue';
import Icon from '../UI/Icon.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import { toast } from '../../Composables/useDialog';

/**
 * The legacy profile-picture upload: a round photo with a camera button.
 * Picking a file shows it immediately (a local preview) and emits it; the
 * server re-encodes it as a centered square (ProfilePhotos), so what you
 * see here is what gets saved.
 */
const props = defineProps({
    name: { type: String, required: true },
    photoUrl: { type: String, default: null },
    error: { type: String, default: null },
    maxBytes: { type: Number, default: 5 * 1024 * 1024 },
});

const emit = defineEmits(['select']);

const id = useId();
const inputRef = ref(null);
const preview = ref(null);

const clearPreview = () => {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
};

const onChange = (event) => {
    const [file] = event.target.files ?? [];
    event.target.value = '';
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        toast.error('Please choose an image file (JPG, PNG, or WebP).');

        return;
    }
    if (file.size > props.maxBytes) {
        toast.error('Please choose an image under 5 MB.');

        return;
    }

    clearPreview();
    preview.value = URL.createObjectURL(file);
    emit('select', file);
};

// A saved photo replaces the local preview.
watch(() => props.photoUrl, clearPreview);
onBeforeUnmount(clearPreview);

defineExpose({ reset: clearPreview });
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <div class="relative">
            <UserAvatar :name="name || '?'" :photo-url="preview || photoUrl" size="xl" />
            <button
                type="button"
                class="absolute right-0 bottom-0 flex h-8 w-8 items-center justify-center rounded-full bg-primary text-white shadow ring-2 ring-surface hover:bg-primary-hover focus:outline-none focus-visible:ring-focus-ring"
                :aria-label="photoUrl || preview ? 'Change profile photo' : 'Add a profile photo'"
                :aria-describedby="error ? `${id}-error` : undefined"
                @click="inputRef.click()"
            >
                <Icon name="camera" size="sm" />
            </button>
        </div>
        <input :id="id" ref="inputRef" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" @change="onChange" />
        <p class="text-xs text-text-muted">JPG, PNG, or WebP · up to 5 MB</p>
        <p v-if="error" :id="`${id}-error`" class="text-xs text-danger">{{ error }}</p>
        <slot />
    </div>
</template>
