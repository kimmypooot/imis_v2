<script setup>
import { ref, computed } from 'vue';
import Icon from '../UI/Icon.vue';
import HelperText from './HelperText.vue';
import InputError from './InputError.vue';

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
    autocomplete: {
        type: String,
        default: 'current-password',
    },
    required: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    // Shown under the field (and marks it invalid); helperText shows while there is no error.
    error: {
        type: String,
        default: null,
    },
    helperText: {
        type: String,
        default: null,
    },
    // Optional Icon name shown inside the field, before the text.
    icon: {
        type: String,
        default: null,
    },
});

defineEmits(['update:modelValue']);

const visible = ref(false);
const inputType = computed(() => (visible.value ? 'text' : 'password'));
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
                :type="inputType"
                :value="modelValue"
                :autocomplete="autocomplete"
                :required="required"
                :aria-invalid="isInvalid"
                :aria-describedby="describedBy"
                class="block w-full form-control px-3 py-2 pr-10"
                :class="{ 'pl-9': icon }"
                @input="$emit('update:modelValue', $event.target.value)"
            />
            <button
                type="button"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :aria-pressed="visible"
                :aria-label="visible ? 'Hide password' : 'Show password'"
                :title="visible ? 'Hide password' : 'Show password'"
                @click="visible = !visible"
            >
                <Icon :name="visible ? 'eyeSlash' : 'eye'" size="sm" />
            </button>
        </div>
        <HelperText v-if="helperText && !error" :id="`${id}-helper`">{{ helperText }}</HelperText>
        <InputError :message="error" />
    </div>
</template>
