<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { useUnsavedChanges } from '@cscro8/ui/Composables/useUnsavedChanges.js';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import ProgressBar from '@cscro8/ui/Components/UI/ProgressBar.vue';
import PdsSectionForm from './PdsSectionForm.vue';
import PdsRowsForm from './PdsRowsForm.vue';
import PdsManagedList from './PdsManagedList.vue';
import { partStates, progressOf, STATE_LOOKS } from './pdsProgress';
import { formatCalendarDate } from '../../../Utils/datetime';

/**
 * The PDS editor (CS Form No. 212, Revised 2026), driven entirely by the
 * server's PdsSchema. The parts of the form are steps in a list beside the
 * editor (a menu on phones), each showing how far along it is; one part is
 * edited at a time, with one Save for everything in it and "Save and
 * continue" to the next. Items 1–3, 5 and 15 come from the account and
 * HR-1 record and are shown read-only. Edits in other parts are kept while
 * moving between them; leaving the page with unsaved changes warns first.
 */
const props = defineProps({
    schema: { type: Object, required: true /** { tabs, sections } */ },
    pds: { type: Object, required: true /** { identity, sections } */ },
    url: { type: String, default: null /** e.g. /my/pds; not needed when readonly */ },
    readonly: { type: Boolean, default: false },
    // Section keys to flag as changed (HR's review of a submission).
    highlight: { type: Array, default: () => [] },
    // Show "Manage in …" links on sections kept elsewhere (own PDS only).
    manageLinks: { type: Boolean, default: false },
});

const tab = ref(props.schema.tabs[0].key);
const panel = ref(null);
const dirty = reactive({});
const forms = {};
const saving = ref(false);

const markDirty = (section, isDirty) => (dirty[section] = isDirty);
const anyDirty = computed(() => Object.values(dirty).some(Boolean));

const parts = computed(() =>
    partStates(props.schema, props.pds).map((part, index) => {
        const sections = props.schema.tabs[index].sections;

        return {
            ...part,
            number: index + 1,
            unsaved: sections.some((section) => dirty[section]),
            changed: sections.some((section) => props.highlight.includes(section)),
        };
    }),
);
const progress = computed(() => progressOf(parts.value));
const current = computed(() => parts.value.find((part) => part.key === tab.value));
const currentIndex = computed(() => current.value.number - 1);
const previous = computed(() => parts.value[currentIndex.value - 1] ?? null);
const next = computed(() => parts.value[currentIndex.value + 1] ?? null);

/**
 * Opens a part and brings its heading into view when it isn't. Checked
 * after the switch renders: a shorter part shortens the page, and the
 * browser may have pulled the scroll position up past the editor.
 */
const openPart = async (key) => {
    tab.value = key;
    await nextTick();
    const top = panel.value?.getBoundingClientRect().top;
    if (top !== undefined && (top < 0 || top > window.innerHeight * 0.4)) {
        panel.value.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    }
};

// Lets a page jump to a part of the form.
defineExpose({ openTab: openPart });

/** Saves every changed section of the current part, one after another; true when all saved. */
const savePart = async () => {
    if (saving.value) return false;
    saving.value = true;
    try {
        for (const section of props.schema.tabs[currentIndex.value].sections) {
            const form = forms[section];
            if (form?.isDirty() && !(await form.save())) return false;
        }

        return true;
    } finally {
        saving.value = false;
    }
};

const saveAndContinue = async () => {
    if ((await savePart()) && next.value) openPart(next.value.key);
};

const identity = computed(() => [
    ['1. Surname', props.pds.identity.surname],
    ['2. First name', props.pds.identity.first_name],
    ['Middle name', props.pds.identity.middle_name],
    ['Name extension', props.pds.identity.name_extension],
    ['3. Date of birth', props.pds.identity.date_of_birth ? formatCalendarDate(props.pds.identity.date_of_birth) : null],
    ['5. Sex at birth', props.pds.identity.sex_at_birth === 'female' ? 'Female' : props.pds.identity.sex_at_birth === 'male' ? 'Male' : null],
    ['15. Agency employee no.', props.pds.identity.agency_employee_no],
]);

// Don't lose unsaved work: warn on leaving the page or navigating away.
useUnsavedChanges(anyDirty, { message: 'You have unsaved PDS changes. If you leave now, they will be lost.' });
</script>

