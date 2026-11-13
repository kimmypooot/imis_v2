<script setup>
import Button from '../UI/Button.vue';
import { ref, useId } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Date-range (and optional extra select) filters for the server-side log
 * tables. Applying keeps the table's own state (search, sort, page size)
 * and fetches only the table's prop.
 */
const props = defineProps({
    url: { type: String, required: true },
    only: { type: Array, required: true },
    query: { type: Object, required: true },
    select: { type: Object, default: null /** { key, label, options: [{ value, label }] } */ },
});

const id = useId();
const from = ref(props.query.from ?? '');
const to = ref(props.query.to ?? '');
const extra = ref(props.select ? props.query[props.select.key] ?? '' : '');

const apply = () => {
    router.get(
        props.url,
        {
            ...props.query,
            from: from.value || undefined,
            to: to.value || undefined,
            ...(props.select ? { [props.select.key]: extra.value || undefined } : {}),
            page: 1,
        },
        { only: props.only, preserveState: true, preserveScroll: true, replace: true },
    );
};

const clear = () => {
    from.value = '';
    to.value = '';
    extra.value = '';
    apply();
};
</script>

<template>
    <form class="flex flex-wrap items-center gap-2 text-sm" @submit.prevent="apply">
        <label :for="`${id}-from`" class="text-text-muted">From</label>
        <input :id="`${id}-from`" v-model="from" type="date" class="form-control px-2 py-1" />
        <label :for="`${id}-to`" class="text-text-muted">to</label>
        <input :id="`${id}-to`" v-model="to" type="date" class="form-control px-2 py-1" />
        <template v-if="select">
            <label :for="`${id}-extra`" class="sr-only">{{ select.label }}</label>
            <select :id="`${id}-extra`" v-model="extra" class="form-control max-w-48 px-2 py-1">
                <option value="">{{ select.label }}</option>
                <option v-for="option in select.options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </template>
        <Button type="submit" size="sm" icon="funnel">Apply</Button>
        <button v-if="from || to || extra" type="button" class="text-xs text-link hover:underline" @click="clear">Clear</button>
    </form>
</template>
