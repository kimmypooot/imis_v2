<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import FormGroup from '@cscro8/ui/Components/Forms/FormGroup.vue';
import Textarea from '@cscro8/ui/Components/Forms/Textarea.vue';

/**
 * Calendar of Activities: an action that needs a reason or note: cancel
 * (`reason`), PALD's return to the office (`note`) and remove (`reason`,
 * sent as a DELETE).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    // The URL the reason is sent to.
    url: { type: String, default: null },
    // The field the server expects: reason or note.
    field: { type: String, default: 'reason' },
    label: { type: String, default: 'Reason' },
    helperText: { type: String, default: null },
    submitLabel: { type: String, required: true },
    method: { type: String, default: 'post', validator: (value) => ['post', 'delete'].includes(value) },
    danger: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const form = useForm({ text: '' });

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);

const submit = () => {
    // Laravel reads a spoofed DELETE from a POST body, so the reason always arrives.
    form.transform((data) => ({ [props.field]: data.text, ...(props.method === 'delete' ? { _method: 'delete' } : {}) }))
        .post(props.url, { preserveScroll: true, onSuccess: () => emit('close') });
};
</script>

<template>
    <Modal :open="open" :title="title" :tone="danger ? 'danger' : 'primary'" size="md" @close="emit('close')">
        <form id="activity-reason-form" novalidate @submit.prevent="submit">
            <FormGroup id="activity-reason" :label="label" required :helper-text="helperText" :error="form.errors[field] || form.errors.activity">
                <Textarea id="activity-reason" v-model="form.text" :rows="3" :maxlength="255" required data-autofocus :invalid="!!(form.errors[field] || form.errors.activity)" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="secondary" @click="emit('close')">Back</Button>
            <Button type="submit" form="activity-reason-form" :variant="danger ? 'danger' : 'primary'" :loading="form.processing">{{ submitLabel }}</Button>
        </template>
    </Modal>
</template>
