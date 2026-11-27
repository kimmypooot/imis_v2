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

/** Items 34–40, answered Yes or No. */
const QUESTIONS = ['q34a', 'q34b', 'q35a', 'q35b', 'q36', 'q37', 'q38a', 'q38b', 'q39', 'q40a', 'q40b', 'q40c'];

/**
 * What to check before signing: every part filled in, the photo, items
 * 34–40 all answered, the government-issued ID and the form's three
 * references. `part` is where to go to fix it. Guides, doesn't gate.
 *
 * @returns {Array<{ key: string, label: string, done: boolean, detail: string|null, part: string|null }>}
 */
export const readiness = (schema, pds, hasPhoto) => {
    const parts = partStates(schema, pds);
    const unfinished = parts.filter((part) => part.state === 'empty' || part.state === 'started');
    const questions = pds.sections.questions ?? {};
    const unanswered = QUESTIONS.filter((key) => questions[key] !== true && questions[key] !== false).length;
    const id = pds.sections.government_id ?? {};
    const references = (pds.sections.references ?? []).length;

    return [
        {
            key: 'parts',
            label: 'Every part filled in',
            done: unfinished.length === 0,
            detail: unfinished.length ? `Still to do: ${unfinished.map((part) => part.label).join(', ')}` : null,
            part: unfinished[0]?.key ?? null,
        },
        { key: 'photo', label: 'Passport-size photo', done: hasPhoto, detail: hasPhoto ? null : 'Upload it in the Photo card.', part: null },
        {
            key: 'questions',
            label: 'Questions 34–40 answered',
            done: unanswered === 0,
            detail: unanswered ? `${unanswered} not answered yet` : null,
            part: 'questions',
        },
        {
            key: 'government_id',
            label: 'Government-issued ID',
            done: Boolean(id.government_id_type && id.government_id_number),
            detail: id.government_id_type && id.government_id_number ? null : 'The ID, its number and date/place of issuance',
            part: 'references',
        },
        {
            key: 'references',
            label: 'Three references',
            done: references >= 3,
            detail: references >= 3 ? null : `${references} of 3 so far`,
            part: 'references',
        },
    ];
};
