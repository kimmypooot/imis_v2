<script setup>
import Checkbox from './Checkbox.vue';
import InputError from './InputError.vue';

/**
 * A labelled set of checkboxes bound to an array of selected values — a
 * fieldset/legend, so screen readers announce the group's question.
 */
const props = defineProps({
    id: { type: String, required: true },
    legend: { type: String, required: true },
    options: { type: Array, required: true /** [{ value, label }] */ },
    modelValue: { type: Array, default: () => [] },
    helperText: { type: String, default: null },
    emptyText: { type: String, default: 'Nothing to choose from yet.' },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const toggle = (value, checked) => {
    emit(
        'update:modelValue',
        checked ? [...props.modelValue, value] : props.modelValue.filter((selected) => selected !== value),
    );
};
</script>

<template>
    <fieldset :aria-describedby="helperText ? `${id}-helper` : undefined">
        <legend class="text-sm font-medium text-text">{{ legend }}</legend>
        <p v-if="helperText" :id="`${id}-helper`" class="mt-0.5 text-xs text-text-muted">{{ helperText }}</p>
        <div v-if="options.length" class="mt-2 grid gap-2 sm:grid-cols-2">
            <Checkbox
                v-for="option in options"
                :id="`${id}-${option.value}`"
                :key="option.value"
                :label="option.label"
                :model-value="modelValue.includes(option.value)"
                @update:model-value="(checked) => toggle(option.value, checked)"
            />
        </div>
        <p v-else class="mt-2 text-sm text-text-muted">{{ emptyText }}</p>
        <InputError :message="error" />
    </fieldset>
</template>
