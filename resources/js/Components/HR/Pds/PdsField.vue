<script setup>
import { computed } from 'vue';

/**
 * One PDS field, from a PdsSchema field definition. Styled like the
 * shared form inputs, plus what the PDS needs: decimal steps, min/max,
 * numeric keyboards for ID numbers, suggestion lists, Yes/No answers,
 * and the error message tied to the input for screen readers.
 */
const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: [String, Number, Boolean], default: null },
    id: { type: String, required: true },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const errorId = computed(() => `${props.id}-error`);
const helpId = computed(() => `${props.id}-help`);
const describedBy = computed(() => [props.error && errorId.value, props.field.help && helpId.value].filter(Boolean).join(' ') || undefined);

const inputType = computed(
    () => ({ email: 'email', number: 'number', year: 'number', date: 'date', idnumber: 'text' })[props.field.type] ?? 'text',
);

const text = computed(() => (props.modelValue === null || props.modelValue === undefined ? '' : String(props.modelValue)));

const control =
    'mt-1 block w-full rounded-md border bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted';
const border = computed(() => (props.error ? 'border-danger' : 'border-border'));
</script>

<template>
    <!-- Yes / No question -->
    <fieldset v-if="field.type === 'yesno'" :aria-describedby="describedBy" :disabled="disabled">
        <legend class="text-sm text-text">{{ field.label }}</legend>
        <div class="mt-2 flex gap-6">
            <label v-for="option in [{ value: true, label: 'Yes' }, { value: false, label: 'No' }]" :key="option.label" class="flex items-center gap-2 text-sm font-medium text-text">
                <input
                    type="radio"
                    :name="id"
                    :checked="modelValue === option.value"
                    class="h-4 w-4 border-border-strong text-link focus:ring-2 focus:ring-focus-ring"
                    @change="emit('update:modelValue', option.value)"
                />
                {{ option.label }}
            </label>
        </div>
        <p v-if="error" :id="errorId" class="mt-1 text-xs text-danger-fg">{{ error }}</p>
    </fieldset>

    <!-- Checkbox -->
    <div v-else-if="field.type === 'checkbox'">
        <label :for="id" class="flex items-center gap-2 text-sm font-medium text-text">
            <input
                :id="id"
                type="checkbox"
                :checked="!!modelValue"
                :disabled="disabled"
                :aria-describedby="describedBy"
                class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring"
                @change="emit('update:modelValue', $event.target.checked)"
            />
            {{ field.label }}
        </label>
        <p v-if="error" :id="errorId" class="mt-1 text-xs text-danger-fg">{{ error }}</p>
    </div>

    <!-- Labelled input / select / textarea -->
    <div v-else>
        <label :for="id" class="block text-sm font-medium text-text">
            {{ field.label }}<span v-if="field.required && !field.when" class="text-danger-fg" aria-hidden="true"> *</span>
        </label>

        <select
            v-if="field.type === 'select'"
            :id="id"
            :value="text"
            :disabled="disabled"
            :required="field.required"
            :aria-invalid="!!error"
            :aria-describedby="describedBy"
            :class="[control, border]"
            @change="emit('update:modelValue', $event.target.value)"
        >
            <option value="">— Select —</option>
            <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <textarea
            v-else-if="field.type === 'textarea'"
            :id="id"
            :value="text"
            rows="3"
            :maxlength="field.max"
            :disabled="disabled"
            :aria-invalid="!!error"
            :aria-describedby="describedBy"
            :class="[control, border]"
            @input="emit('update:modelValue', $event.target.value)"
        />

        <template v-else>
            <input
                :id="id"
                :type="inputType"
                :value="text"
                :list="field.suggestions ? `${id}-suggestions` : undefined"
                :inputmode="field.type === 'idnumber' ? 'numeric' : undefined"
                :step="field.type === 'number' ? (field.step ?? 'any') : field.type === 'year' ? 1 : undefined"
                :min="field.type === 'number' ? field.min : field.type === 'year' ? 1900 : undefined"
                :max="field.type === 'number' ? field.max : undefined"
                :maxlength="['text', 'email', 'idnumber'].includes(field.type) ? (field.max ?? 255) : undefined"
                :required="field.required && !field.when"
                :disabled="disabled"
                autocomplete="off"
                :aria-invalid="!!error"
                :aria-describedby="describedBy"
                :class="[control, border]"
                @input="emit('update:modelValue', $event.target.value)"
            />
            <datalist v-if="field.suggestions" :id="`${id}-suggestions`">
                <option v-for="suggestion in field.suggestions" :key="suggestion" :value="suggestion" />
            </datalist>
        </template>

        <p v-if="field.help && !error" :id="helpId" class="mt-1 text-xs text-text-muted">{{ field.help }}</p>
        <p v-if="error" :id="errorId" class="mt-1 text-xs text-danger-fg">{{ error }}</p>
    </div>
</template>
