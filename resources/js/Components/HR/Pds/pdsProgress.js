/**
 * How far along each part of the PDS is (a part = one step of the editor).
 * A part is Done when its required sections have entries, Started when
 * some do, and Not started otherwise. Sections that can legitimately stay
 * empty (children, voluntary work, eligibility, trainings, other
 * information) are optional: a part with only those is "Optional" until
 * something is added, and doesn't count toward the total. It guides; it
 * doesn't gate signing.
 */

const has = (value) => value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && value.length === 0);

/** Whether a section has anything in it. Items 34–40 are Yes/No, so a No (false) counts there. */
const filled = (schema, pds, key) => {
    const value = pds.sections[key];
    if (Array.isArray(value)) return value.length > 0;
    if (!value || typeof value !== 'object') return has(value);
    const answered = schema.sections[key]?.kind === 'questions';

    return Object.values(value).some((entry) => has(entry) && (entry !== false || answered));
};

/**
 * @returns {Array<{ key: string, label: string, state: 'done'|'started'|'empty'|'optional' }>}
 */
export const partStates = (schema, pds) =>
    schema.tabs.map((tab) => {
        const required = tab.sections.filter((key) => !schema.sections[key]?.optional);
        const anyFilled = tab.sections.some((key) => filled(schema, pds, key));

        let state;
        if (required.length === 0) {
            state = anyFilled ? 'done' : 'optional';
        } else if (required.every((key) => filled(schema, pds, key))) {
            state = 'done';
        } else {
            state = anyFilled ? 'started' : 'empty';
        }

        return { key: tab.key, label: tab.label, state };
    });

/** Done, counted and the percentage; optional parts left empty count neither way. */
export const progressOf = (parts) => {
    const counted = parts.filter((part) => part.state !== 'optional');
    const done = counted.filter((part) => part.state === 'done').length;

    return { done, total: counted.length, percent: counted.length ? Math.round((done / counted.length) * 100) : 100 };
};

/** How each state looks: an icon in a tinted circle plus a word (never colour alone). */
export const STATE_LOOKS = {
    done: { icon: 'check', chip: 'bg-success-bg text-success-fg', label: 'Done' },
    started: { icon: 'pencil', chip: 'bg-warning-bg text-warning-fg', label: 'Started' },
    empty: { icon: 'plus', chip: 'bg-surface-muted text-text-muted', label: 'Not started' },
    optional: { icon: 'plus', chip: 'bg-surface-muted text-text-muted', label: 'Optional' },
};
