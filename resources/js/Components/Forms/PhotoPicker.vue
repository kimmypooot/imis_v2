<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue';
import Icon from '../UI/Icon.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import Button from '../UI/Button.vue';
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

const dragging = ref(false);

const accept = (file) => {
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

const onChange = (event) => {
    const [file] = event.target.files ?? [];
    event.target.value = '';
    accept(file);
};

const onDrop = (event) => {
    dragging.value = false;
    accept(event.dataTransfer?.files?.[0]);
};

// A saved photo replaces the local preview.
watch(() => props.photoUrl, clearPreview);
onBeforeUnmount(clearPreview);

defineExpose({ reset: clearPreview });
</script>

<template>
    <div class="flex flex-col items-center gap-3">
        <div
            class="group relative rounded-full"
            @dragover.prevent="dragging = true"
            @dragenter.prevent="dragging = true"
            @dragleave="dragging = false"
            @drop.prevent="onDrop"
        >
            <button
                type="button"
                class="relative block rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                :aria-label="photoUrl || preview ? 'Change profile photo' : 'Add a profile photo'"
                :aria-describedby="error ? `${id}-error` : undefined"
                @click="inputRef.click()"
            >
                <UserAvatar :name="name || '?'" :photo-url="preview || photoUrl" size="xl" />
                <span
                    class="absolute inset-0 flex flex-col items-center justify-center gap-0.5 rounded-full text-2xs font-semibold text-white transition-opacity duration-150 ease-standard motion-reduce:transition-none"
                    :class="dragging ? 'bg-primary/85 opacity-100' : 'bg-black/55 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100'"
                    aria-hidden="true"
                >
                    <Icon :name="dragging ? 'download' : 'camera'" size="md" />
                    {{ dragging ? 'Drop photo' : 'Change' }}
                </span>
            </button>
            <span
                class="pointer-events-none absolute right-0 bottom-0 flex h-8 w-8 items-center justify-center rounded-full bg-primary text-white shadow-menu ring-2 ring-surface"
                aria-hidden="true"
            >
                <Icon name="camera" size="sm" />
            </span>
        </div>
        <input :id="id" ref="inputRef" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" @change="onChange" />
        <Button variant="outline" size="sm" @click="inputRef.click()">
            <Icon name="upload" size="sm" />
            {{ photoUrl || preview ? 'Change photo' : 'Choose photo' }}
        </Button>
        <p class="text-xs text-text-muted">or drag a photo onto the circle · JPG, PNG, or WebP · up to 5 MB</p>
        <p v-if="error" :id="`${id}-error`" class="flex items-center gap-1 text-xs text-danger-fg"><Icon name="exclamation" size="xs" />{{ error }}</p>
        <slot />
    </div>
</template>
