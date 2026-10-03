<script setup>
import TextInput from '../Forms/TextInput.vue';
import Textarea from '../Forms/Textarea.vue';
import InputError from '../Forms/InputError.vue';
import FormGroup from '../Forms/FormGroup.vue';
import Select from '../Forms/Select.vue';
import Checkbox from '../Forms/Checkbox.vue';

/**
 * Renders a form's fields from a declarative list, bound to an Inertia
 * form — so simple admin records (offices, services, committees) share one
 * modal instead of each hand-writing the same inputs.
 *
 * Field: { key, label, type = 'text' | 'email' | 'url' | 'tel' | 'number' |
 *          'date' | 'textarea' | 'select' | 'checkbox', required?, options?,
 *          span?: 'full' | 'half' (default), help?, suggestions? }
 * `suggestions` (text fields) are offered as you type; other values are allowed.
 */
defineProps({
    form: { type: Object, required: true },
    fields: { type: Array, required: true },
    idPrefix: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <fieldset :disabled="disabled" class="grid gap-4 sm:grid-cols-2">
        <template v-for="field in fields" :key="field.key">
            <div v-if="field.type === 'checkbox'" class="sm:col-span-2">
                <Checkbox :id="`${idPrefix}-${field.key}`" v-model="form[field.key]" :label="field.label" />
                <InputError :message="form.errors[field.key]" />
            </div>

            <FormGroup
                v-else-if="field.type === 'select' || field.type === 'textarea'"
                :id="`${idPrefix}-${field.key}`"
                :label="field.label"
                :required="field.required"
                :helper-text="field.help"
                :error="form.errors[field.key]"
                :class="{ 'sm:col-span-2': field.span === 'full' || field.type === 'textarea' }"
            >
                <Select v-if="field.type === 'select'" :id="`${idPrefix}-${field.key}`" v-model="form[field.key]" :options="field.options" />
                <Textarea v-else :id="`${idPrefix}-${field.key}`" v-model="form[field.key]" :invalid="!!form.errors[field.key]" />
            </FormGroup>

            <div v-else :class="{ 'sm:col-span-2': field.span === 'full' }">
                <TextInput
                    :id="`${idPrefix}-${field.key}`"
                    v-model="form[field.key]"
                    :type="field.type ?? 'text'"
                    :label="field.label"
                    :required="field.required"
                    :invalid="!!form.errors[field.key]"
                    :suggestions="field.suggestions"
                />
                <p v-if="field.help && !form.errors[field.key]" class="mt-1 text-xs text-text-muted">{{ field.help }}</p>
                <InputError :message="form.errors[field.key]" />
            </div>
        </template>
    </fieldset>
</template>
