<script setup>
import { computed } from 'vue';
import Card from '../../UI/Card.vue';
import Icon from '../../UI/Icon.vue';

/**
 * How far along the PDS is: each part of the form (a tab of the editor) is
 * Done when its required sections have entries, Started when some do, and
 * Not started otherwise. Sections that can be legitimately empty (children,
 * voluntary work, eligibility, trainings, other information) are optional:
 * a part with only those is "Optional" until something is added, and does
 * not count against the total. It guides, it does not gate signing.
 * Picking a part opens that tab in the editor.
 */
const props = defineProps({
    schema: { type: Object, required: true },
    pds: { type: Object, required: true },
});

const emit = defineEmits(['open']);

const has = (value) => value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && value.length === 0);

// Items 34–40 are answered with Yes/No, so "No" (false) counts there.
const filled = (key) => {
    const value = props.pds.sections[key];
    if (Array.isArray(value)) return value.length > 0;
    if (!value || typeof value !== 'object') return has(value);
    const answered = props.schema.sections[key]?.kind === 'questions';

    return Object.values(value).some((entry) => has(entry) && (entry !== false || answered));
};

const parts = computed(() =>
    props.schema.tabs.map((tab) => {
        const required = tab.sections.filter((key) => !props.schema.sections[key]?.optional);
        const anyFilled = tab.sections.some(filled);

        let state;
        if (required.length === 0) {
            state = anyFilled ? 'done' : 'optional';
        } else if (required.every(filled)) {
            state = 'done';
        } else {
            state = anyFilled ? 'started' : 'empty';
        }

        return { key: tab.key, label: tab.label, state };
    }),
);

// Optional parts left empty are not counted either way.
const counted = computed(() => parts.value.filter((part) => part.state !== 'optional'));
const doneCount = computed(() => counted.value.filter((part) => part.state === 'done').length);
const percent = computed(() => Math.round((doneCount.value / counted.value.length) * 100));
const next = computed(() => counted.value.find((part) => part.state !== 'done'));

const looks = {
    done: { icon: 'check', chip: 'bg-success-bg text-success', label: 'Done' },
    started: { icon: 'pencil', chip: 'bg-warning-bg text-warning', label: 'Started' },
    empty: { icon: 'plus', chip: 'bg-surface-muted text-text-muted', label: 'Not started' },
    optional: { icon: 'plus', chip: 'bg-surface-muted text-text-muted', label: 'Optional' },
};
</script>

<template>
    <Card title="How complete is your PDS?" icon="clipboard" :description="`${doneCount} of ${counted.length} parts filled in`">
        <template #actions>
            <button
                v-if="next"
                type="button"
                class="rounded-md text-sm font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                @click="emit('open', next.key)"
            >
                Continue with {{ next.label }}
            </button>
        </template>

        <div class="h-2 overflow-hidden rounded-full bg-surface-muted" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" aria-label="PDS completion">
            <div class="h-full rounded-full bg-success transition-[width] duration-500" :style="{ width: `${percent}%` }" />
        </div>

        <ul class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            <li v-for="part in parts" :key="part.key">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-md border border-border p-2.5 text-left transition-colors hover:border-primary/40 hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    @click="emit('open', part.key)"
                >
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full" :class="looks[part.state].chip" aria-hidden="true">
                        <Icon :name="looks[part.state].icon" size="xs" />
                    </span>
                    <span class="min-w-0 flex-1 truncate text-sm text-text">{{ part.label }}</span>
                    <span class="shrink-0 text-xs text-text-muted">{{ looks[part.state].label }}</span>
                </button>
            </li>
        </ul>

        <p class="mt-3 text-xs text-text-muted">Parts marked Optional (such as children or voluntary work) can stay empty if they do not apply to you.</p>
    </Card>
</template>
