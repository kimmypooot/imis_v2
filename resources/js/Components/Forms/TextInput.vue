<script setup>
import { computed } from 'vue';
import Icon from '../UI/Icon.vue';
import HelperText from './HelperText.vue';
import InputError from './InputError.vue';

/**
 * Label + input + helper text + error in one. Pass `error` (e.g.
 * `form.errors.name`) to mark the field invalid and show the message under
 * it; `helperText` is shown while there is no error.
 */
const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    type: {
        type: String,
        default: 'text',
    },
    autocomplete: {
        type: String,
        default: 'off',
    },
    required: {
        type: Boolean,
        default: false,
    },
    autofocus: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: null,
    },
    helperText: {
        type: String,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    // Optional Icon name shown inside the field, before the text.
    icon: {
        type: String,
        default: null,
    },
    // Values offered as you type (a native datalist); any other value is still allowed.
    suggestions: {
        type: Array,
        default: () => [],
    },
});

defineEmits(['update:modelValue']);

const isInvalid = computed(() => props.invalid || !!props.error);
const describedBy = computed(() => (props.error ? undefined : props.helperText ? `${props.id}-helper` : undefined));
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium text-text">{{ label }}</label>
        <div class="relative mt-1">
            <Icon
                v-if="icon"
                :name="icon"
                size="sm"
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted"
            />
            <input
                :id="id"
                :type="type"
                :value="modelValue"
                :autocomplete="autocomplete"
                :required="required"
                :autofocus="autofocus"
                :aria-invalid="isInvalid"
                :aria-describedby="describedBy"
                :disabled="disabled"
                :list="suggestions.length ? `${id}-suggestions` : undefined"
                class="block w-full form-control px-3 py-2"
                :class="{ 'pl-9': icon }"
                @input="$emit('update:modelValue', $event.target.value)"
            />
        </div>
        <datalist v-if="suggestions.length" :id="`${id}-suggestions`">
            <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
        </datalist>
        <HelperText v-if="helperText && !error" :id="`${id}-helper`">{{ helperText }}</HelperText>
        <InputError :message="error" />
    </div>
</template>
