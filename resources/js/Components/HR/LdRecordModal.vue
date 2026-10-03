<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import LdTrainingFields from './LdTrainingFields.vue';
import InputError from '../Forms/InputError.vue';

/**
 * Add or edit one L&D training (ldms's "Add Training" modal), with an
 * optional certificate. Edits go as POST + _method=PUT so the file can
 * ride along. `record` null = adding.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    record: { type: Object, default: null },
    url: { type: String, required: true /** collection URL; record id is appended for edits */ },
    typeOptions: { type: Array, required: true },
    modalityOptions: { type: Array, required: true },
    certificateKilobytes: { type: Number, required: true },
    // HRD editing someone else's log (HR-3b): their edits don't resubmit.
    onBehalf: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const blank = {
    title: '', date_from: '', date_to: '', hours: '', ld_type: '', ld_type_other: '', modality: '',
    sponsor: '', venue: '', resource_speaker: '', certificate: null, remove_certificate: false,
};

const form = useForm({ ...blank });
const fileInput = ref(null);
const editing = computed(() => props.record !== null);

watch(
    () => [props.open, props.record?.id],
    ([open]) => {
        if (!open) return;
        const source = props.record ?? {};
        form.defaults(Object.fromEntries(Object.keys(blank).map((key) => [key, key === 'certificate' ? null : key === 'remove_certificate' ? false : (source[key] ?? '')])));
        form.reset();
        form.clearErrors();
        if (fileInput.value) fileInput.value.value = '';
    },
);

const submit = () => {
    const options = { preserveScroll: true, forceFormData: true, onSuccess: () => emit('close') };
    const data = (values) => ({ ...values, ...(editing.value ? { _method: 'put' } : {}) });

    form.transform(data).post(editing.value ? `${props.url}/${props.record.id}` : props.url, options);
};

</script>

<template>
    <Modal :open="open" :title="editing ? 'Edit training' : 'Add training'" :icon="editing ? 'pencil' : 'plus'" :tone="editing ? 'primary' : 'success'" size="lg" @close="emit('close')">
        <form id="ld-record-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <p v-if="record?.approval_status === 'rejected'" class="rounded-md border border-danger-border bg-danger-bg px-3 py-2 text-sm text-text sm:col-span-2">
                <strong class="text-danger">Rejected by HRD:</strong> {{ record.rejection_reason }}<br />
                <template v-if="onBehalf">Saving keeps it rejected — approve it from the log if it's now in order.</template>
                <template v-else>Saving your changes sends it back to HRD for review.</template>
            </p>

            <LdTrainingFields :form="form" :type-options="typeOptions" :modality-options="modalityOptions" />

            <div class="sm:col-span-2">
                <label for="ld-certificate" class="block text-sm font-medium text-text">Certificate (optional)</label>
                <p v-if="record?.certificate && !form.remove_certificate" class="mt-1 text-sm text-text">
                    Current: {{ record.certificate.name }}
                    <label class="ml-3 inline-flex items-center gap-1.5 text-danger">
                        <input v-model="form.remove_certificate" type="checkbox" class="h-4 w-4 rounded border-border text-danger focus:ring-2 focus:ring-focus-ring" />
                        Remove
                    </label>
                </p>
                <input
                    id="ld-certificate"
                    ref="fileInput"
                    type="file"
                    accept="application/pdf,image/jpeg,image/png"
                    aria-describedby="ld-certificate-help"
                    class="mt-1 block w-full text-sm text-text file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-surface-muted"
                    @change="form.certificate = $event.target.files[0] ?? null"
                />
                <p id="ld-certificate-help" class="mt-1 text-xs text-text-muted">
                    PDF, JPG or PNG, up to {{ Math.round(certificateKilobytes / 1024) }} MB. {{ record?.certificate ? 'Choosing a file replaces the current one.' : '' }}
                </p>
                <InputError :message="form.errors.certificate" />
            </div>
        </form>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="ld-record-form" :variant="editing ? 'primary' : 'success'" :loading="form.processing">
                {{ editing ? 'Save changes' : 'Add training' }}
            </Button>
        </template>
    </Modal>
</template>
