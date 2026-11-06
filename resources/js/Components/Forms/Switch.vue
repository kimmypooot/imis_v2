<script setup>
/**
 * An on/off setting that takes effect as soon as it is flipped (a feature
 * flag, "notify me"). For a choice that is saved later with a form, use
 * `Checkbox` instead. Space/Enter toggle it; the label is the accessible
 * name and is always shown, so color is never the only signal.
 */
defineProps({
    modelValue: { type: Boolean, default: false },
    id: { type: String, required: true },
    label: { type: String, required: true },
    description: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex items-start gap-3">
        <button
            :id="id"
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :aria-describedby="description ? `${id}-description` : undefined"
            :disabled="disabled"
            class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-transparent transition-colors duration-150 ease-standard focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none"
            :class="modelValue ? 'bg-primary' : 'bg-border-strong'"
            @click="$emit('update:modelValue', !modelValue)"
        >
            <span
                class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform duration-150 ease-standard motion-reduce:transition-none"
                :class="modelValue ? 'translate-x-4' : 'translate-x-0.5'"
                aria-hidden="true"
            />
        </button>
        <label :for="id" class="min-w-0 text-sm select-none" :class="disabled ? 'text-text-muted' : 'text-text'">
            {{ label }}
            <span v-if="description" :id="`${id}-description`" class="block text-xs text-text-muted">{{ description }}</span>
        </label>
    </div>
</template>
