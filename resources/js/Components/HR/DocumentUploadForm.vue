<script setup>
import FileInput from '../Forms/FileInput.vue';
import InputLabel from '../Forms/InputLabel.vue';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '../UI/Button.vue';
import Select from '../Forms/Select.vue';
import TextInput from '../Forms/TextInput.vue';
import Textarea from '../Forms/Textarea.vue';
import InputError from '../Forms/InputError.vue';

/**
 * HR adds a document to an employee's 201 file: what it is, its date,
 * and the scanned file (PDF, JPG or PNG).
 */
const props = defineProps({
    url: { type: String, required: true },
    typeOptions: { type: Array, required: true },
    maxKilobytes: { type: Number, required: true },
});

const form = useForm({ document_type: '', title: '', document_date: '', remarks: '', file: null });

const submit = () =>
    form
        .transform((data) => ({ ...data, title: data.title || null, document_date: data.document_date || null, remarks: data.remarks || null }))
        .post(props.url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
            },
        });
</script>

<template>
    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
        <div>
            <InputLabel for="doc-type" required>Document</InputLabel>
            <Select id="doc-type" v-model="form.document_type" class="mt-1" :options="typeOptions" placeholder="— Select —" required :invalid="!!form.errors.document_type" />
            <InputError :message="form.errors.document_type" />
        </div>
        <div>
            <TextInput id="doc-date" v-model="form.document_date" type="date" label="Document date" :error="form.errors.document_date" />
        </div>
        <div class="sm:col-span-2">
            <TextInput id="doc-title" v-model="form.title" :label="form.document_type === 'other' ? 'Title (what is it?)' : 'Title (optional)'" :required="form.document_type === 'other'" :error="form.errors.title" />
        </div>
        <div class="sm:col-span-2">
            <InputLabel for="doc-remarks">Remarks</InputLabel>
            <Textarea id="doc-remarks" v-model="form.remarks" class="mt-1" :rows="2" :invalid="!!form.errors.remarks" />
            <InputError :message="form.errors.remarks" />
        </div>
        <div class="sm:col-span-2">
            <InputLabel for="doc-file" required>File</InputLabel>
            <FileInput
                id="doc-file"
                v-model="form.file"
                class="mt-1"
                accept="application/pdf,image/jpeg,image/png"
                required
                :max-size-mb="Math.round(maxKilobytes / 1024)"
                :invalid="!!form.errors.file"
                :hint="`PDF, JPG or PNG, up to ${Math.round(maxKilobytes / 1024)} MB. Stored privately.`"
            />
            <InputError :message="form.errors.file" />
        </div>
        <div class="flex justify-end sm:col-span-2">
            <Button type="submit" variant="success" :loading="form.processing" :disabled="!form.file || !form.document_type">Add to 201 file</Button>
        </div>
    </form>
</template>
