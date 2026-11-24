<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';

/**
 * A select you can type into: type to narrow a long list (everyone in the
 * office, say), then pick with the mouse or the keyboard (Arrow keys, Enter,
 * Escape). The value is the chosen option's `value`, or null for none.
 *
 * Options: [{ value, label, description?, group? }]. Options with a `group`
 * are listed under that heading, in the order the groups first appear. Typing
 * matches the label and the description, ignoring case.
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: null },
    options: { type: Array, required: true },
    id: { type: String, default: () => useId() },
    label: { type: String, default: null },
    placeholder: { type: String, default: 'Type to search' },
    emptyText: { type: String, default: 'No one matches that.' },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** List the options in the flow of the page instead of floating over it: for a dialog, whose scrolling would clip a floating list. */
    inline: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const query = ref('');
const active = ref(0);
const input = ref(null);
const list = ref(null);

const selected = computed(() => props.options.find((option) => option.value === props.modelValue) ?? null);

const matches = computed(() => {
    const words = query.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return props.options;

    return props.options.filter((option) => {
        const haystack = `${option.label} ${option.description ?? ''}`.toLowerCase();
        return words.every((word) => haystack.includes(word));
    });
});

// The matches in display order, with a heading before each new group.
const rows = computed(() => {
    const groups = [];
    for (const option of matches.value) {
        const name = option.group ?? '';
        let group = groups.find((entry) => entry.name === name);
        if (!group) groups.push((group = { name, options: [] }));
        group.options.push(option);
    }

    return groups.flatMap((group) => [...(group.name ? [{ heading: group.name }] : []), ...group.options.map((option) => ({ option }))]);
});
const choices = computed(() => rows.value.filter((row) => row.option).map((row) => row.option));

watch(query, () => (active.value = 0));

const show = () => {
    if (props.disabled) return;
    open.value = true;
};

const close = () => {
    open.value = false;
    query.value = '';
};

const choose = (option) => {
    emit('update:modelValue', option.value);
    close();
};

const clear = () => {
    emit('update:modelValue', null);
    query.value = '';
    input.value?.focus();
};

const scrollToActive = async () => {
    await nextTick();
    list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
};

const move = (step) => {
    show();
    if (!choices.value.length) return;
    active.value = (active.value + step + choices.value.length) % choices.value.length;
    scrollToActive();
};

const onKeydown = (event) => {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(open.value ? 1 : 0);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Home':
            if (open.value) {
                event.preventDefault();
                active.value = 0;
                scrollToActive();
            }
            break;
        case 'End':
            if (open.value) {
                event.preventDefault();
                active.value = Math.max(choices.value.length - 1, 0);
                scrollToActive();
            }
            break;
        case 'Enter':
            if (open.value) {
                event.preventDefault();
                if (choices.value[active.value]) choose(choices.value[active.value]);
            }
            break;
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                close();
            }
            break;
    }
};

const optionId = (option) => `${props.id}-option-${option.value}`;
const activeId = computed(() => (open.value && choices.value[active.value] ? optionId(choices.value[active.value]) : undefined));
</script>

<template>
    <div class="relative">
        <InputLabel v-if="label" :for="id" :value="label" />

        <div class="relative" :class="{ 'mt-1': label }">
            <input
                :id="id"
                ref="input"
                v-model="query"
                type="text"
                role="combobox"
                autocomplete="off"
                class="block w-full form-control px-3 py-2 pr-16"
                :placeholder="selected && !open ? '' : placeholder"
                :disabled="disabled"
                :aria-invalid="invalid"
                :aria-expanded="open"
                :aria-controls="`${id}-listbox`"
                :aria-activedescendant="activeId"
                aria-autocomplete="list"
                @focus="show"
                @click="show"
                @input="show"
                @blur="close"
                @keydown="onKeydown"
            />

            <!-- What is chosen, shown over the empty field until you type. -->
            <span v-if="selected && !open && !query" class="pointer-events-none absolute inset-y-0 left-3 right-16 flex flex-col justify-center overflow-hidden">
                <span class="truncate text-sm text-text">{{ selected.label }}</span>
                <span v-if="selected.description" class="truncate text-xs text-text-muted">{{ selected.description }}</span>
            </span>

            <span class="absolute inset-y-0 right-2 flex items-center gap-1">
                <button
                    v-if="selected && !disabled"
                    type="button"
                    class="rounded-sm p-1 text-text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    aria-label="Clear the choice"
                    @mousedown.prevent
                    @click="clear"
                >
                    <Icon name="close" size="sm" />
                </button>
                <Icon name="chevronDown" size="sm" class="pointer-events-none text-text-muted" />
            </span>
        </div>

        <ul
            v-show="open"
            :id="`${id}-listbox`"
            ref="list"
            role="listbox"
            class="mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-border bg-surface py-1 text-sm"
            :class="inline ? '' : 'absolute z-30 max-h-64 shadow-lg'"
        >
            <template v-for="(row, index) in rows" :key="row.option ? row.option.value : `heading-${row.heading}-${index}`">
                <li v-if="row.heading" role="presentation" class="px-3 pt-2 pb-1 text-xs font-semibold tracking-wide text-text-muted uppercase">{{ row.heading }}</li>
                <li
                    v-else
                    :id="optionId(row.option)"
                    role="option"
                    :aria-selected="row.option.value === modelValue"
                    :data-active="choices[active] === row.option"
                    class="cursor-pointer px-3 py-1.5"
                    :class="choices[active] === row.option ? 'bg-info-bg text-text' : 'text-text hover:bg-surface-muted'"
                    @mousedown.prevent="choose(row.option)"
                    @mousemove="active = choices.indexOf(row.option)"
                >
                    <span class="block truncate" :class="{ 'font-semibold': row.option.value === modelValue }">{{ row.option.label }}</span>
                    <span v-if="row.option.description" class="block truncate text-xs text-text-muted">{{ row.option.description }}</span>
                </li>
            </template>
            <li v-if="!choices.length" class="px-3 py-2 text-text-muted">{{ emptyText }}</li>
        </ul>
    </div>
</template>
