<script setup>
import { useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PdsFields from './PdsFields.vue';

/**
 * One single-value PDS section (personal information, family names,
 * items 34–40, government ID), saved on its own to `{url}/{section.key}`.
 * The editor saves it with the rest of its part (`save()`); it reports its
 * unsaved state so the editor can mark the part.
 */
const props = defineProps({
    section: { type: Object, required: true },
    values: { type: Object, required: true },
    url: { type: String, default: null },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['dirty', 'submit']);

const id = useId();
const form = useForm({ ...props.values });

// New saved values (an Excel import, or HR's save elsewhere) replace the form,
// unless there are unsaved edits in it, which are kept.
watch(
    () => props.values,
    (values) => {
        const keepEdits = form.isDirty;
        const edits = form.data();

        form.defaults({ ...values });
        keepEdits ? form.setData(edits) : form.reset();
    },
);

watch(
    () => form.isDirty,
    (dirty) => emit('dirty', props.section.key, dirty),
);

/** Saves the section; resolves true when saved, false on validation errors. */
const save = () =>
    new Promise((resolve) => {
        form.put(`${props.url}/${props.section.key}`, {
            preserveScroll: true,
            // The saved values become the new baseline for "unsaved changes".
            onSuccess: () => {
                form.defaults({ ...form.data() });
                resolve(true);
            },
            onError: () => resolve(false),
            onCancel: () => resolve(false),
        });
    });

defineExpose({ save, isDirty: () => form.isDirty });
</script>

<template>
    <section :aria-labelledby="`${id}-title`" class="rounded-lg border border-border p-4">
        <h3 :id="`${id}-title`" class="text-sm font-semibold text-text">{{ section.label }}</h3>
        <p v-if="section.help" class="mt-0.5 text-xs text-text-muted">{{ section.help }}</p>

        <form class="mt-4" @submit.prevent="emit('submit')">
            <PdsFields :fields="section.fields" :values="form" :errors="form.errors" :id-prefix="`${id}-${section.key}`" :disabled="readonly" />
        </form>
    </section>
</template>
