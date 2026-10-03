<script setup>
import { useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '../../UI/Button.vue';
import PdsFields from './PdsFields.vue';

/**
 * One single-value PDS section (personal information, family names,
 * items 34–40, government ID), saved on its own to `{url}/{section.key}`.
 * Reports its unsaved state so the editor can mark the tab.
 */
const props = defineProps({
    section: { type: Object, required: true },
    values: { type: Object, required: true },
    url: { type: String, required: true },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['dirty']);

const id = useId();
const form = useForm({ ...props.values });

watch(
    () => form.isDirty,
    (dirty) => emit('dirty', props.section.key, dirty),
);

const save = () =>
    form.put(`${props.url}/${props.section.key}`, {
        preserveScroll: true,
        // The saved values become the new baseline for "unsaved changes".
        onSuccess: () => form.defaults({ ...form.data() }),
    });
</script>

<template>
    <section :aria-labelledby="`${id}-title`" class="rounded-lg border border-border p-4">
        <h3 :id="`${id}-title`" class="text-sm font-semibold text-text">{{ section.label }}</h3>
        <p v-if="section.help" class="mt-0.5 text-xs text-text-muted">{{ section.help }}</p>

        <form class="mt-4" @submit.prevent="save">
            <PdsFields :fields="section.fields" :values="form" :errors="form.errors" :id-prefix="`${id}-${section.key}`" :disabled="readonly" />

            <div v-if="!readonly" class="mt-4 flex items-center justify-end gap-3">
                <span v-if="form.isDirty" class="text-xs text-warning">Unsaved changes</span>
                <span v-else-if="form.recentlySuccessful" class="text-xs text-success" role="status">Saved</span>
                <Button type="submit" :loading="form.processing" :disabled="!form.isDirty">Save {{ section.label.replace(/^[\d.]+\s*/, '').toLowerCase() }}</Button>
            </div>
        </form>
    </section>
</template>
