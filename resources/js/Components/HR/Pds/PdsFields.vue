<script setup>
import PdsField from './PdsField.vue';

/**
 * A grid of PDS fields bound to `values` (an Inertia form, or one row of
 * it). A field with `when` only shows while its condition holds; the
 * server blanks it otherwise, so nothing hidden is ever kept.
 */
const props = defineProps({
    fields: { type: Array, required: true },
    values: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    errorPrefix: { type: String, default: '' },
    idPrefix: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});

const applies = (field) => {
    if (!field.when) return true;
    const actual = props.values[field.when.field];

    return typeof field.when.is === 'boolean' ? !!actual === field.when.is : actual === field.when.is;
};

const spanClass = (field) =>
    field.type === 'heading' || field.type === 'yesno' || field.type === 'textarea' || field.span === 'full' ? 'sm:col-span-2' : '';
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <template v-for="field in fields" :key="field.key">
            <h4
                v-if="field.type === 'heading'"
                class="mt-2 border-b border-border pb-1 text-xs font-bold tracking-wide text-link uppercase sm:col-span-2"
            >
                {{ field.label }}
            </h4>
            <div v-else-if="applies(field)" :class="spanClass(field)">
                <PdsField
                    v-model="values[field.key]"
                    :field="field"
                    :id="`${idPrefix}-${field.key}`"
                    :error="errors[`${errorPrefix}${field.key}`]"
                    :disabled="disabled"
                />
            </div>
        </template>
    </div>
</template>
