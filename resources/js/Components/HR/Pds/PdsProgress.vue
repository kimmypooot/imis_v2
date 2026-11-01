<script setup>
import { computed } from 'vue';
import Card from '../../UI/Card.vue';
import Icon from '../../UI/Icon.vue';

/**
 * How far along the PDS is: each part of the form (a tab of the editor) is
 * Done when every section in it has entries, Started when some do, and
 * Not started otherwise. It only counts what is filled in — a section that
 * does not apply can stay empty — so it guides, it does not gate signing.
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
        const done = tab.sections.filter(filled).length;
        const state = done === 0 ? 'empty' : done === tab.sections.length ? 'done' : 'started';

        return { key: tab.key, label: tab.label, state };
    }),
);

const doneCount = computed(() => parts.value.filter((part) => part.state === 'done').length);
const percent = computed(() => Math.round((doneCount.value / parts.value.length) * 100));
const next = computed(() => parts.value.find((part) => part.state !== 'done'));

const looks = {
    done: { icon: 'check', chip: 'bg-success-bg text-success', label: 'Done' },
    started: { icon: 'pencil', chip: 'bg-warning-bg text-warning', label: 'Started' },
    empty: { icon: 'plus', chip: 'bg-surface-muted text-text-muted', label: 'Not started' },
};
</script>

<template>
    <Card title="How complete is your PDS?" icon="clipboard" :description="`${doneCount} of ${parts.length} parts filled in`">
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

        <p class="mt-3 text-xs text-text-muted">Parts that do not apply to you can stay empty; write N/A where the form asks for it.</p>
    </Card>
</template>
