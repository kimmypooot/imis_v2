<script setup>
import { useForm } from '@inertiajs/vue3';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import FileInput from '@cscro8/ui/Components/Forms/FileInput.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';

/**
 * Uploading the signed and sworn SALN once it is cleared. It goes to the
 * 201 File and closes the SALN.
 */
const props = defineProps({
    url: { type: String, required: true },
    maxKilobytes: { type: Number, required: true },
    reference: { type: String, required: true },
});

const form = useForm({ file: null });
const submit = () => form.post(props.url, { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset() });
</script>

<template>
    <Card title="Upload the signed copy" icon="upload" :description="`Once ${reference} is signed and sworn before the administering officer (or notarized), scan every page into one file.`">
        <form class="space-y-3" @submit.prevent="submit">
            <InputLabel for="saln-scan">Signed SALN</InputLabel>
            <FileInput
                id="saln-scan"
                v-model="form.file"
                accept="application/pdf,image/jpeg,image/png"
                required
                :max-size-mb="Math.round(maxKilobytes / 1024)"
                :invalid="!!form.errors.file"
                :hint="`PDF, JPG or PNG, up to ${Math.round(maxKilobytes / 1024)} MB. Filed privately in the 201 File.`"
            />
            <InputError :message="form.errors.file" />
            <Button type="submit" variant="success" :loading="form.processing">File in the 201 File</Button>
        </form>
    </Card>
</template>
