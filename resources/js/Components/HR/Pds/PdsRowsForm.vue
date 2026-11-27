<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import PdsFields from './PdsFields.vue';
import PdsField from './PdsField.vue';

/**
 * A repeating PDS section (children, education, eligibility, work
 * experience, …), saved as a whole list to `{url}/{section.key}` by the
 * editor with the rest of its part (`save()`). The server keeps rows by id,
 * adds new ones, and removes those no longer listed.
 *
 * How entries show follows the schema:
 * - `layout: inline`: one line per entry, typed straight in (a child's
 *   name and birthday, a skill). Enter on the last line adds another.
 * - otherwise one summary line per entry (the schema's `summary` fields,
 *   as on the form), opening to the full form for the entry being edited.
 * - `order: recent` keeps entries newest first (work experience, as CSC's
 *   guide asks), so they have no move buttons.
 */
const props = defineProps({
    section: { type: Object, required: true },
    rows: { type: Array, required: true },
    url: { type: String, default: null },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['dirty', 'submit']);

const id = useId();
const fields = computed(() => props.section.fields.filter((field) => field.type !== 'heading'));
const fieldByKey = computed(() => Object.fromEntries(fields.value.map((field) => [field.key, field])));
const inline = computed(() => props.section.layout === 'inline');
const recent = computed(() => props.section.order === 'recent');
const canMove = computed(() => !props.readonly && !recent.value);

/** Newest first: the one still held (no end date) on top, then by start date. */
const ordered = (rows) => {
    if (!recent.value) return rows;

    return [...rows].sort((a, b) => (a.date_to ? 1 : 0) - (b.date_to ? 1 : 0) || String(b.date_from ?? '').localeCompare(String(a.date_from ?? '')));
};
const copies = (rows) => ordered(rows).map((row) => ({ ...row }));

const form = useForm({ rows: copies(props.rows) });

/** The entry open for editing (summary layout), by position. */
const open = ref(null);

watch(
    () => form.isDirty,
    (dirty) => emit('dirty', props.section.key, dirty),
);

// After a save the page reloads with fresh rows (incl. new ids).
watch(
    () => props.rows,
    (rows) => {
        if (!form.isDirty) {
            form.defaults({ rows: copies(rows) });
            form.reset();
        }
    },
);

const full = computed(() => props.section.max_rows !== null && form.rows.length >= props.section.max_rows);

const blankRow = () => Object.fromEntries(fields.value.map((field) => [field.key, field.type === 'checkbox' ? field.key === 'is_government' : null]));

const rowId = (index) => `${id}-row-${index}`;
const focusRow = async (index) => {
    await nextTick();
    document.getElementById(rowId(index))?.querySelector('input, select, textarea')?.focus();
};

const add = () => {
    form.rows.push(blankRow());
    if (!inline.value) open.value = form.rows.length - 1;
    focusRow(form.rows.length - 1);
};

const remove = (index) => {
    form.rows.splice(index, 1);
    if (open.value === index) open.value = null;
    else if (open.value !== null && open.value > index) open.value -= 1;
};

const move = (index, offset) => {
    const [row] = form.rows.splice(index, 1);
    form.rows.splice(index + offset, 0, row);
    if (open.value === index) open.value = index + offset;
};

const toggle = (index) => {
    open.value = open.value === index ? null : index;
    if (open.value !== null && !props.readonly) focusRow(index);
};

const rowError = (index) => Object.keys(form.errors).some((key) => key.startsWith(`rows.${index}.`));

/** Inline lists: Enter moves to the next line, or adds one after the last. */
const nextLine = (index) => {
    if (index < form.rows.length - 1) focusRow(index + 1);
    else if (!full.value && Object.values(form.rows[index]).some((value) => value !== null && value !== '')) add();
};

/** Saves the list; resolves true when saved, false on validation errors. */
const save = () =>
    new Promise((resolve) => {
        if (recent.value) {
            form.rows = ordered(form.rows);
            open.value = null;
        }
        form.put(`${props.url}/${props.section.key}`, {
            preserveScroll: true,
            onSuccess: () => {
                // New rows now have ids; take the saved list as the baseline.
                form.defaults({ rows: copies(props.rows) });
                form.reset();
                open.value = null;
                resolve(true);
            },
            onError: () => {
                // Open the first entry with a problem, so it's in view.
                const first = form.rows.findIndex((_, index) => rowError(index));
                if (!inline.value && first !== -1) open.value = first;
                resolve(false);
            },
            onCancel: () => resolve(false),
        });
    });

defineExpose({ save, isDirty: () => form.isDirty });

// ── Summary line ──────────────────────────────────────────────────
const money = (value) => Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const display = (key, value) => {
    const field = fieldByKey.value[key];
    if (value === null || value === undefined || value === '') return '';
    // Dates as the PDS writes them (dd/mm/yyyy): short, and the same as on the printout.
    if (field?.type === 'date') return String(value).slice(0, 10).split('-').reverse().join('/');
    if (field?.type === 'select') return field.options?.find((option) => option.value === value)?.label ?? String(value);
    if (field?.type === 'number' && field.prefix) return `${field.prefix}${money(value)}`;
    if (field?.unit) return `${value} ${field.unit}`;

    return String(value);
};

/** The entry in one line: its summary fields, a date range as "from – to" (or "– Present"). */
const specs = computed(() => props.section.summary ?? fields.value.slice(0, 3).map((field) => field.key));

/** A date range as "from – to" ("– Present" when the end may be left blank for a current one). */
const range = (row, [fromKey, toKey]) => {
    const from = display(fromKey, row[fromKey]);
    const to = row[toKey] ? display(toKey, row[toKey]) : /present/i.test(fieldByKey.value[toKey]?.label ?? '') ? 'Present' : '';

    return from && to ? `${from} – ${to}` : from;
};

/**
 * The entry in one line. A leading date range gets its own column (so the
 * entries line up); the first of the rest is the entry's name, in bold.
 */
const summary = (row) => {
    const [first, ...rest] = specs.value;
    const dated = Array.isArray(first);
    const parts = (dated ? rest : specs.value).map((spec) => (Array.isArray(spec) ? range(row, spec) : display(spec, row[spec]))).filter(Boolean);

    return { dates: dated ? range(row, first) : null, name: parts[0] ?? null, details: parts.slice(1) };
};
const dated = computed(() => Array.isArray(specs.value[0]));

// Inline rows: the entry fields side by side, then the remove button.
const inlineGrid = computed(() => (fields.value.length === 1 ? 'grid-cols-[minmax(0,1fr)_auto]' : 'grid-cols-[minmax(0,1fr)_minmax(0,11rem)_auto]'));
</script>

<template>
    <section :aria-labelledby="`${id}-title`" class="rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 :id="`${id}-title`" class="text-sm font-semibold text-text">{{ section.label }}</h3>
                <p v-if="section.help" class="mt-0.5 text-xs text-text-muted">{{ section.help }}</p>
                <p v-if="inline && fields.length === 1 && fields[0].help" class="mt-0.5 text-xs text-text-muted">{{ fields[0].help }}</p>
                <p v-if="recent" class="mt-0.5 text-xs text-text-muted">Listed newest first, as on the form.</p>
            </div>
            <span class="text-xs text-text-muted">
                {{ form.rows.length }} {{ form.rows.length === 1 ? 'entry' : 'entries' }}<template v-if="section.max_rows"> of {{ section.max_rows }}</template>
            </span>
        </div>

        <form class="mt-4 space-y-3" @submit.prevent="emit('submit')">
            <p v-if="form.rows.length === 0" class="rounded-md bg-surface-muted px-3 py-4 text-center text-sm text-text-muted">
                None yet<template v-if="!readonly"> — add one below, or leave empty if not applicable</template>.
            </p>

            <!-- One line per entry, typed straight in -->
            <div v-else-if="inline" role="group" :aria-labelledby="`${id}-title`">
                <div class="mb-1 grid gap-2 px-0.5 text-xs font-medium text-text-muted" :class="inlineGrid" aria-hidden="true">
                    <span v-for="field in fields" :key="field.key">{{ field.label }}</span>
                    <span class="w-9" />
                </div>
                <ul class="space-y-2">
                    <li v-for="(row, index) in form.rows" :id="rowId(index)" :key="row.id ?? `new-${index}`" class="grid items-start gap-2" :class="inlineGrid" @keydown.enter.prevent="nextLine(index)">
                        <PdsField
                            v-for="field in fields"
                            :key="field.key"
                            v-model="row[field.key]"
                            :field="{ ...field, label: `${field.label} (${section.noun} ${index + 1})` }"
                            :id="`${rowId(index)}-${field.key}`"
                            :error="form.errors[`rows.${index}.${field.key}`]"
                            :disabled="readonly"
                            compact
                        />
                        <ActionButton v-if="!readonly" icon="trash" tone="danger" :label="`Remove ${section.noun} ${index + 1}`" @click="remove(index)" />
                        <span v-else class="w-9" aria-hidden="true" />
                    </li>
                </ul>
            </div>

            <!-- One summary line per entry, opening to the full form -->
            <ul v-else class="divide-y divide-border rounded-md border border-border">
                <li v-for="(row, index) in form.rows" :key="row.id ?? `new-${index}`" :class="rowError(index) && open !== index && 'bg-danger-bg'">
                    <div class="flex items-center gap-1 py-1.5 pr-2 pl-3">
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-center gap-3 rounded-md py-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                            :aria-expanded="open === index"
                            :aria-controls="rowId(index)"
                            @click="toggle(index)"
                        >
                            <Icon :name="open === index ? 'chevronDown' : 'chevronRight'" size="sm" class="shrink-0 text-text-muted" />
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-col text-sm sm:flex-row sm:items-baseline sm:gap-3">
                                    <span v-if="dated" class="shrink-0 text-text-muted tabular-nums sm:w-48">{{ summary(row).dates || 'No dates yet' }}</span>
                                    <span class="min-w-0 flex-1 truncate">
                                        <span class="font-medium text-text">{{ summary(row).name ?? `New ${section.noun}` }}</span>
                                        <span v-for="(part, p) in summary(row).details" :key="p" class="text-text-muted"> · {{ part }}</span>
                                    </span>
                                </span>
                                <span v-if="rowError(index) && open !== index" class="mt-0.5 flex items-center gap-1 text-xs font-medium text-danger-fg">
                                    <Icon name="exclamation" size="xs" />Needs attention — open to see what to fix
                                </span>
                            </span>
                        </button>
                        <template v-if="canMove">
                            <ActionButton icon="chevronUp" tone="neutral" :label="`Move ${section.noun} ${index + 1} up`" :disabled="index === 0" @click="move(index, -1)" />
                            <ActionButton icon="chevronDown" tone="neutral" :label="`Move ${section.noun} ${index + 1} down`" :disabled="index === form.rows.length - 1" @click="move(index, 1)" />
                        </template>
                        <ActionButton v-if="!readonly" icon="trash" tone="danger" :label="`Remove ${section.noun} ${index + 1}`" @click="remove(index)" />
                    </div>

                    <div v-if="open === index" :id="rowId(index)" class="border-t border-border bg-surface-muted/50 p-3">
                        <PdsFields
                            :fields="section.fields"
                            :values="row"
                            :errors="form.errors"
                            :error-prefix="`rows.${index}.`"
                            :id-prefix="rowId(index)"
                            :disabled="readonly"
                        />
                        <div class="mt-4 flex justify-end">
                            <Button size="sm" variant="secondary" @click="open = null">{{ readonly ? 'Close' : 'Done' }}</Button>
                        </div>
                    </div>
                </li>
            </ul>

            <p v-if="form.errors.rows" class="text-xs text-danger-fg">{{ form.errors.rows }}</p>

            <div v-if="!readonly">
                <Button variant="secondary" size="sm" icon="plus" :disabled="full" @click="add">Add {{ section.noun }}</Button>
            </div>
        </form>
    </section>
</template>
