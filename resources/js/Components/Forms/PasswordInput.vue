<script setup>
import { ref, computed } from 'vue';

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
});

defineEmits(['update:modelValue']);

const visible = ref(false);
const inputType = computed(() => (visible.value ? 'text' : 'password'));
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium text-text">{{ label }}</label>
        <div class="relative mt-1">
            <input
                :id="id"
                :type="inputType"
                :value="modelValue"
                :autocomplete="autocomplete"
                :required="required"
                :aria-invalid="invalid"
                class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 pr-16 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                @input="$emit('update:modelValue', $event.target.value)"
            />
            <button
                type="button"
                class="absolute inset-y-0 right-0 px-3 text-sm text-text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :aria-pressed="visible"
                :aria-label="visible ? 'Hide password' : 'Show password'"
                @click="visible = !visible"
            >
                {{ visible ? 'Hide' : 'Show' }}
            </button>
        </div>
    </div>
</template>
