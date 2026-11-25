<script setup>
import { computed } from 'vue';

/**
 * One release-note entry as a bullet: a dot in its kind's colour, the
 * topic before the first colon in bold ("Push notifications: switch them
 * on…"), and the words matching `query` highlighted.
 */
const props = defineProps({
    description: { type: String, required: true },
    label: { type: String, required: true },
    dot: { type: String, required: true },
    query: { type: String, default: '' },
});

const LEAD_MAX = 70;

const parts = computed(() => {
    const at = props.description.indexOf(': ');
    const lead = at > 2 && at <= LEAD_MAX ? props.description.slice(0, at) : null;

    return lead && !lead.includes('. ') ? { lead, body: props.description.slice(at + 2) } : { lead: null, body: props.description };
});

/** The text split into runs, with the runs matching the query marked. */
const highlight = (text) => {
    const needle = props.query.trim().toLowerCase();
    if (!needle) return [{ text, match: false }];

    const runs = [];
    const lower = text.toLowerCase();
    let from = 0;

    while (from < text.length) {
        const at = lower.indexOf(needle, from);
        if (at === -1) {
            runs.push({ text: text.slice(from), match: false });
            break;
        }
        if (at > from) runs.push({ text: text.slice(from, at), match: false });
        runs.push({ text: text.slice(at, at + needle.length), match: true });
        from = at + needle.length;
    }

    return runs;
};
</script>

<template>
    <li class="relative break-inside-avoid pb-3 pl-5 text-sm leading-relaxed text-text">
        <span class="absolute top-[0.5rem] left-0.5 h-2 w-2 rounded-full ring-2 ring-surface" :class="dot" aria-hidden="true" />
        <span class="sr-only">{{ label }}: </span>
        <template v-if="parts.lead">
            <strong class="font-semibold text-text"><template v-for="(run, index) in highlight(parts.lead)" :key="index"><mark v-if="run.match" class="rounded-sm bg-warning-bg px-0.5 text-warning-fg">{{ run.text }}</mark><template v-else>{{ run.text }}</template></template></strong>:
        </template>
        <span :class="parts.lead ? 'text-text-muted' : ''"><template v-for="(run, index) in highlight(parts.body)" :key="index"><mark v-if="run.match" class="rounded-sm bg-warning-bg px-0.5 text-warning-fg">{{ run.text }}</mark><template v-else>{{ run.text }}</template></template></span>
    </li>
</template>
