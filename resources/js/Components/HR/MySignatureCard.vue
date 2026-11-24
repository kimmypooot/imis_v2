<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import SignaturePad from './SignaturePad.vue';

/**
 * Your saved e-signature: draw it or upload an image of it once, and it goes
 * on the leave forms you choose to sign. The server cleans it (clear
 * background, cropped) and keeps it private to you.
 */
const props = defineProps({
    // Where your saved signature is served from, or null when there is none.
    url: { type: String, default: null },
});

const mode = ref('draw');
const editing = ref(!props.url);
const drawn = ref(null);
const file = ref(null);
const fileInput = ref(null);

const form = useForm({ signature: null, file: null });
const ready = computed(() => (mode.value === 'draw' ? !!drawn.value : !!file.value));

const pick = (event) => {
    file.value = event.target.files?.[0] ?? null;
};

const save = () => {
    form.transform(() => (mode.value === 'draw' ? { signature: drawn.value } : { file: file.value }))
        .post('/my/signature', {
            preserveScroll: true,
            forceFormData: mode.value === 'upload',
            onSuccess: () => {
                editing.value = false;
                drawn.value = null;
                file.value = null;
            },
        });
};

const remove = async () => {
    if (await confirm({ title: 'Remove your signature?', text: 'Forms you already signed keep the signature they were signed with.', tone: 'danger', confirmText: 'Remove' })) {
        router.delete('/my/signature', { preserveScroll: true, onSuccess: () => { editing.value = true; } });
    }
};
</script>

<template>
    <Card title="My e-signature" icon="pencil" description="Saved once, then placed on the leave forms you choose to sign. Only you can see it here; each signed form keeps its own copy.">
        <div v-if="url && !editing" class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex h-24 min-w-[14rem] items-center justify-center rounded-md border border-border bg-white px-4">
                <img :src="url" alt="Your saved signature" class="max-h-20 max-w-full object-contain" />
            </div>
            <div class="flex gap-2">
                <Button variant="secondary" icon="pencil" @click="editing = true">Change</Button>
                <Button variant="danger" icon="close" @click="remove">Remove</Button>
            </div>
        </div>

        <form v-else class="space-y-4" @submit.prevent="save">
            <div class="inline-flex rounded-md border border-border p-0.5 text-sm" role="tablist" aria-label="How to add your signature">
                <button type="button" role="tab" :aria-selected="mode === 'draw'" class="rounded px-3 py-1.5 font-medium" :class="mode === 'draw' ? 'bg-primary text-white' : 'text-text-muted hover:text-text'" @click="mode = 'draw'">Draw</button>
                <button type="button" role="tab" :aria-selected="mode === 'upload'" class="rounded px-3 py-1.5 font-medium" :class="mode === 'upload' ? 'bg-primary text-white' : 'text-text-muted hover:text-text'" @click="mode = 'upload'">Upload an image</button>
            </div>

            <div v-if="mode === 'draw'">
                <SignaturePad v-model="drawn" />
                <InputError :message="form.errors.signature" />
            </div>
            <div v-else>
                <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-text file:mr-3 file:rounded-md file:border-0 file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-medium file:text-text hover:file:bg-border" @change="pick" />
                <p class="mt-1 text-xs text-text-muted">A photo or scan of your signature on white paper works best. The background is made clear for you. PNG, JPG or WebP, up to 4 MB.</p>
                <InputError :message="form.errors.file || form.errors.signature" />
            </div>

            <div class="flex justify-end gap-2">
                <Button v-if="url" variant="secondary" @click="editing = false">Cancel</Button>
                <Button type="submit" variant="success" icon="check" :loading="form.processing" :disabled="!ready">Save signature</Button>
            </div>
        </form>
    </Card>
</template>
