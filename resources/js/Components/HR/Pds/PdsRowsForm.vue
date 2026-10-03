<script setup>
import { computed, nextTick, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '../../UI/Button.vue';
import ActionButton from '../../UI/ActionButton.vue';
import Icon from '../../UI/Icon.vue';
import PdsFields from './PdsFields.vue';

/**
 * A repeating PDS section (children, education, eligibility, work
 * experience, L&D, …): add, remove and reorder entries, then save the
 * whole list to `{url}/{section.key}`. The server keeps rows by id, adds
 * new ones, and removes those no longer listed.
 */
const props = defineProps({
    section: { type: Object, required: true },
    rows: { type: Array, required: true },
    url: { type: String, required: true },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['dirty']);

const id = useId();

const blankRow = () =>
    Object.fromEntries(
        props.section.fields.filter((field) => field.type !== 'heading').map((field) => [field.key, field.type === 'checkbox' ? field.key === 'is_government' : null]),
    );

const form = useForm({ rows: props.rows.map((row) => ({ ...row })) });

watch(
    () => form.isDirty,
    (dirty) => emit('dirty', props.section.key, dirty),
);

const full = computed(() => props.section.max_rows !== null && form.rows.length >= props.section.max_rows);

const add = async () => {
    form.rows.push(blankRow());
    await nextTick();
    // Move focus to the new entry's first field.
    document.getElementById(`${id}-row-${form.rows.length - 1}`)?.querySelector('input, select, textarea')?.focus();
};

const remove = (index) => form.rows.splice(index, 1);

const move = (index, offset) => {
    const [row] = form.rows.splice(index, 1);
    form.rows.splice(index + offset, 0, row);
};

const save = () =>
    form.put(`${props.url}/${props.section.key}`, {
        preserveScroll: true,
        onSuccess: () => {
            // New rows now have ids; take the saved list as the baseline.
            form.defaults({ rows: props.rows.map((row) => ({ ...row })) });
            form.reset();
        },
    });

// After a save the page reloads with fresh rows (incl. new ids).
watch(
    () => props.rows,
    (rows) => {
        if (!form.isDirty) {
            form.defaults({ rows: rows.map((row) => ({ ...row })) });
            form.reset();
        }
    },
);

const rowError = (index) => Object.keys(form.errors).some((key) => key.startsWith(`rows.${index}.`));
</script>

<template>
    <section :aria-labelledby="`${id}-title`" class="rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 :id="`${id}-title`" class="text-sm font-semibold text-text">{{ section.label }}</h3>
                <p v-if="section.help" class="mt-0.5 text-xs text-text-muted">{{ section.help }}</p>
            </div>
            <span class="text-xs text-text-muted">
                {{ form.rows.length }} {{ form.rows.length === 1 ? 'entry' : 'entries' }}<template v-if="section.max_rows"> of {{ section.max_rows }}</template>
            </span>
        </div>

        <form class="mt-4 space-y-3" @submit.prevent="save">
            <p v-if="form.rows.length === 0" class="rounded-md bg-surface-muted px-3 py-4 text-center text-sm text-text-muted">
                None yet<template v-if="!readonly"> — add one below, or leave empty if not applicable</template>.
            </p>

            <fieldset
                v-for="(row, index) in form.rows"
                :id="`${id}-row-${index}`"
                :key="row.id ?? `new-${index}`"
                class="rounded-md border p-3"
                :class="rowError(index) ? 'border-danger' : 'border-border'"
            >
                <legend class="px-1 text-xs font-semibold text-text-muted uppercase">{{ section.noun }} {{ index + 1 }}</legend>
                <div v-if="!readonly" class="-mt-1 mb-2 flex justify-end gap-1">
                    <ActionButton icon="chevronUp" tone="neutral" :label="`Move ${section.noun} ${index + 1} up`" :disabled="index === 0" @click="move(index, -1)" />
                    <ActionButton icon="chevronDown" tone="neutral" :label="`Move ${section.noun} ${index + 1} down`" :disabled="index === form.rows.length - 1" @click="move(index, 1)" />
                    <ActionButton icon="trash" tone="danger" :label="`Remove ${section.noun} ${index + 1}`" @click="remove(index)" />
                </div>
                <PdsFields
                    :fields="section.fields"
                    :values="row"
                    :errors="form.errors"
                    :error-prefix="`rows.${index}.`"
                    :id-prefix="`${id}-row-${index}`"
                    :disabled="readonly"
                />
            </fieldset>

            <p v-if="form.errors.rows" class="text-xs text-danger">{{ form.errors.rows }}</p>

            <div v-if="!readonly" class="flex flex-wrap items-center justify-between gap-3 pt-1">
                <Button type="button" variant="secondary" :disabled="full" @click="add">
                    <Icon name="plus" size="sm" />Add {{ section.noun }}
                </Button>
                <div class="flex items-center gap-3">
                    <span v-if="form.isDirty" class="text-xs text-warning">Unsaved changes</span>
                    <span v-else-if="form.recentlySuccessful" class="text-xs text-success" role="status">Saved</span>
                    <Button type="submit" :loading="form.processing" :disabled="!form.isDirty">Save</Button>
                </div>
            </div>
        </form>
    </section>
</template>
