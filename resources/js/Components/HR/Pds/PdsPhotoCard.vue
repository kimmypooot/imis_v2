<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Card from '../../UI/Card.vue';
import Button from '../../UI/Button.vue';
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

const upload = (event) => {
    form.photo = event.target.files[0] ?? null;
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

const remove = async () => {
    if (await confirm({ title: 'Remove the PDS photo?', text: 'Your signed submissions keep their own copy.', tone: 'danger', confirmText: 'Remove' })) {
        router.delete(props.url, { preserveScroll: true });
    }
};
</script>

<template>
    <Card title="Photo" icon="camera" description="Passport size (3.5 × 4.5 cm), taken within the last 6 months.">
        <div class="flex items-end gap-4">
            <div class="flex h-36 w-28 shrink-0 items-center justify-center overflow-hidden rounded-sm border border-border bg-surface-muted">
                <img v-if="photoUrl" :src="photoUrl" alt="PDS photo" class="h-full w-full object-cover" />
                <span v-else class="px-2 text-center text-xs text-text-muted">No photo</span>
            </div>
            <div v-if="!readonly" class="space-y-2">
                <!-- The input sits inside its label, so the label shows its keyboard focus. -->
                <label
                    class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus-within:ring-2 focus-within:ring-focus-ring"
                >
                    <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="form.processing" @change="upload" />
                    {{ form.processing ? 'Uploading…' : photoUrl ? 'Replace photo' : 'Upload photo' }}
                </label>
                <Button v-if="photoUrl" variant="link" class="block text-danger" @click="remove">Remove</Button>
                <InputError :message="form.errors.photo" />
            </div>
        </div>
    </Card>
</template>
