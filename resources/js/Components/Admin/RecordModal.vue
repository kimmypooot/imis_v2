<script setup>
import { computed, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import SchemaForm from './SchemaForm.vue';

/**
 * The legacy add/edit modal for a simple record: green "Add …" header when
 * `record` is null, blue "Edit …" otherwise, fields from a schema, saved
 * to `storeUrl` (POST) or `updateUrl` (PUT). Empty values of `nullable`
 * keys are sent as null. Stays open on validation errors; closes on save.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    noun: { type: String, required: true /** e.g. "Office" */ },
    record: { type: Object, default: null },
    fields: { type: Array, required: true },
    defaults: { type: Object, required: true },
    storeUrl: { type: String, required: true },
    updateUrl: { type: Function, required: true /** (record) => url */ },
    nullable: { type: Array, default: () => [] },
    canSave: { type: Boolean, default: true },
    size: { type: String, default: 'lg' },
});

const emit = defineEmits(['close']);

const id = useId();
const isNew = computed(() => props.record === null);

const valuesFor = (record) =>
    Object.fromEntries(Object.entries(props.defaults).map(([key, fallback]) => [key, record?.[key] ?? fallback]));

const form = useForm(valuesFor(props.record));

watch(
    () => [props.open, props.record?.id],
    ([open]) => {
        if (!open) return;
        form.defaults(valuesFor(props.record));
        form.reset();
        form.clearErrors();
    },
);

const close = () => emit('close');

const submit = () => {
    form.transform((data) =>
        Object.fromEntries(Object.entries(data).map(([key, value]) => [key, props.nullable.includes(key) && value === '' ? null : value])),
    );
    const options = { preserveScroll: true, onSuccess: close };

    if (isNew.value) form.post(props.storeUrl, options);
    else form.put(props.updateUrl(props.record), options);
};
</script>

<template>
    <Modal
        :open="open"
        :title="isNew ? `Add New ${noun}` : `Edit ${noun} — ${record.name}`"
        :tone="isNew ? 'success' : 'primary'"
        :icon="isNew ? 'plus' : 'pencil'"
        :size="size"
        @close="close"
    >
        <form :id="`${id}-form`" @submit.prevent="submit">
            <SchemaForm :form="form" :fields="fields" :id-prefix="id" :disabled="!canSave" />
            <slot :form="form" />
        </form>

        <template #footer>
            <Button variant="secondary" @click="close">Cancel</Button>
            <Button
                v-if="canSave"
                type="submit"
                :form="`${id}-form`"
                :variant="isNew ? 'success' : 'primary'"
                :loading="form.processing"
                :disabled="!isNew && !form.isDirty"
            >
                {{ isNew ? `Add ${noun}` : 'Save changes' }}
            </Button>
        </template>
    </Modal>
</template>
