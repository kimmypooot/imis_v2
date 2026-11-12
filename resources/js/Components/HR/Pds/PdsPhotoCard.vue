<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Card from '../../UI/Card.vue';
import Button from '../../UI/Button.vue';
import Icon from '../../UI/Icon.vue';
import Spinner from '../../UI/Spinner.vue';
import InputError from '../../Forms/InputError.vue';
import { confirm } from '../../../Composables/useDialog';

/**
 * The PDS photo (passport size). Uploads are cropped to 3.5 × 4.5 cm on
 * the server and stored privately; `url` is the upload/remove endpoint.
 */
const props = defineProps({
    photoUrl: { type: String, default: null },
    url: { type: String, required: true },
    readonly: { type: Boolean, default: false },
});

const input = ref(null);
const form = useForm({ photo: null });

const dragging = ref(false);

const send = (file) => {
    form.photo = file ?? null;
    if (!form.photo) return;

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
        <div class="flex flex-wrap items-end gap-4">
            <div
                class="relative flex h-36 w-28 shrink-0 items-center justify-center overflow-hidden rounded-md border-2 transition-colors duration-150 ease-standard motion-reduce:transition-none"
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
            <div v-if="!readonly" class="space-y-2">
                <!-- The input sits inside its label, so the label shows its keyboard focus. -->
                <label
                    class="inline-flex cursor-pointer items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-primary-hover focus-within:ring-2 focus-within:ring-focus-ring focus-within:ring-offset-2"
                    :class="{ 'pointer-events-none opacity-60': form.processing }"
                >
                    <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="form.processing" @change="upload" />
                    <Icon name="upload" size="sm" />
                    {{ form.processing ? 'Uploading…' : photoUrl ? 'Replace photo' : 'Upload photo' }}
                </label>
                <p class="text-xs text-text-muted">JPG, PNG or WebP. You can also drop a photo on the frame.</p>
                <Button v-if="photoUrl" variant="link" class="block text-danger-fg" @click="remove">Remove</Button>
                <InputError :message="form.errors.photo" />
            </div>
        </div>
    </Card>
</template>
