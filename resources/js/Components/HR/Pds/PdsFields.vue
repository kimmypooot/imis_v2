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

/**
 * How much of a row a field needs. The server may say (`width`); otherwise
 * it follows from the type, so a year or a height never gets a half-row
 * box. Classes are written out in full so Tailwind can see them, and use
 * container queries: the form narrows with the sidebar and the tab panel,
 * not just with the screen.
 */
const widths = {
    xs: 'col-span-6 @xl:col-span-3 @3xl:col-span-2',
    sm: 'col-span-6 @xl:col-span-4 @3xl:col-span-3',
    md: 'col-span-12 @xl:col-span-6 @3xl:col-span-4',
    lg: 'col-span-12 @xl:col-span-6',
    xl: 'col-span-12 @3xl:col-span-8',
    full: 'col-span-12',
};

const widthOf = (field) => {
    if (field.width) return field.width;
    if (field.span === 'full') return 'full';

    switch (field.type) {
        case 'heading':
        case 'yesno':
        case 'textarea':
        case 'checkbox':
            return 'full';
        case 'year':
            return 'xs';
        case 'number':
        case 'date':
            return 'sm';
        case 'select':
        case 'idnumber':
            return 'md';
        default:
            return !field.max ? 'lg' : field.max <= 10 ? 'xs' : field.max <= 100 ? 'md' : 'lg';
    }
};

const spanClass = (field) => widths[widthOf(field)];
</script>

<template>
    <div class="@container">
        <div class="grid grid-cols-12 gap-x-4 gap-y-5">
            <template v-for="field in fields" :key="field.key">
                <h4
                    v-if="field.type === 'heading'"
                    class="col-span-12 mt-3 flex items-center gap-3 text-xs font-bold tracking-wide text-link uppercase first:mt-0"
                >
                    <span>{{ field.label }}</span>
                    <span class="h-px flex-1 bg-border" aria-hidden="true" />
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
    </div>
</template>
