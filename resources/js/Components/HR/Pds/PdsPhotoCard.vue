<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import Spinner from '@cscro8/ui/Components/UI/Spinner.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import PdsPhotoCropper from './PdsPhotoCropper.vue';

/**
 * The PDS photo (passport size, 3.5 × 4.5 cm). It must be exactly 413 × 531
 * pixels (3.5 × 4.5 cm at 300 dpi, PdsPhotos::WIDTH/HEIGHT), so it prints at
 * its true size without cropping. The size is checked here before
 * uploading: a picture of another size opens the cropper (PdsPhotoCropper),
 * which saves it at exactly that size. The server checks again. Stored
 * privately; `url` is the upload/remove endpoint.
 */
const props = defineProps({
    photoUrl: { type: String, default: null },
    url: { type: String, required: true },
    readonly: { type: Boolean, default: false },
});

const input = ref(null);
const form = useForm({ photo: null });

const dragging = ref(false);

const SIZE = { width: 413, height: 531 };

/** The picture's size in pixels, or null when it can't be read as an image. */
const dimensionsOf = (file) =>
    new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            resolve({ width: image.naturalWidth, height: image.naturalHeight });
            URL.revokeObjectURL(url);
        };
        image.onerror = () => {
            resolve(null);
            URL.revokeObjectURL(url);
        };
        image.src = url;
    });

const send = async (file) => {
    form.clearErrors();
    if (!file) return;

    const size = await dimensionsOf(file);
    if (!size) {
        form.setError('photo', 'That file could not be read as a picture. Use a JPG, PNG or WebP image.');
        if (input.value) input.value.value = '';
        return;
    }
    if (size.width !== SIZE.width || size.height !== SIZE.height) {
        // Not passport size: say so, and offer to crop it to size here.
        cropping.value = {
            file,
            message: `The photo must be exactly ${SIZE.width} × ${SIZE.height} pixels (passport size, 3.5 × 4.5 cm at 300 dpi). This one is ${size.width} × ${size.height}, so crop it to size below, or choose another photo.`,
        };
        if (input.value) input.value.value = '';
        return;
    }

    post(file);
};

/** The cropper for a picture that isn't passport size: { file, message }, or null. */
const cropping = ref(null);
const onCropped = (file) => {
    cropping.value = null;
    post(file);
};

const post = (file) => {
    form.photo = file;
    form.post(props.url, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            form.reset();
            if (input.value) input.value.value = '';
        },
    });
};

const upload = (event) => send(event.target.files[0]);

const onDrop = (event) => {
    dragging.value = false;
    if (readonlyOrBusy()) return;
    send(event.dataTransfer?.files?.[0]);
};

const readonlyOrBusy = () => props.readonly || form.processing;

const remove = async () => {
    if (await confirm({ title: 'Remove the PDS photo?', text: 'Your signed submissions keep their own copy.', tone: 'danger', confirmText: 'Remove' })) {
        router.delete(props.url, { preserveScroll: true });
    }
};
</script>

<template>
    <Card title="Photo" icon="camera" description="Passport size (3.5 × 4.5 cm), taken within the last 6 months.">
        <!-- Two equal halves: the photo (Remove under it) centred on the left, the guidance on the right; Replace across the card. -->
        <div class="grid gap-4 sm:grid-cols-2 sm:items-center">
            <div class="mx-auto w-36 space-y-2">
                <div
                    class="relative flex aspect-413/531 w-full items-center justify-center overflow-hidden rounded-md border-2 transition-colors duration-150 ease-standard motion-reduce:transition-none"
                    :class="[dragging ? 'border-primary bg-info-bg' : photoUrl ? 'border-border' : 'border-dashed border-border-strong bg-surface-muted']"
                    @dragover.prevent="!readonly && (dragging = true)"
                    @dragleave="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <img v-if="photoUrl" :src="photoUrl" alt="PDS photo" class="h-full w-full object-cover" />
                    <span v-else class="flex flex-col items-center gap-1 px-2 text-center text-xs text-text-muted">
                        <Icon name="camera" size="lg" />
                        {{ readonly ? 'No photo' : dragging ? 'Drop to upload' : 'Drop a photo here' }}
                    </span>
                    <span v-if="form.processing" class="absolute inset-0 flex items-center justify-center bg-surface/80">
                        <Spinner label="Uploading photo…" />
                    </span>
                </div>

                <Button v-if="!readonly && photoUrl" variant="ghost" size="sm" icon="trash" class="w-full text-danger-fg" @click="remove">Remove</Button>
            </div>

            <!-- Centred on the photo itself: with a photo, the bottom padding offsets the Remove button under it. -->
            <div v-if="!readonly" class="min-w-0 space-y-2 text-center" :class="{ 'sm:pb-11': photoUrl }">
                <p class="text-sm text-text-muted">
                    <span class="block font-medium text-text">Passport size</span>
                    <span class="block">413 × 531 pixels (3.5 × 4.5 cm).</span>
                    <span class="mt-2 block">Any other size can be cropped to fit. JPG, PNG or WebP; you can also drop it on the frame.</span>
                </p>
                <InputError :message="form.errors.photo" />
            </div>
        </div>

        <!-- The input sits inside its label, so the label shows its keyboard focus. -->
        <label
            v-if="!readonly"
            class="mt-4 flex w-full cursor-pointer items-center justify-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-primary-hover focus-within:ring-2 focus-within:ring-focus-ring focus-within:ring-offset-2"
            :class="{ 'pointer-events-none opacity-60': form.processing }"
        >
            <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="form.processing" @change="upload" />
            <Icon name="upload" size="sm" />
            {{ form.processing ? 'Uploading…' : photoUrl ? 'Replace photo' : 'Upload photo' }}
        </label>

        <PdsPhotoCropper
            :open="!!cropping"
            :file="cropping?.file ?? null"
            :size="SIZE"
            :message="cropping?.message ?? null"
            @close="cropping = null"
            @crop="onCropped"
        />
    </Card>
</template>