<template>
    <div class="space-y-6">
        <Card
            title="Name and identity"
            icon="user"
            :description="highlight.includes('identity') ? 'Changed since the last verified PDS.' : 'From the IMIS account and employee record. Ask HR to correct these.'"
        >
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="[label, value] in identity" :key="label">
                    <dt class="text-text-muted">{{ label }}</dt>
                    <dd class="mt-0.5 font-medium text-text">{{ value || '—' }}</dd>
                </div>
            </dl>
        </Card>

        <div class="grid gap-6 lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-start">
            <!-- The parts of the form, as steps -->
            <nav aria-label="Parts of the Personal Data Sheet" class="lg:sticky lg:top-20">
                <Card>
                    <p class="text-sm font-semibold text-text">Parts of the form</p>
                    <p class="mt-0.5 text-xs text-text-muted">{{ progress.done }} of {{ progress.total }} parts filled in</p>
                    <ProgressBar class="mt-2" :value="progress.percent" tone="success" size="sm" label="PDS completion" />

                    <!-- Phones: a menu -->
                    <div class="mt-4 lg:hidden">
                        <label for="pds-part" class="block text-sm font-medium text-text">Part of the form</label>
                        <select id="pds-part" :value="tab" class="form-control mt-1 block w-full px-3 py-2" @change="openPart($event.target.value)">
                            <option v-for="part in parts" :key="part.key" :value="part.key">
                                {{ part.label }} — {{ STATE_LOOKS[part.state].label }}{{ part.unsaved ? ' (unsaved)' : '' }}{{ part.changed ? ' (changed)' : '' }}
                            </option>
                        </select>
                    </div>

                    <!-- Larger screens: the list -->
                    <ol class="mt-4 hidden space-y-1 lg:block">
                        <li v-for="part in parts" :key="part.key">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-md border px-2 py-1.5 text-left transition-colors motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                :class="part.key === tab ? 'border-info-border bg-info-bg' : 'border-transparent hover:bg-surface-muted'"
                                :aria-current="part.key === tab ? 'step' : undefined"
                                @click="openPart(part.key)"
                            >
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full" :class="STATE_LOOKS[part.state].chip" aria-hidden="true">
                                    <Icon :name="STATE_LOOKS[part.state].icon" size="xs" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm" :class="part.key === tab ? 'font-semibold text-text' : 'text-text'">{{ part.label }}</span>
                                    <span class="block text-xs text-text-muted">
                                        {{ STATE_LOOKS[part.state].label }}<template v-if="part.unsaved"> · <span class="font-medium text-warning-fg">Unsaved</span></template>
                                    </span>
                                </span>
                                <Badge v-if="part.changed" tone="info" pill>Changed</Badge>
                            </button>
                        </li>
                    </ol>
                    <p class="mt-3 text-xs text-text-muted">Optional parts (such as children or voluntary work) can stay empty if they don't apply.</p>
                </Card>
            </nav>

            <!-- The part being edited -->
            <section ref="panel" :aria-labelledby="`pds-part-${tab}`" class="scroll-mt-20">
                <Card>
                    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-medium text-text-muted">Part {{ current.number }} of {{ parts.length }}</p>
                            <h2 :id="`pds-part-${tab}`" class="text-lg font-semibold text-text">{{ current.label }}</h2>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="STATE_LOOKS[current.state].chip">
                            <Icon :name="STATE_LOOKS[current.state].icon" size="xs" />{{ STATE_LOOKS[current.state].label }}
                        </span>
                    </header>

                    <!-- Every part stays mounted, so edits survive moving between parts -->
                    <div v-for="item in schema.tabs" v-show="item.key === tab" :key="item.key" class="space-y-4">
                        <template v-for="sectionKey in item.sections" :key="sectionKey">
                            <PdsManagedList
                                v-if="schema.sections[sectionKey].managed_by"
                                :section="schema.sections[sectionKey]"
                                :rows="pds.sections[sectionKey]"
                                :manage-link="manageLinks"
                            />
                            <PdsRowsForm
                                v-else-if="schema.sections[sectionKey].kind === 'list'"
                                :ref="(form) => (forms[sectionKey] = form)"
                                :section="schema.sections[sectionKey]"
                                :rows="pds.sections[sectionKey]"
                                :url="url"
                                :readonly="readonly"
                                @dirty="markDirty"
                                @submit="savePart"
                            />
                            <PdsSectionForm
                                v-else
                                :ref="(form) => (forms[sectionKey] = form)"
                                :section="schema.sections[sectionKey]"
                                :values="pds.sections[sectionKey]"
                                :url="url"
                                :readonly="readonly"
                                @dirty="markDirty"
                                @submit="savePart"
                            />
                        </template>
                    </div>

                    <template #footer>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <Button v-if="previous" variant="ghost" icon="arrowLeft" @click="openPart(previous.key)">{{ previous.label }}</Button>
                            <span v-else />

                            <div class="flex flex-wrap items-center gap-3">
                                <span v-if="current.unsaved" class="text-xs font-medium text-warning-fg">Unsaved changes</span>
                                <template v-if="!readonly && current.unsaved">
                                    <Button variant="secondary" :loading="saving" @click="savePart">Save</Button>
                                    <Button v-if="next" :loading="saving" @click="saveAndContinue">Save and continue<Icon name="arrowRight" size="sm" /></Button>
                                </template>
                                <Button v-else-if="next" variant="secondary" @click="openPart(next.key)">{{ next.label }}<Icon name="arrowRight" size="sm" /></Button>
                            </div>
                        </div>
                    </template>
                </Card>
            </section>
        </div>
    </div>
</template>
