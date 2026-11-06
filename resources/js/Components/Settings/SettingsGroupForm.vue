<script setup>
import { useForm } from '@inertiajs/vue3';
import Card from '../UI/Card.vue';
import Badge from '../UI/Badge.vue';
import Button from '../UI/Button.vue';
import Icon from '../UI/Icon.vue';
import InputLabel from '../Forms/InputLabel.vue';
import HelperText from '../Forms/HelperText.vue';
import InputError from '../Forms/InputError.vue';
import Select from '../Forms/Select.vue';

/**
 * One settings group, saved as a single form. Only editable settings are
 * submitted; protected ones are shown read-only (and the server would
 * reject them anyway). Validation is entirely server-side — the input
 * types/bounds here are just hints.
 */
const props = defineProps({
    group: { type: Object, required: true },
    canUpdate: { type: Boolean, default: false },
});

const toInput = (setting) => {
    if (setting.type === 'boolean') return Boolean(setting.value);
    return setting.value === null ? '' : String(setting.value);
};

const form = useForm({
    settings: Object.fromEntries(
        props.group.settings.filter((setting) => setting.editable).map((setting) => [setting.key, toInput(setting)]),
    ),
});

const inputTypes = { string: 'text', integer: 'number', url: 'url', email: 'email' };

// An optional select can be left unset (stored as null).
const selectOptions = (setting) => [
    ...(setting.required ? [] : [{ value: '', label: '— Not set —' }]),
    ...(setting.options ?? []).map((option) => ({ value: option, label: option })),
];

// What an empty field falls back to, following another setting's
// current (unsaved) value, e.g. the holder of the selected position.
const placeholderFor = (setting) => {
    const source = setting.placeholders;
    const fallback = source ? source.values?.[form.settings[source.setting]] : null;
    return fallback ? `${fallback} (from the Plantilla)` : undefined;
};

const fieldId = (setting) => `setting-${setting.key}`;
const errorFor = (setting) => form.errors[`settings.${setting.key}`];
const describedBy = (setting) => (setting.description && !errorFor(setting) ? `${fieldId(setting)}-helper` : undefined);

const submit = () => {
    form.put(`/admin/settings/${props.group.key}`, {
        preserveScroll: true,
        // The saved values become the new baseline for `isDirty`.
        onSuccess: () => form.defaults(),
    });
};

const inputClass =
    'block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted';
</script>

<template>
    <Card :title="group.label" :description="group.description" icon="cog">
        <form :aria-label="`${group.label} settings`" class="space-y-5" @submit.prevent="submit">
            <div v-for="setting in group.settings" :key="setting.key">
                <!-- Protected: value only, never an input. -->
                <template v-if="!setting.editable">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-text">{{ setting.label }}</span>
                        <Badge tone="neutral">
                            <Icon name="lock" size="sm" class="mr-1 inline" />
                            Protected
                        </Badge>
                    </div>
                    <p class="mt-1 break-words rounded-md bg-surface-muted px-3 py-2 text-sm text-text">
                        {{ setting.value ?? '—' }}
                    </p>
                    <HelperText v-if="setting.description">{{ setting.description }}</HelperText>
                </template>

                <template v-else-if="setting.type === 'boolean'">
                    <label :for="fieldId(setting)" class="flex select-none items-center gap-2 text-sm font-medium text-text">
                        <input
                            :id="fieldId(setting)"
                            v-model="form.settings[setting.key]"
                            type="checkbox"
                            :disabled="!canUpdate"
                            :aria-describedby="describedBy(setting)"
                            class="h-4 w-4 rounded-sm border-border-strong text-primary focus:ring-2 focus:ring-focus-ring"
                        />
                        {{ setting.label }}
                    </label>
                    <HelperText v-if="setting.description && !errorFor(setting)" :id="`${fieldId(setting)}-helper`">
                        {{ setting.description }}
                    </HelperText>
                    <InputError :message="errorFor(setting)" />
                </template>

                <template v-else>
                    <InputLabel :for="fieldId(setting)" :required="setting.required">{{ setting.label }}</InputLabel>
                    <div class="mt-1">
                        <Select
                            v-if="setting.type === 'select'"
                            :id="fieldId(setting)"
                            v-model="form.settings[setting.key]"
                            :options="selectOptions(setting)"
                            :required="setting.required"
                            :disabled="!canUpdate"
                            :invalid="!!errorFor(setting)"
                            :aria-describedby="describedBy(setting)"
                        />
                        <textarea
                            v-else-if="setting.type === 'text'"
                            :id="fieldId(setting)"
                            v-model="form.settings[setting.key]"
                            :rows="3"
                            :maxlength="setting.max ?? 5000"
                            :required="setting.required"
                            :disabled="!canUpdate"
                            :aria-invalid="!!errorFor(setting)"
                            :aria-describedby="describedBy(setting)"
                            :class="inputClass"
                        />
                        <input
                            v-else
                            :id="fieldId(setting)"
                            v-model="form.settings[setting.key]"
                            :type="inputTypes[setting.type] ?? 'text'"
                            :min="setting.type === 'integer' ? setting.min : undefined"
                            :max="setting.type === 'integer' ? setting.max : undefined"
                            :maxlength="setting.type === 'string' ? setting.max ?? 255 : undefined"
                            :placeholder="placeholderFor(setting)"
                            :required="setting.required"
                            :disabled="!canUpdate"
                            :aria-invalid="!!errorFor(setting)"
                            :aria-describedby="describedBy(setting)"
                            :class="[inputClass, setting.type === 'integer' ? 'max-w-32' : '']"
                        />
                    </div>
                    <HelperText v-if="setting.description && !errorFor(setting)" :id="`${fieldId(setting)}-helper`">
                        {{ setting.description }}
                    </HelperText>
                    <InputError :message="errorFor(setting)" />
                </template>
            </div>

            <InputError :message="form.errors.settings" />

            <div v-if="canUpdate && Object.keys(form.settings).length" class="flex items-center gap-3 border-t border-border pt-4">
                <Button type="submit" :loading="form.processing" :disabled="!form.isDirty">Save {{ group.label }}</Button>
                <p role="status" aria-live="polite" class="text-sm text-success">
                    <template v-if="form.recentlySuccessful">Saved.</template>
                </p>
                <p v-if="form.hasErrors && !form.processing" class="text-sm text-danger">
                    Not saved — fix the highlighted fields.
                </p>
            </div>
        </form>
    </Card>
</template>
